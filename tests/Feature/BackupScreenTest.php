<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
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

    // A backup needs a recovery key to encrypt to, whatever the format floor, so an organisation
    // that can take one has a key. This used to be absent here, and the tests below passed because
    // the rule only applied at the v2 floor — which no organisation reaches until it adds its first
    // key, so the rule applied to nobody who had not already complied with it.
    $this->key = RecoveryKey::factory()->for($this->organisation)->create();

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

it('offers the ciphertext for download and a command for decrypting it', function (): void {
    /*
     | This assertion used to be that there was no download button at all, and the reason given was
     | that a download which works until a database is big enough to matter is worse than a command
     | that always works. That reason is about *decrypting* inside a web request, and it is still
     | enforced — by the two assertions at the bottom, which are the part worth keeping.
     |
     | What it also did, unintentionally, was forbid handing over the bytes as they are already
     | stored. A v2 artifact is sealed to the organisation's own recovery keys, so the console cannot
     | decrypt it and says so, and told people to run `manager-restore decrypt` on a file the product
     | gave them no way to obtain. On Cloud that made the flagship feature unusable without an
     | operator opening an SSH session. So the button exists now, and it is ciphertext only.
    */
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    $artifact = BackupArtifact::factory()->for($this->site)->create(['organisation_id' => $this->organisation->id]);

    $html = $this->actingAs($this->owner)->get('/backups')->assertOk()->getContent();

    expect($html)->toContain(route('backups.download', $artifact))
        ->and($html)->toContain('manager-restore decrypt')
        ->and($html)->toContain('manager:backups:fetch')
        ->and($html)->toContain('Restoring is not automated')

        // The protection the old assertion was actually providing. Nothing on this screen offers
        // plaintext through the browser, and no restore button implies a recovery path nobody has
        // designed.
        ->and($html)->not->toContain('>Restore<')
        ->and($html)->not->toContain('.sql</a>');
});

