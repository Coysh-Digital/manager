<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Nonce;

/**
 * Adding a site and issuing the code that pairs it.
 *
 * This was missing entirely — the fleet screen said "Add one, then pair its connector" and there was
 * no route that would. Every site in the test suite had been created through a factory, which is how a
 * gap in the primary flow stayed invisible.
 *
 * The security properties worth holding are about the code: it is shown once, only its hash is stored,
 * a new one invalidates the last, and it cannot displace a working connector without somebody saying so.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];

    $this->add = fn (array $overrides = []) => $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->post('/sites', array_merge([
            'name' => 'Example Client',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ], $overrides));
});

it('adds a site and issues a code in one step', function (): void {
    $response = ($this->add)();

    $site = Site::query()->sole();

    $response->assertRedirect(route('sites.show', $site));

    expect($site->name)->toBe('Example Client')
        ->and($site->expected_domain)->toBe('example.org')
        ->and($site->environment)->toBe('production')
        // Never rather than not: it has not stopped reporting, it has not started.
        ->and($site->status)->toBe(Site::STATUS_NEVER_CONNECTED);

    expect(EnrolmentCode::query()->where('site_id', $site->id)->count())->toBe(1);
});

it('shows the code once and never again', function (): void {
    $response = ($this->add)();

    $code = session('enrolmentCode');

    expect($code)->toBeString()
        ->and($code)->toStartWith('mgr_enrol_');

    // On the screen it redirected to...
    $this->actingAs($this->owner)->get(route('sites.show', Site::query()->sole()))
        ->assertOk()
        ->assertSee($code);

    // ...and not on the next request, nor anywhere else.
    $this->actingAs($this->owner)->get(route('sites.show', Site::query()->sole()))
        ->assertOk()
        ->assertDontSee($code);
});

it('stores only the hash of the code', function (): void {
    ($this->add)();

    $code = (string) session('enrolmentCode');
    $stored = EnrolmentCode::query()->sole();

    // The column is a hash, and the hash is the one pairing will compute. If these disagreed the code
    // would be unusable, which is the failure this catches.
    expect($stored->code_hash)->toBe(Nonce::hashEnrolmentCode($code))
        ->and($stored->code_hash)->not->toBe($code)
        ->and(DB::table('enrolment_codes')->get()->toJson())->not->toContain($code);
});

it('records issuing it without recording the code', function (): void {
    ($this->add)();

    $code = (string) session('enrolmentCode');

    $created = AuditEvent::query()->where('action', 'site.created')->sole();
    $issued = AuditEvent::query()->where('action', 'enrolment_code.issued')->sole();

    expect($created->after['expected_domain'])->toBe('example.org')
        ->and($issued->actor_id)->toBe($this->owner->id)
        // Neither the code nor its hash. A hash of a 256-bit secret is not sensitive, but recording it
        // would invite somebody to treat the audit log as a place to look one up.
        ->and($issued->toJson())->not->toContain($code)
        ->and($issued->toJson())->not->toContain(Nonce::hashEnrolmentCode($code));
});

it('reduces a pasted URL to a bare host', function (string $typed): void {
    ($this->add)(['expected_domain' => $typed]);

    // People paste URLs. Storing one whole would make the domain comparison at pairing fail for the
    // wrong reason, and present as a mysterious pending-confirmation rather than as a typo.
    expect(Site::query()->sole()->expected_domain)->toBe('example.org');
})->with([
    'plain host' => 'example.org',
    'https URL' => 'https://example.org',
    'URL with path' => 'https://example.org/admin',
    'with www' => 'https://www.example.org',
    'with port' => 'example.org:8443',
    'shouting' => 'HTTPS://Example.ORG',
    'padded' => '  example.org  ',
]);

it('refuses something that is not a domain', function (string $typed): void {
    ($this->add)(['expected_domain' => $typed])->assertSessionHasErrors('expected_domain');

    expect(Site::query()->count())->toBe(0);
})->with([
    'no dot' => 'localhost',
    'empty-ish' => '   ',
    'scheme only' => 'https://',
]);

it('invalidates the previous code when a new one is issued', function (): void {
    ($this->add)();

    $site = Site::query()->sole();
    $first = EnrolmentCode::query()->sole();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('sites.enrolment-code', $site))
        ->assertRedirect();

    // Two live codes would mean a leaked one stayed usable after somebody reissued in response to the
    // leak — which is usually why they reissued.
    expect($first->fresh()->isConsumed())->toBeTrue()
        ->and(EnrolmentCode::query()->whereNull('consumed_at')->count())->toBe(1);
});

it('will not replace a working connector unless told to', function (): void {
    ($this->add)();

    $site = Site::query()->sole();
    Connector::factory()->for($site)->create(['state' => Connector::STATE_ACTIVE]);

    // The checkbox is required when a connector is active, so omitting it is a validation failure
    // rather than a silent grant.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('sites.enrolment-code', $site))
        ->assertSessionHasErrors('authorise_replacement');

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('sites.enrolment-code', $site), ['authorise_replacement' => '1'])
        ->assertRedirect();

    expect(EnrolmentCode::query()->whereNull('consumed_at')->sole()->authorisesReplacement())->toBeTrue();
});

it('does not mark a first pairing as a replacement', function (): void {
    ($this->add)();

    // No connector yet, so nothing is being replaced and the code must not carry the authorisation.
    expect(EnrolmentCode::query()->sole()->authorisesReplacement())->toBeFalse();
});

it('needs recent authentication', function (): void {
    $this->actingAs($this->owner)->post('/sites', [
        'name' => 'Opportunistic',
        'expected_domain' => 'example.org',
        'environment' => 'production',
    ])->assertRedirect(route('password.confirm'));

    expect(Site::query()->count())->toBe(0);
});

it('refuses a member who is not an administrator', function (): void {
    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->post('/sites', [
            'name' => 'Not mine to add',
            'expected_domain' => 'example.org',
            'environment' => 'production',
        ])
        ->assertForbidden();

    expect(Site::query()->count())->toBe(0);
});

it('cannot issue a code for another organisation\'s site', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post(route('sites.enrolment-code', $theirs))
        ->assertNotFound();

    expect(EnrolmentCode::query()->count())->toBe(0);
});

it('issues a code that pairing will actually accept', function (): void {
    ($this->add)();

    $code = (string) session('enrolmentCode');

    // The round trip that matters: what the interface hands somebody has to satisfy the validator on
    // the other side of the wire, and expire in the configured window rather than immediately.
    expect(Nonce::isValidEnrolmentCode($code))->toBeTrue();

    $stored = EnrolmentCode::query()->sole();

    expect($stored->isExpired())->toBeFalse()
        ->and($stored->expires_at->timestamp)
        ->toEqualWithDelta(now()->addSeconds((int) config('manager.enrolment.ttl'))->timestamp, 5);
});

it('offers the form on the fleet screen, and only to administrators', function (): void {
    $this->actingAs($this->owner)->get('/sites')
        ->assertOk()
        ->assertSee('Add a site')
        ->assertSee('expected_domain', false);

    // A member sees the fleet but not the means to change it.
    $this->actingAs($this->member)->get('/sites')
        ->assertOk()
        ->assertDontSee('Add a site');
});

// --------------------------------------------------------------------------------------------------
// Capabilities chosen when the site is added
// --------------------------------------------------------------------------------------------------

it('grants every read capability by default', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/sites', [
        'name' => 'Example Client',
        'expected_domain' => 'example.org',
        'environment' => 'production',
        'capabilities' => CapabilityService::grantableFromInterface(),
    ])->assertRedirect();

    // Every one of them, without anybody visiting the Capabilities screen. A fleet table with nothing
    // in it is not much of a fleet table.
    expect(Site::query()->sole()->grantedCapabilities())
        ->toEqualCanonicalizing(CapabilityService::grantableFromInterface());
});

it('checks every read capability in the form by default', function (): void {
    $html = $this->actingAs($this->owner)->get('/sites')->assertOk()->getContent();

    // Counted rather than eyeballed: one checkbox per grantable capability, and every one checked.
    // Derived from the list rather than a literal, so adding a capability changes this test's
    // subject rather than breaking it — the claim is "all of them", not "five of them".
    $expected = count(CapabilityService::grantableFromInterface());

    expect(substr_count($html, 'name="capabilities[]"'))->toBe($expected)
        ->and(preg_match_all('/name="capabilities\[\]"[^>]*checked/', $html))->toBe($expected);
});

it('never offers backups as a checkbox, and refuses it if asked', function (): void {
    $html = $this->actingAs($this->owner)->get('/sites')->assertOk()->getContent();

    expect($html)->not->toContain('value="backups:create"');

    // Refused by the validation rule, not merely absent from the form — invariant 7 is not a matter of
    // which inputs happen to be rendered.
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/sites', [
        'name' => 'Sneaky',
        'expected_domain' => 'example.org',
        'environment' => 'production',
        'capabilities' => ['inventory:read', 'backups:create'],
    ])->assertSessionHasErrors('capabilities.1');

    expect(Site::query()->count())->toBe(0);
});

it('grants nothing when every box is unchecked', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)->post('/sites', [
        'name' => 'Watchful waiting',
        'expected_domain' => 'example.org',
        'environment' => 'production',
    ])->assertRedirect();

    // A site can be added now and permitted later. Pairing will still grant inventory:read, because
    // that is what pairing does — but nothing here presumes.
    expect(Site::query()->sole()->grantedCapabilities())->toBe([]);
});

// --------------------------------------------------------------------------------------------------
// Refresh
// --------------------------------------------------------------------------------------------------

it('queues a refresh for a site', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();

    $this->actingAs($this->owner)->post(route('sites.refresh', $site))
        ->assertRedirect()
        ->assertSessionHas('status');

    $job = RemoteJob::query()->sole();

    expect($job->type)->toBe(Jobs::INVENTORY_REFRESH)
        ->and($job->state)->toBe(Jobs::STATE_QUEUED);
});

it('also queues an update check when that is permitted', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();
    CapabilityGrant::factory()->for($site)->capability('updates:read')->create();

    $this->actingAs($this->owner)->post(route('sites.refresh', $site))->assertRedirect();

    expect(RemoteJob::query()->pluck('type')->all())
        ->toEqualCanonicalizing(['inventory.refresh', 'updates.check']);
});

it('does not pile up refreshes when pressed repeatedly', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();

    foreach (range(1, 4) as $ignored) {
        $this->actingAs($this->owner)->post(route('sites.refresh', $site));
    }

    // One outstanding job, not four. Somebody pressing a button that appears to do nothing will press
    // it again, and the site should not pay for that.
    expect(RemoteJob::query()->count())->toBe(1);
});

it('says so rather than failing when a site has no connector', function (): void {
    $site = Site::factory()->for($this->organisation)->create();

    $this->actingAs($this->owner)->post(route('sites.refresh', $site))
        ->assertSessionHasErrors('refresh');

    expect(RemoteJob::query()->count())->toBe(0);
});

it('refreshes the whole fleet, and reports what it skipped', function (): void {
    $ready = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($ready)->create();
    CapabilityGrant::factory()->for($ready)->capability('inventory:read')->create();

    // Connected but with nothing granted, so there is nothing to ask it for.
    $bare = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($bare)->create();

    $this->actingAs($this->owner)->post(route('sites.refresh-all'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(RemoteJob::query()->count())->toBe(1);

    // The skipped one is reported rather than swallowed. "Refreshed" while quietly doing half of them
    // is the sort of half-truth somebody discovers a week later.
    expect(session('status'))->toContain('1 skipped');
});

it('lets a member refresh, since it only asks a site to repeat itself', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();

    // No recent-auth gate and no administrator requirement: it is the least privileged useful action
    // in the interface, and gating it would only make people wait for cron.
    $this->actingAs($this->member)->post(route('sites.refresh', $site))->assertRedirect();

    expect(RemoteJob::query()->count())->toBe(1);
});

it('cannot refresh another organisation\'s site', function (): void {
    $other = Organisation::factory()->create();
    $theirs = Site::factory()->for($other)->connected()->create();
    Connector::factory()->for($theirs)->create();

    $this->actingAs($this->owner)->post(route('sites.refresh', $theirs))->assertNotFound();

    expect(RemoteJob::query()->count())->toBe(0);
});
