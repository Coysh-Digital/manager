<?php

declare(strict_types=1);

use App\Domain\Backup\BackupService;
use App\Domain\Backup\RetentionPolicy;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Retention, which is the half of a backup policy people forget.
 *
 * A backup kept indefinitely is personal data kept indefinitely. The interesting behaviour is the
 * interaction between expiry and the period policy, because either on its own gets it wrong in a
 * different direction — and because the rule this replaced, "always keep the most recent N", gets it
 * wrong in the worst direction of all. See {@see RetentionPolicy}.
 *
 * These tests set the weekly and monthly windows to zero unless they are exercising them, so that
 * "expired" means expired. Leaving them at their defaults would have every artifact in this file
 * protected as the representative of its month, which is correct behaviour and useless for testing
 * deletion.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create([
        'backup_retention_days' => 30,
        'backup_retention_weeks' => 0,
        'backup_retention_months' => 0,
    ]);

    $this->site = Site::factory()->for($this->organisation)->connected()->create();

    $this->artifact = function (array $attributes = []) {
        $artifact = BackupArtifact::factory()->for($this->site)->create(array_merge([
            'organisation_id' => $this->organisation->id,
        ], $attributes));

        // A real object behind each row, so a deletion that claims to have removed bytes has to have
        // removed some.
        Storage::disk('backups')->put((string) $artifact->storage_key, 'ciphertext');

        return $artifact;
    };
});