it('names the recovery key that opens each backup', function (): void {
    // An organisation can hold several keys, and rotating them means an older backup needs an older
    // key. The platform recorded which one when it sealed the artifact, so making somebody download
    // the file and run `inspect` to find out would be a strange thing to insist on.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'format_version' => BackupArtifact::FORMAT_V2,
    ]);

    $artifact->recipients()->create([
        'fingerprint' => 'MGRK-TEST-0001',
        'public_key' => str_repeat('a', 44),
        'wrapped_key' => str_repeat('b', 44),
        'label' => 'Ops laptop',
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Sealed to Ops laptop');
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

it('attributes a requested backup and says so rather than queueing a second one behind it', function (): void {
    // Neither an actor nor an idempotency key was passed, so the audit row for a job that reads an
    // entire production database recorded only that "the system" asked — and a second press queued a
    // second full dump of the same database.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect();

    // The second press is now refused with a reason instead of being absorbed by the idempotency
    // key. Same outcome for the database — one job — but somebody who presses twice because nothing
    // appeared to happen is told that something did.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHasErrors('site');

    $job = RemoteJob::query()->sole();

    expect($job->requested_by)->toBe($this->owner->id)
        ->and($job->requested_by_label)->toBe($this->owner->name ?: $this->owner->email);

    // One request, one timeline entry. The refused press is not a request.
    expect(BackupEvent::query()->where('event', BackupEvent::REQUESTED)->count())->toBe(1);
});

it('will not queue a manual backup on top of a scheduled one', function (): void {
    /*
     | The gap the idempotency key never covered.
     |
     | Manual presses are keyed 'backup:manual' and scheduled runs are keyed per site and hour, so
     | they never collided — pressing the button while a nightly backup sat queued produced a second
     | job and, when the site next checked in, two concurrent dumps of the same database. That is the
     | outage this whole area is written to avoid, and it was reachable from a button.
    */
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_QUEUED,
        'idempotency_key' => 'schedule:'.$this->site->external_id.':2026-07-31-03',
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHasErrors('site');

    expect(RemoteJob::query()->where('type', Jobs::BACKUP_CREATE)->count())->toBe(1);
});

it('refuses a backup with a reason when the organisation has no recovery key to encrypt to', function (): void {
    /*
     | What the screen used to do: flash "Backup requested. It will run when the site next checks
     | in", write a REQUESTED row, and then have JobService::claimFor() cancel the job minutes later
     | for a reason stated on a different screen entirely. The worst version of that is somebody
     | believing a site is backed up when it is not.
    */
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    // No floor is set. That is the point: the rule used to apply only at v2, which an organisation
    // reaches on its first key activation — so the only organisations exempt from "you need a key"
    // were the ones that had never had one.
    $this->key->forceFill(['state' => RecoveryKey::STATE_REVOKED, 'revoked_at' => now()])->save();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHasErrors('site');

    expect(RemoteJob::query()->count())->toBe(0)
        ->and(BackupEvent::query()->where('event', BackupEvent::REQUESTED)->count())->toBe(0);

    $this->actingAs($this->owner)
        ->get(route('sites.backups', $this->site))
        ->assertOk()
        ->assertSee('nothing to encrypt a backup to')
        ->assertSee('Add one in Settings');
});

it('does not offer a backup from a site whose connector has gone away', function (): void {
    // Enqueue throws SITE_NOT_CONNECTED here, and nothing caught it — so a routine race between the
    // screen rendering and the button being pressed was a 500.
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();
    $this->site->connectors()->delete();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHasErrors('site');

    expect(RemoteJob::query()->count())->toBe(0);
});

it('shows a requested backup as queued until the site collects it', function (): void {
    /*
     | The screen used to look identical after pressing the button: a flash message, then nothing at
     | all until an artifact appeared minutes later. A site collects work every five minutes at best,
     | so the observable consequence was people pressing the button again.
     */
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    foreach (['/backups', "/sites/{$this->site->external_id}/backups"] as $url) {
        $this->actingAs($this->owner)->get($url)
            ->assertOk()
            ->assertSee('Queued')
            // Said plainly, because nothing is wrong and nothing is stuck.
            ->assertSee('Waiting for this site to check in');
    }
});

it('follows a backup through the phases the site reports', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $job = RemoteJob::query()->sole();
    $job->forceFill(['state' => Jobs::STATE_CLAIMED, 'claimed_at' => now()])->save();

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Collected by the site');

    // A phase the site reported about itself, recorded against the job rather than an artifact —
    // there is no artifact yet, which is why backup_events allows a null one.
    BackupEvent::query()->create([
        'remote_job_id' => $job->id,
        'organisation_id' => $this->organisation->id,
        'site_id' => $this->site->id,
        'event' => BackupEvent::DUMP_STARTED,
        'source' => BackupEvent::SOURCE_CONNECTOR,
        'occurred_at' => now(),
        'recorded_at' => now(),
    ]);

    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee('Dumping the database')
        // Whose claim it is, said on the screen rather than assumed.
        ->assertSee('Reported by the site');
});

it('serves the in-flight list as JSON without leaking another organisation', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $this->actingAs($this->owner)->getJson('/backups/status')
        ->assertOk()
        ->assertJsonPath('in_flight.0.phase', 'queued')
        ->assertJsonPath('in_flight.0.site', $this->site->external_id);

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($stranger)->for(Organisation::factory()->create())->owner()->create();

    $this->actingAs($stranger)->getJson('/backups/status')
        ->assertOk()
        ->assertJsonPath('in_flight', []);
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

it('removes a failed artifact outright, rather than tombstoning a backup that never existed', function (): void {
    /*
     * These accumulated with no way to remove them. The delete button asks isRetrievable(), which a
     * failed declaration can never satisfy, and the nightly prune only ever creates more of them —
     * so a site failing every night filled the screen for good.
     *
     * Discarded rather than deleted, because a tombstone here would record the absence of something
     * that was already absent: no ciphertext was ever stored and no key was ever wrapped. The audit
     * entry, which carries the reason, is the record.
     */
    $artifact = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_FAILED,
        'failure_reason' => 'The artifact was declared but never uploaded',
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/backups/{$artifact->external_id}")
        ->assertRedirect();

    expect(BackupArtifact::query()->find($artifact->id))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'backup.discarded')->count())->toBe(1);
});

it('gives a failed artifact a way to be removed at all', function (): void {
    $artifact = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_FAILED,
        'failure_reason' => 'The artifact was declared but never uploaded',
    ]);

    // The affordance, not just the route behind it: this row was unremovable because nothing on the
    // screen offered to remove it.
    $this->actingAs($this->owner)->get('/backups')
        ->assertOk()
        ->assertSee(route('backups.destroy', $artifact));
});

it('will not discard an artifact that actually stored something', function (): void {
    // Same button, two acts. A stored artifact keeps its tombstone and its destroyed key; only the
    // rows that never held bytes disappear.
    $artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete("/backups/{$artifact->external_id}", ['reason' => 'Client asked us to'])
        ->assertRedirect();

    expect($artifact->fresh()->state)->toBe(BackupArtifact::STATE_DELETED);
});

it('refuses to remove a failed artifact for anybody but an owner', function (): void {
    $artifact = BackupArtifact::factory()->for($this->site)->pending()->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_FAILED,
    ]);

    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->delete("/backups/{$artifact->external_id}")
        ->assertForbidden();

    expect(BackupArtifact::query()->find($artifact->id))->not->toBeNull();
});
