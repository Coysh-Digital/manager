<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\Site;
use App\Models\User;
use App\Support\ResumableInput;

/*
 * Deleting several backups from the backups screen.
 *
 * A separate file from BulkBackupTest, whose subject is queuing, because the behaviour worth
 * defending here is the opposite one: that nothing is destroyed except what was ticked, and that the
 * screen says which of two different things it did to each row.
 *
 * The two acts are the same distinction the single-row button makes. A stored artifact is deleted and
 * leaves a tombstone with its key destroyed; a declaration that stored nothing is discarded outright,
 * because a tombstone there would record the absence of something already absent. A selection is
 * allowed to hold both, so "four deleted" would be a false summary of three deletions and a removal.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    // An administrator, to prove the guard here is the stronger of the two bulk routes'. Asking for a
    // backup and destroying one were never the same permission.
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->admin)->for($this->organisation)->admin()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);

    RecoveryKey::factory()->for($this->organisation)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->stored = fn (): BackupArtifact => BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->neverStored = fn (): BackupArtifact => BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_FAILED,
        'failure_reason' => 'The artifact was declared but never uploaded',
    ]);
});

it('deletes several stored artifacts and destroys their keys', function (): void {
    $one = ($this->stored)();
    $two = ($this->stored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->from('/backups')
        ->delete('/backups', ['artifacts' => [$one->external_id, $two->external_id]])
        ->assertRedirect('/backups')
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 backups were deleted'));

    foreach ([$one, $two] as $artifact) {
        $artifact->refresh();

        // The row survives so the audit trail still shows it existed; the key does not, so whatever
        // is left in storage after a half-failed delete is unreadable.
        expect($artifact->state)->toBe(BackupArtifact::STATE_DELETED)
            ->and($artifact->wrapped_key)->toBeNull()
            ->and($artifact->storage_key)->toBeNull();
    }
});

it('discards rows for backups that never stored anything', function (): void {
    $one = ($this->neverStored)();
    $two = ($this->neverStored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$one->external_id, $two->external_id]])
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '2 rows were removed'));

    expect(BackupArtifact::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'backup.discarded')->count())->toBe(2);
});

it('handles a mixed selection and reports both counts', function (): void {
    /*
     | The test the two-count summary exists for.
     |
     | Adding these together would be the easy thing to write and a false statement about the row
     | that had nothing to delete. The screen distinguishes the two acts in the words on its buttons -
     | "Delete" and "Remove" - and the summary after a bulk action should not quietly stop making the
     | distinction that the buttons make.
     */
    $stored = ($this->stored)();
    $never = ($this->neverStored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$stored->external_id, $never->external_id]])
        ->assertRedirect()
        ->assertSessionHas('status', function (string $status): bool {
            return str_contains($status, '1 backup was deleted')
                && str_contains($status, '1 row was removed')
                && ! str_contains($status, '2 backups');
        });

    expect($stored->fresh()->state)->toBe(BackupArtifact::STATE_DELETED)
        ->and(BackupArtifact::query()->find($never->id))->toBeNull();
});

it('records that a bulk deletion was a bulk deletion', function (): void {
    /*
     | The tombstone is the record, and "was this one chosen, or swept up with thirty-nine others" is
     | a question somebody reading an audit log a year from now is entitled to an answer to. It costs
     | one string to keep and is unrecoverable afterwards.
     |
     | Written as an inequality rather than pinned to the exact sentence, so rewording the constant
     | stays free while collapsing it into the single-row button's reason does not.
     */
    $artifact = ($this->stored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$artifact->external_id]])
        ->assertRedirect();

    expect($artifact->fresh()->deleted_reason)
        ->not->toBe('Deleted by hand')
        ->toContain('several');
});

it('writes one audit row per artifact and no summary on top', function (): void {
    // A summary row would be a second account of the same events, free to disagree with the first.
    // storeMany() sets the precedent by writing none.
    $one = ($this->stored)();
    $two = ($this->stored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$one->external_id, $two->external_id]])
        ->assertRedirect();

    expect(AuditEvent::query()->where('action', 'backup.deleted')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'like', 'backup.%deleted-many%')->count())->toBe(0);
});

it('reports what it skipped rather than counting it as done', function (): void {
    // A hundred rows is enough that the screen can go stale between being rendered and the button
    // being pressed, which is an ordinary thing to happen and should be said out loud.
    $live = ($this->stored)();
    $gone = BackupArtifact::factory()->for($this->site)->deleted()->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$live->external_id, $gone->external_id]])
        ->assertRedirect()
        ->assertSessionHas('status')
        ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, '1 backup was skipped'));

    expect($live->fresh()->state)->toBe(BackupArtifact::STATE_DELETED);
});

it('errors rather than flashing success when nothing could be deleted', function (): void {
    $gone = BackupArtifact::factory()->for($this->site)->deleted()->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$gone->external_id]])
        ->assertSessionHasErrors('artifacts')
        ->assertSessionMissing('status');
});