it('leaves artifacts inside the retention window alone', function (): void {
    $artifact = ($this->artifact)(['expires_at' => now()->addDays(10)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
    Storage::disk('backups')->assertExists((string) $artifact->storage_key);
});

it('deletes an expired artifact and the key that opened it', function (): void {
    // Three, so deleting one still leaves the minimum of two.
    ($this->artifact)(['taken_at' => now()->subDays(1)]);
    ($this->artifact)(['taken_at' => now()->subDays(2)]);
    $oldest = ($this->artifact)(['taken_at' => now()->subDays(90), 'expires_at' => now()->subDays(60)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    $oldest->refresh();

    expect($oldest->state)->toBe(BackupArtifact::STATE_DELETED)
        ->and($oldest->wrapped_key)->toBeNull()
        // The row survives, because the audit trail should still show the artifact existed.
        ->and($oldest->deleted_at)->not->toBeNull();

    expect(Storage::disk('backups')->allFiles())->toHaveCount(2);
});

it('never leaves an organisation with nothing', function (): void {
    // The case a pure expiry rule gets wrong. A client whose site has been quiet for two months, with
    // every window set to zero, would otherwise be left with no backup at all — and somebody whose
    // backups stopped months ago is exactly the person who is going to need the one they still have.
    $artifacts = collect(range(1, 4))->map(fn (int $age) => ($this->artifact)([
        'taken_at' => now()->subDays(60 + $age),
        'expires_at' => now()->subDays(30),
    ]));

    $this->artisan('manager:backups:prune')->assertSuccessful();

    $surviving = BackupArtifact::query()->stored()->get();

    expect($surviving)->toHaveCount(1)
        // The newest, not an arbitrary one.
        ->and($surviving->first()->id)->toBe($artifacts->first()->id);
});

it('does not let a run of bad backups push out the last good one', function (): void {
    /*
     | The failure that made count-based retention worth removing.
     |
     | A site starts producing bad backups on a schedule. Under "always keep the most recent N", seven
     | bad nights push out the last known-good copy: the count never drops below N, nothing looks
     | wrong, and the only usable backup is gone. Under a period policy the old one is the
     | representative of its month and the recent run cannot displace it, because they are not
     | competing for the same slot.
     */
    $this->organisation->forceFill([
        'backup_retention_days' => 3,
        'backup_retention_weeks' => 0,
        'backup_retention_months' => 6,
    ])->save();

    $good = ($this->artifact)([
        'taken_at' => now()->subMonths(2),
        'expires_at' => now()->subDays(1),
    ]);

    // A fortnight of nightly backups, all expired, all newer than the good one.
    collect(range(1, 14))->each(fn (int $age) => ($this->artifact)([
        'taken_at' => now()->subDays(3 + $age),
        'expires_at' => now()->subDays(1),
    ]));

    $this->artisan('manager:backups:prune')->assertSuccessful();

    expect($good->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('thins older backups to one a week and then one a month', function (): void {
    $this->organisation->forceFill([
        'backup_retention_days' => 7,
        'backup_retention_weeks' => 4,
        'backup_retention_months' => 6,
    ])->save();

    // Everything expired, so only the policy decides what survives.
    $expired = ['expires_at' => now()->subDay()];

    // Inside the daily window: all of these stay. Retention answers "how far back", not "how many",
    // so a day with several backups keeps all of them while it is still inside the window.
    $daily = collect(range(1, 6))->map(fn (int $d) => ($this->artifact)([...$expired, 'taken_at' => now()->subDays($d)]));

    // Two in the same week, well outside the daily window. One survives, and it is the later one.
    $earlierInWeek = ($this->artifact)([...$expired, 'taken_at' => now()->subDays(20)]);
    $laterInWeek = ($this->artifact)([...$expired, 'taken_at' => now()->subDays(18)]);

    // Two in the same month, outside the weekly window.
    $earlierInMonth = ($this->artifact)([...$expired, 'taken_at' => now()->subDays(100)]);
    $laterInMonth = ($this->artifact)([...$expired, 'taken_at' => now()->subDays(98)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    foreach ($daily as $artifact) {
        expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
    }

    // The last of each period, not the first. Keeping the first would mean a Monday backup outliving
    // the Sunday one taken six days later, which is the wrong way round.
    expect($laterInWeek->fresh()->state)->toBe(BackupArtifact::STATE_STORED)
        ->and($earlierInWeek->fresh()->state)->toBe(BackupArtifact::STATE_DELETED)
        ->and($laterInMonth->fresh()->state)->toBe(BackupArtifact::STATE_STORED)
        ->and($earlierInMonth->fresh()->state)->toBe(BackupArtifact::STATE_DELETED);
});

it('deletes what falls outside every window', function (): void {
    $this->organisation->forceFill([
        'backup_retention_days' => 7,
        'backup_retention_weeks' => 2,
        'backup_retention_months' => 2,
    ])->save();

    ($this->artifact)(['taken_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
    $ancient = ($this->artifact)(['taken_at' => now()->subYears(2), 'expires_at' => now()->subYear()]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    // Past every window and not the only thing left, so it goes. A backup kept indefinitely is
    // personal data kept indefinitely.
    expect($ancient->fresh()->state)->toBe(BackupArtifact::STATE_DELETED);
});

it('writes off a declaration whose bytes never arrived', function (): void {
    $stale = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'created_at' => now()->subHours(4),
    ]);

    $recent = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'created_at' => now()->subMinutes(2),
    ]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    // A pending artifact is not a backup, so leaving them accumulating would make the interface claim
    // protection that does not exist. An upload still in progress is left alone.
    expect($stale->fresh()->state)->toBe(BackupArtifact::STATE_FAILED)
        ->and($recent->fresh()->state)->toBe(BackupArtifact::STATE_PENDING);
});

it('records every deletion in the audit log', function (): void {
    ($this->artifact)(['taken_at' => now()->subDays(1)]);
    ($this->artifact)(['taken_at' => now()->subDays(2)]);
    $oldest = ($this->artifact)(['taken_at' => now()->subDays(90), 'expires_at' => now()->subDays(60)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    $event = AuditEvent::query()->where('action', 'backup.deleted')->sole();

    expect($event->target_id)->toBe($oldest->external_id)
        ->and($event->actor_label)->toBe('Retention')
        ->and($event->after['bytes_removed'])->toBeTrue();
});

it('changes nothing when asked not to', function (): void {
    $artifact = ($this->artifact)(['taken_at' => now()->subDays(90), 'expires_at' => now()->subDays(60)]);

    $this->artisan('manager:backups:prune --dry-run')->assertSuccessful();

    expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
    Storage::disk('backups')->assertExists((string) $artifact->storage_key);
});

it('never deletes an artifact belonging to another organisation', function (): void {
    $other = Organisation::factory()->create([
        'backup_retention_days' => 30,
        'backup_retention_weeks' => 0,
        'backup_retention_months' => 0,
    ]);
    $theirSite = Site::factory()->for($other)->connected()->create();

    $theirs = BackupArtifact::factory()->for($theirSite)->create([
        'organisation_id' => $other->id,
        'taken_at' => now()->subDays(90),
        'expires_at' => now()->addDays(10),
    ]);

    ($this->artifact)(['taken_at' => now()->subDays(90), 'expires_at' => now()->subDays(60)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    // Retention is per organisation. One organisation's policy must never reach another's artifacts.
    expect($theirs->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('honours a retention of zero days as keep indefinitely', function (): void {
    $this->organisation->forceFill(['backup_retention_days' => 0])->save();

    // expires_at is computed at storage time from the policy then in force, so an artifact stored under
    // an indefinite policy simply has no expiry rather than one in the past.
    expect(app(BackupService::class)->expiryFor($this->organisation->id))->toBeNull();
});
