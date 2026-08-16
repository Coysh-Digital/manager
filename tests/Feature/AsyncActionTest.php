<?php

declare(strict_types=1);

use App\Http\Middleware\AnswerInPlace;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;

/**
 * Actions answered where they were pressed.
 *
 * The behaviour under test is a middleware that turns a redirect carrying flash data into that flash
 * data as JSON, for callers that ask for it. Two things about it are easy to get wrong and are what
 * most of this file is for:
 *
 *  - **It must be inert without the header.** The bands above <main> are the only feedback an
 *    installation with JavaScript blocked gets, and they are fed by the redirect. Every action here
 *    is asserted twice, once each way.
 *  - **It must only report what the request it is answering actually said.** A flash survives one
 *    further request, so the naive reading re-announces the previous action every time somebody
 *    presses a button that had nothing to say.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    RecoveryKey::factory()->for($this->organisation)->create();

    $this->async = [AnswerInPlace::HEADER => '1'];
});

it('queues the backup and answers in place rather than redirecting', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $response = $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post("/backups/sites/{$this->site->external_id}");

    $response->assertOk()->assertJsonPath('errors', []);

    // The sentence is the one the band would have shown, unchanged. Which of the two arrives depends
    // on whether the site can be knocked on, and this is asserting that a sentence arrived at all —
    // BackupScreenTest already pins the wording of each.
    expect($response->json('status'))->toContain('Backup requested for Example Site');

    // The action happened, which is the half a toast could otherwise lie about.
    $job = RemoteJob::query()->sole();
    expect($job->site_id)->toBe($this->site->id);
});

it('still redirects and still flashes when the header is absent', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    /*
     | The test that stops somebody simplifying the middleware into being unconditional.
     |
     | Everything about the async path is an addition. If this ever fails, an installation running
     | with scripts blocked has lost the only feedback it has.
    */
    $this->actingAs($this->owner)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertRedirect()
        ->assertSessionHas('status');
});

it('leaves the flash in the session, so the band still renders on the next navigation', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    /*
     | Deliberate, and the one thing about this design somebody will want to change.
     |
     | Reading the flash does not consume it, so the same sentence appears again as a band the next
     | time the person navigates. That was chosen over forgetting it: forgetting is a decision about
     | the no-JavaScript path taken on the JavaScript path, and the cost of keeping it is one stale
     | band bounded to the most recent action. Reversing it is one `forget()` call.
    */
    $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertOk()
        ->assertSessionHas('status');
});

it('does not re-announce a message left over from the previous request', function (): void {
    /*
     | A flash written by an earlier request is still in the session while this one runs. Without the
     | `_flash.new` check, every action that had nothing of its own to say would repeat the last one
     | that did - so a refusal would arrive with a cheerful green "Backup requested" beside it.
     |
     | Exercised with a refresh on an unpaired site, which is the case that makes the assertion
     | sharp: it flashes `errors` and no `status` at all, so a `status` in the answer can only have
     | come from the session. The session is seeded as it genuinely looks on a second request - the
     | value present, and the list of keys flashed *by this request* empty.
    */
    $silent = Site::factory()->for($this->organisation)->create(['name' => 'Unpaired Site']);

    $response = $this->actingAs($this->owner)
        ->withSession(['status' => 'Backup requested for Example Site.', '_flash.new' => []])
        ->withHeaders($this->async)
        ->post("/sites/{$silent->external_id}/refresh");

    $response->assertOk();

    expect($response->json('status'))->toBeNull()
        ->and($response->json('errors'))->not->toBeEmpty();
});

it('reports a refusal as errors rather than as a status', function (): void {
    // No capability granted, so the readiness check refuses before anything is queued.
    $response = $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post("/backups/sites/{$this->site->external_id}");

    $response->assertOk();

    expect($response->json('status'))->toBeNull()
        ->and($response->json('errors'))->not->toBeEmpty();

    expect(RemoteJob::query()->count())->toBe(0);
});

it('makes a refresh failure visible, which it is not on five of the seven site tabs', function (): void {
    /*
     | `SiteController::refresh()` flashes an error, and Overview, Health, Updates, Security and
     | Audit all render the Refresh button without an `@if ($errors->any())` block - so on those
     | screens the sentence is written to the session and thrown away.
     |
     | The band half of that is untouched here and still open. The toast half is closed by this
     | route answering in place, which is the first time the message reaches anybody on those tabs.
    */
    $silent = Site::factory()->for($this->organisation)->create(['name' => 'Unpaired Site']);

    $response = $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post("/sites/{$silent->external_id}/refresh");

    $response->assertOk();

    expect($response->json('errors'))->toHaveCount(1)
        ->and($response->json('errors.0'))->toContain('no active connector');
});

it('carries the destination so a caller can follow one that is not back', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    // Always present, and compared by the caller against where it already is. `back()` names the
    // page the press came from, which is the ordinary case; anything else is a real instruction.
    $this->actingAs($this->owner)
        ->from('/backups')
        ->withHeaders($this->async)
        ->post("/backups/sites/{$this->site->external_id}")
        ->assertOk()
        ->assertJsonPath('redirect', url('/backups'));
});

it('does not answer in place on a route that did not ask for it', function (): void {
    /*
     | The opt-in is per route, and this proves it is not per request. `sites.store` is deliberately
     | not on the list - adding a site is not something it is harmless to do twice, and the fallback
     | in submit.js re-posts.
    */
    $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post('/sites', ['name' => 'New Site', 'url' => 'https://example.test'])
        ->assertRedirect();
});

it('answers the other four actions in place too', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    foreach ([
        "/sites/{$this->site->external_id}/refresh",
        '/sites/refresh-all',
        "/updates/{$this->site->external_id}/refresh",
    ] as $url) {
        $this->actingAs($this->owner)
            ->withHeaders($this->async)
            ->post($url)
            ->assertOk()
            ->assertJsonStructure(['status', 'warning', 'errors', 'redirect']);
    }

    $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post('/backups/sites', ['sites' => [$this->site->external_id]])
        ->assertOk()
        ->assertJsonStructure(['status', 'warning', 'errors', 'redirect']);

    $job = RemoteJob::query()->where('type', 'backup.create')->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeaders($this->async)
        ->post('/backups/cancel', ['job' => $job->external_id])
        ->assertOk()
        ->assertJsonPath('errors', []);
});