it('ignores an artifact belonging to another organisation without abandoning the rest', function (): void {
    // Scoped in the query rather than by route binding, so one stale identifier reports itself as
    // missing instead of 404ing thirty-nine good ones.
    $other = Organisation::factory()->create();
    $theirSite = Site::factory()->for($other)->connected()->create();
    $theirs = BackupArtifact::factory()->for($theirSite)->create(['organisation_id' => $other->id]);

    $mine = ($this->stored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$mine->external_id, $theirs->external_id]])
        ->assertRedirect()
        ->assertSessionHas('warning', fn (string $warning): bool => str_contains($warning, 'no longer in your list'));

    expect($mine->fresh()->state)->toBe(BackupArtifact::STATE_DELETED)
        ->and($theirs->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('counts a repeated identifier once', function (): void {
    // array_unique is doing real work: without it the second copy finds nothing on the second pass
    // and is reported as a missing artifact, so a successful deletion comes with a warning about
    // itself.
    $artifact = ($this->stored)();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$artifact->external_id, $artifact->external_id]])
        ->assertRedirect()
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '1 backup was deleted'))
        ->assertSessionMissing('warning');
});

it('refuses an administrator who is not an owner', function (): void {
    // Stronger than storeMany()'s guard, deliberately. Batching an act does not lower the privilege
    // it needs, and this administrator may ask for backups all day.
    $artifact = ($this->stored)();

    $this->actingAs($this->admin)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => [$artifact->external_id]])
        ->assertForbidden();

    expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('requires recent authentication', function (): void {
    // The half of "anything backup related" that kept its gate. Asking for a backup no longer needs
    // a password; destroying up to a hundred encryption keys irrecoverably still does.
    $artifact = ($this->stored)();

    $this->actingAs($this->owner)
        ->delete('/backups', ['artifacts' => [$artifact->external_id]])
        ->assertRedirect(route('password.confirm'));

    expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('hands the selection back across the recent-authentication gate', function (): void {
    /*
     | The selection is the input here, and on a hundred-row table it is the most tedious thing on
     | the screen to reconstruct - which is the complaint that produced ResumableInput.
     |
     | This also covers the trap that made the field name a decision rather than a detail:
     | FORBIDDEN_KEY matches /key/, so calling the field anything naming the encryption keys these
     | destroy would see it stripped by layer two, and the selection lost with nothing to see.
     */
    $one = ($this->stored)();
    $two = ($this->stored)();

    $this->actingAs($this->owner)
        ->delete('/backups', ['artifacts' => [$one->external_id, $two->external_id]])
        ->assertRedirect(route('password.confirm'));

    $pending = ResumableInput::pending();

    expect($pending)->not->toBeNull()
        ->and($pending['route'])->toBe('backups.destroy-many')
        ->and($pending['url'])->toBe(route('backups.index'))
        ->and($pending['input']['artifacts'])->toBe([$one->external_id, $two->external_id]);
});

it('needs at least one artifact', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => []])
        ->assertSessionHasErrors('artifacts');
});

it('refuses an implausible number of identifiers', function (): void {
    // A hundred, because index() renders limit(100) and the screen cannot offer more. The bound is
    // what the screen can produce rather than a number picked to sound safe.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/backups', ['artifacts' => array_fill(0, 101, 'not-a-real-identifier')])
        ->assertSessionHasErrors('artifacts');
});

it('offers the checkboxes to an owner and nobody else', function (): void {
    $artifact = ($this->stored)();

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Delete selected')
        ->assertSee('bulk-delete-artifacts')
        ->assertSee($artifact->external_id);

    // An administrator may download and request; the column is not drawn for them at all.
    $this->actingAs($this->admin)->get('/backups')
        ->assertOk()
        ->assertDontSee('Delete selected')
        ->assertDontSee('bulk-delete-artifacts');
});

it('draws no checkbox for a row that has nothing to delete', function (): void {
    /*
     | This deliberately departs from the fleet screen, which draws a checkbox on every row and has a
     | comment arguing that disabling some lets a fleet look fully covered while half of it is
     | quietly unselectable. Pinned here because that argument is written down two files away, and
     | somebody restoring "draw them all" for consistency would make this screen contradict itself:
     | the row's own action cell is already empty, and a checkbox whose only possible outcome is a
     | line in the skipped sentence is worse than no checkbox.
     */
    $deletable = ($this->stored)();

    BackupArtifact::factory()->for($this->site)->deleted()->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $response = $this->actingAs($this->owner)->get('/backups')->assertOk();

    expect(substr_count($response->getContent(), 'name="artifacts[]"'))->toBe(1)
        ->and($response->getContent())->toContain($deletable->external_id);
});

it('keeps the single-row buttons alongside the bulk one', function (): void {
    // The regression that collapsing the two paths into one would cause. Deleting the one bad backup
    // in a list is the common case, and it should stay one click rather than tick-then-press.
    $stored = ($this->stored)();
    $never = ($this->neverStored)();

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Delete selected')
        ->assertSee(route('backups.destroy', $stored))
        ->assertSee(route('backups.destroy', $never));
});
