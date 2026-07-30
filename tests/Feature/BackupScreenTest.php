<?php

declare(strict_types=1);

use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

it('says nothing has permission when nothing does', function (): void {
    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('No site has permission to back up')
        // The explanation, not just the empty state. Somebody looking at this screen for the first time
        // should learn why it is empty rather than assume it is broken.
        ->assertSee('never offered as a switch');
});

it('never claims end-to-end encryption', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    // The specification is explicit: do not claim end-to-end encryption unless the platform genuinely
    // cannot decrypt. It can, so the screen has to say so.
    // Asserted as short phrases rather than a sentence, because the sentence wraps in the template and
    // a whitespace-sensitive assertion would break on reformatting rather than on a real change.
    expect($html)->toContain('not end-to-end encryption')
        ->and($html)->toContain('can decrypt them')
        ->and($html)->toContain('password hashes');
});

it('lists artifacts with their checksum and retention date', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertSee($artifact->shortChecksum())
        ->assertSee('mysql');
});

it('offers a command rather than a download button', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    BackupArtifact::factory()->for($this->site)->create(['organisation_id' => $this->organisation->id]);

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    // A download that works until a database is big enough to matter is worse than a command that
    // always works, and a restore button would imply a recovery path that has not been designed.
    expect($html)->toContain('manager:backups:fetch')
        ->and($html)->toContain('Restoring is not automated')
        ->and($html)->not->toContain('>Download<')
        ->and($html)->not->toContain('>Restore<');
});

it('queues a backup when an administrator asks for one', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect();

    $job = RemoteJob::query()->sole();

    expect($job->type)->toBe(Jobs::BACKUP_CREATE)
        ->and($job->state)->toBe(Jobs::STATE_QUEUED)
        ->and($job->site_id)->toBe($this->site->id);
});

it('refuses to queue a backup for a site without permission', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertSessionHasErrors();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('needs recent authentication to request a backup', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    // Asking a production site for a copy of its database is not something a stale session should do.
    $this->actingAs($this->owner)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect(route('password.confirm'));

    expect(RemoteJob::query()->count())->toBe(0);
});

it('refuses a member who is not an administrator', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertForbidden();
});

it('cannot see or delete another organisation\'s artifacts', function (): void {
    $other = Organisation::factory()->create();
    $theirSite = Site::factory()->for($other)->connected()->create(['name' => 'Their Site']);
    $theirs = BackupArtifact::factory()->for($theirSite)->create(['organisation_id' => $other->id]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertDontSee('Their Site');

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/backups/{$theirs->external_id}", ['reason' => 'Not mine to delete'])
        ->assertNotFound();

    expect($theirs->fresh()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('destroys the key when an owner deletes an artifact', function (): void {
    $artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/backups/{$artifact->external_id}", ['reason' => 'Client asked us to'])
        ->assertRedirect();

    $artifact->refresh();

    // The row survives so the audit trail still shows it existed; the key does not, so whatever is left
    // in storage after a half-failed delete is unreadable.
    expect($artifact->state)->toBe(BackupArtifact::STATE_DELETED)
        ->and($artifact->wrapped_key)->toBeNull()
        ->and($artifact->storage_key)->toBeNull()
        ->and($artifact->deleted_reason)->toBe('Client asked us to');
});
