<?php

declare(strict_types=1);

use App\Domain\Backup\BackupService;
use App\Domain\Backup\RetentionPolicy;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Retention, which is the half of a backup policy people forget.
 *
 * A backup kept indefinitely is personal data kept indefinitely. The interesting behaviour is the
 * interaction between expiry and the period policy, because either on its own gets it wrong in a
 * different direction - and because the rule this replaced, "always keep the most recent N", gets it
 * wrong in the worst direction of all. See {@see RetentionPolicy}.
 *
 * These tests set the weekly and monthly windows to zero unless they are exercising them, so that
 * "expired" means expired. Leaving them at their defaults would have every artifact in this file
 * protected as the representative of its month, which is correct behaviour and useless for testing
 * deletion.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create();

    // Retention is the site's, not the organisation's: a busy shop and a brochure site do not
    // warrant the same history, and one policy across a fleet means picking the more expensive one.
    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'backup_retention_days' => 30,
        'backup_retention_weeks' => 0,
        'backup_retention_months' => 0,
    ]);

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

afterEach(function (): void {
    Carbon::setTestNow();
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
    // every window set to zero, would otherwise be left with no backup at all - and somebody whose
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
    $this->site->forceFill([
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
    /*
     | The clock is frozen, and it has to be.
     |
     | Written without this, the test picked artifacts "18 and 20 days ago" and assumed they shared an
     | ISO week. Whether they do depends on what day of the week it is run, so it passed on a Thursday
     | and failed on the Friday - which is the worst kind of failing test, because the first instinct
     | is to distrust the policy rather than the test.
     |
     | Wednesday 2026-08-05 is the reference. Every offset below is measured from it.
     */
    Carbon::setTestNow(Carbon::parse('2026-08-05 12:00:00', 'UTC'));

    $this->site->forceFill([
        'backup_retention_days' => 7,
        'backup_retention_weeks' => 4,
        'backup_retention_months' => 6,
    ])->save();

    // Everything expired, so only the policy decides what survives.
    $expired = ['expires_at' => now()->subDay()];

    // Inside the daily window: all of these stay. Retention answers "how far back", not "how many",
    // so a day with several backups keeps all of them while it is still inside the window.
    $daily = collect(range(1, 6))->map(fn (int $d) => ($this->artifact)([...$expired, 'taken_at' => now()->subDays($d)]));

    // Two in the same ISO week, well outside the daily window. Named as dates rather than offsets so
    // the week they share is visible rather than arithmetic: both are in the week of 13 July 2026.
    $earlierInWeek = ($this->artifact)([...$expired, 'taken_at' => Carbon::parse('2026-07-14 02:00', 'UTC')]);
    $laterInWeek = ($this->artifact)([...$expired, 'taken_at' => Carbon::parse('2026-07-16 02:00', 'UTC')]);

    // Two in the same calendar month, outside the weekly window.
    $earlierInMonth = ($this->artifact)([...$expired, 'taken_at' => Carbon::parse('2026-04-08 02:00', 'UTC')]);
    $laterInMonth = ($this->artifact)([...$expired, 'taken_at' => Carbon::parse('2026-04-22 02:00', 'UTC')]);

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
    $this->site->forceFill([
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
    /*
     | Aged against the configured window rather than a literal.
     |
     | This said four hours, which was comfortably stale while the window was one hour and became
     | comfortably fresh when the window grew to six. The number that decides is
     | manager.backups.upload_window, so the test reads it - otherwise raising the window to suit a
     | twenty-gigabyte upload silently turns this assertion into one about nothing.
     */
    $window = (int) config('manager.backups.upload_window');

    $stale = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'created_at' => now()->subSeconds($window * 2),
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
    $other = Organisation::factory()->create();
    $theirSite = Site::factory()->for($other)->connected()->create([
        'backup_retention_days' => 30,
        'backup_retention_weeks' => 0,
        'backup_retention_months' => 0,
    ]);

    $theirs = BackupArtifact::factory()->for($theirSite)->create([
        'organisation_id' => $other->id,
        'taken_at' => now()->subDays(90),
        'expires_at' => now()->addDays(10),
    ]);

    ($this->artifact)(['taken_at' => now()->subDays(90), 'expires_at' => now()->subDays(60)]);

    $this->artisan('manager:backups:prune')->assertSuccessful();

    // Retention is per site. One site's policy must never reach another's artifacts.
    expect($theirs->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('honours a retention of zero days as keep indefinitely', function (): void {
    $this->site->forceFill(['backup_retention_days' => 0])->save();

    // expires_at is computed at storage time from the policy then in force, so an artifact stored under
    // an indefinite policy simply has no expiry rather than one in the past.
    expect(app(BackupService::class)->expiryFor($this->site->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------------------------------
| Setting the policy
|--------------------------------------------------------------------------------------------------
*/

it('lets an owner change retention, and records what it was', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($owner)->for($this->organisation)->owner()->create();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$this->site->external_id}/backups/retention", [
            'backup_retention_days' => 14,
            'backup_retention_weeks' => 8,
            'backup_retention_months' => 24,
        ])->assertRedirect();

    $this->site->refresh();

    expect($this->site->backup_retention_days)->toBe(14)
        ->and($this->site->backup_retention_weeks)->toBe(8)
        ->and($this->site->backup_retention_months)->toBe(24);

    // How far back a site can be recovered from is worth a line in the audit log, with the previous
    // values, because "we thought we kept a year" is a conversation that happens after.
    $event = AuditEvent::query()->where('action', 'backup.retention.changed')->firstOrFail();

    expect($event->before['backup_retention_days'])->toBe(30)
        ->and($event->after['backup_retention_days'])->toBe(14);
});

it('does not let an administrator change retention', function (): void {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($admin)->for($this->organisation)->admin()->create();

    // Owner-level, like people and notification destinations. Shortening retention is a different
    // kind of decision from managing a site.
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$this->site->external_id}/backups/retention", [
            'backup_retention_days' => 1,
            'backup_retention_weeks' => 0,
            'backup_retention_months' => 0,
        ])->assertForbidden();

    expect($this->site->fresh()->backup_retention_days)->toBe(30);
});

it('refuses a time zone that is not one', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($owner)->for($this->organisation)->owner()->create();

    // The schedule reads this to decide what "03:00" means, and it lives with the schedule now
    // rather than with retention. A junk value would silently move this site's backup to a
    // different hour, or to none.
    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$this->site->external_id}/backups/schedule", [
            'backup_schedule' => 'daily',
            'backup_schedule_hour' => 3,
            'backup_schedule_day' => 1,
            'timezone' => 'Middle/Earth',
        ])->assertSessionHasErrors('timezone');
});

it('does not re-date backups already taken', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($owner)->for($this->organisation)->owner()->create();

    $existing = ($this->artifact)(['expires_at' => now()->addDays(300)]);

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post("/sites/{$this->site->external_id}/backups/retention", [
            'backup_retention_days' => 1,
            'backup_retention_weeks' => 0,
            'backup_retention_months' => 0,
        ])->assertRedirect();

    // Somebody shortening retention is saying what should happen to future backups. Deciding it
    // also applies to the ones they already have is not something a form should assume.
    expect($existing->fresh()->expires_at?->toDateString())
        ->toBe(now()->addDays(300)->toDateString());
});
