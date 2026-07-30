<?php

declare(strict_types=1);

use App\Domain\Backup\BackupService;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Retention, which is the half of a backup policy people forget.
 *
 * A backup kept indefinitely is personal data kept indefinitely. The interesting behaviour here is the
 * interaction between the two rules — expiry and a minimum count — because either on its own gets it
 * wrong in a different direction.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create([
        'backup_retention_days' => 30,
        'backup_keep_count' => 2,
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

it('keeps the minimum number even when everything has expired', function (): void {
    // The case a pure expiry rule gets wrong: a client whose site has been quiet for two months would
    // otherwise be left with no backup at all.
    $artifacts = collect(range(1, 4))->map(fn (int $age) => ($this->artifact)([
        'taken_at' => now()->subDays(60 + $age),
        'expires_at' => now()->subDays(30),
    ]));

    $this->artisan('manager:backups:prune')->assertSuccessful();

    $surviving = BackupArtifact::query()->stored()->get();

    expect($surviving)->toHaveCount(2)
        // And the two that survive are the newest two, not an arbitrary pair.
        ->and($surviving->pluck('id')->sort()->values()->all())
        ->toBe($artifacts->take(2)->pluck('id')->sort()->values()->all());
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
    $other = Organisation::factory()->create(['backup_retention_days' => 30, 'backup_keep_count' => 0]);
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
