<?php

declare(strict_types=1);

use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;

/**
 * What the status endpoints answer now that a finished backup no longer reloads the page.
 *
 * Three things travel beside `in_flight`, and each is a new way for something to go wrong:
 *
 *  - `html` is rendered markup, which is a second route by which a site's name could reach a screen
 *    it does not belong on. It gets its own tenant-isolation assertion rather than relying on the
 *    one covering `in_flight`.
 *  - `settled` takes identifiers from a browser, so the same applies again and for the same reason.
 *  - `badge_html` carries a precedence rule - failed outranks running - that used to live only in
 *    the sidebar template.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    RecoveryKey::factory()->for($this->organisation)->create();
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

it('renders the in-progress cards as markup, so nothing has to rebuild them', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $html = $this->actingAs($this->owner)->getJson('/backups/status')
        ->assertOk()
        ->json('html');

    // The same component the page was built with, so the phase label, the stepper and the cancel
    // form all arrive together rather than being patched one attribute at a time.
    expect($html)->toContain('Example Site')
        ->and($html)->toContain('data-backup-progress')
        ->and($html)->toContain('Waiting for this site to check in');
});

it('renders an empty fragment when nothing is outstanding', function (): void {
    $answer = $this->actingAs($this->owner)->getJson('/backups/status')->assertOk();

    expect(trim((string) $answer->json('html')))->toBe('')
        ->and($answer->json('in_flight'))->toBe([]);
});

it('says what became of the jobs the caller names, and only those', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $job = RemoteJob::query()->sole();

    // Not asked about, so not answered. A tab is told about work it was showing, never about a
    // scheduled run or a colleague's backup that finished while it sat open.
    $this->actingAs($this->owner)->getJson('/backups/status')
        ->assertOk()
        ->assertJsonPath('settled', []);

    $job->forceFill(['state' => Jobs::STATE_SUCCEEDED, 'finished_at' => now()])->save();

    $this->actingAs($this->owner)->getJson('/backups/status?jobs='.$job->external_id)
        ->assertOk()
        ->assertJsonPath('settled.0.job_id', $job->external_id)
        ->assertJsonPath('settled.0.tone', 'ok')
        ->assertJsonPath('settled.0.sentence', 'Backup of Example Site stored.');
});

it('distinguishes the four ways a backup stops', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $job = RemoteJob::query()->sole();

    $cases = [
        // A cancellation is not a failure. Somebody chose it, so it is not amber.
        [Jobs::STATE_CANCELLED, 'ok', 'was cancelled'],

        // Nothing came to collect it. Said as what it is rather than as "the backup failed", which
        // would send somebody looking at a site that may be perfectly healthy and switched off.
        [Jobs::STATE_EXPIRED, 'warning', 'never collected'],

        [Jobs::STATE_FAILED, 'warning', 'did not complete'],
    ];

    foreach ($cases as [$state, $tone, $phrase]) {
        $job->forceFill(['state' => $state, 'finished_at' => now()])->save();

        $settled = $this->actingAs($this->owner)
            ->getJson('/backups/status?jobs='.$job->external_id)
            ->assertOk()
            ->json('settled.0');

        expect($settled['tone'])->toBe($tone)
            ->and($settled['sentence'])->toContain($phrase);
    }
});

it('does not report another organisation through the fragment or by identifier', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $job = RemoteJob::query()->sole();
    $job->forceFill(['state' => Jobs::STATE_SUCCEEDED, 'finished_at' => now()])->save();

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($stranger)->for(Organisation::factory()->create())->owner()->create();

    /*
     | Knowing the identifier is not permission to be told about it. Answered as "nothing settled"
     | rather than as a 404, because the caller is a poller reconciling a list and one stale entry
     | should not fail the four good ones beside it.
    */
    $answer = $this->actingAs($stranger)
        ->getJson('/backups/status?jobs='.$job->external_id)
        ->assertOk();

    expect($answer->json('settled'))->toBe([])
        ->and($answer->json('in_flight'))->toBe([])
        ->and($answer->json('html'))->not->toContain('Example Site');
});

it('answers the same four keys for one site', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    $this->actingAs($this->owner)
        ->getJson("/sites/{$this->site->external_id}/backups/status")
        ->assertOk()
        ->assertJsonStructure(['in_flight', 'settled', 'html', 'badge_html'])
        ->assertJsonPath('in_flight.0.phase', 'queued');
});

it('keeps the sidebar count honest, with a failure outranking work in progress', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post("/backups/sites/{$this->site->external_id}");

    // One running, none failed: the quiet grey count.
    $badge = $this->actingAs($this->owner)->getJson('/backups/status')->assertOk()->json('badge_html');

    expect($badge)->toContain('text-text-3')
        ->and($badge)->not->toContain('bg-danger-bg');

    // A second site fails while the first is still running. A backup that did not happen is the
    // thing somebody has to act on, so it takes the slot - never both, because a nav entry is not a
    // dashboard.
    $other = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Other Site']);

    RemoteJob::factory()->for($other)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_FAILED,
        'failure_reason' => 'The dump exceeded the size the connector will handle.',
    ]);

    $badge = $this->actingAs($this->owner)->getJson('/backups/status')->assertOk()->json('badge_html');

    expect($badge)->toContain('bg-danger-bg');
});
