<?php

declare(strict_types=1);

use App\Domain\Findings\Severity;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;

/*
 * The Security screen, and what Findings stopped showing.
 *
 * The two are one table split by rule category, so the tests that matter are the ones about the
 * seam: that nothing appears twice, that nothing disappears, and that a reader on the old screen is
 * told where the rest went.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->owner = User::factory()->create(['name' => 'Tim Coysh', 'email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

it('lists a security finding on Security and not on Findings', function (): void {
    Finding::factory()->for($this->site)->rule('dev_mode_in_production')->severity(Severity::HIGH)->create([
        'title' => 'Development mode is on in production',
    ]);

    $this->actingAs($this->owner)
        ->get('/security')
        ->assertOk()
        ->assertSee('Development mode is on in production');

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        ->assertDontSee('Development mode is on in production');
});

it('lists a maintenance finding on Findings and not on Security', function (): void {
    Finding::factory()->for($this->site)->rule('pending_migrations')->create([
        'title' => 'Migrations are waiting to run',
    ]);

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        ->assertSee('Migrations are waiting to run');

    $this->actingAs($this->owner)
        ->get('/security')
        ->assertOk()
        ->assertDontSee('Migrations are waiting to run');
});

it('tells a reader on Findings how many security findings are elsewhere', function (): void {
    // The whole reason the split is safe. A count that quietly drops because one screen stopped
    // counting it is indistinguishable from a problem being fixed.
    Finding::factory()->for($this->site)->rule('dev_mode_in_production')->create();
    Finding::factory()->for($this->site)->rule('https_not_enforced')->create();

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        ->assertSee('security findings')
        ->assertSee('2');
});

it('says nothing about a screen with nothing on it', function (): void {
    Finding::factory()->for($this->site)->rule('pending_migrations')->create();

    $this->actingAs($this->owner)
        ->get('/findings')
        ->assertOk()
        ->assertDontSee('are on the Security screen');
});

it('lists a site with no security findings rather than omitting it', function (): void {
    /*
     | A screen that listed only sites with problems could not distinguish "nothing is wrong here"
     | from "nothing has been checked here", and a rule whose capability is not granted is skipped
     | rather than passed. The clean site has to be on the page for that sentence to have anywhere
     | to go.
     */
    CapabilityGrant::factory()->for($this->site)->capability('security:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('logins:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();

    $this->actingAs($this->owner)
        ->get('/security')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertSee('No security findings');
});

it('says which security checks could not run rather than reporting a clean site', function (): void {
    // No capability granted at all, so every rule that needs one was skipped. An empty list here
    // means "we were not allowed to look", and saying so is the point.
    $this->actingAs($this->owner)
        ->get('/security')
        ->assertOk()
        ->assertSee('could not run on this site')
        ->assertSee('dev_mode_in_production');
});

it('orders the worst site first', function (): void {
    $quiet = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Aaa Quiet Site']);
    Finding::factory()->for($quiet)->rule('accounts_locked_out')->severity(Severity::MEDIUM)->create();
    Finding::factory()->for($this->site)->rule('craft_security_release')->severity(Severity::CRITICAL)->create();

    $html = $this->actingAs($this->owner)->get('/security')->assertOk()->getContent();

    // Alphabetically the quiet site sorts first. Somebody opened this screen because something was
    // wrong, so severity has to win.
    expect(strpos($html, 'Example Site'))->toBeLessThan(strpos($html, 'Aaa Quiet Site'));
});

it('acknowledges a security finding from the Security screen', function (): void {
    $finding = Finding::factory()->for($this->site)->rule('https_not_enforced')->create();

    $this->actingAs($this->owner)
        ->withSession($this->recentAuth)
        ->from('/security')
        ->post("/findings/{$finding->getRouteKey()}/acknowledge", ['reason' => 'Behind a proxy that terminates TLS'])
        ->assertRedirect('/security');

    expect($finding->fresh()->state)->toBe(Finding::STATE_ACKNOWLEDGED);
});

it('shows no other organisation\'s sites', function (): void {
    $other = Organisation::factory()->create(['name' => 'Someone Else']);
    Site::factory()->for($other)->connected()->create(['name' => 'Not Yours']);

    $this->actingAs($this->owner)
        ->get('/security')
        ->assertOk()
        ->assertDontSee('Not Yours');
});

it('hides resolved security findings unless asked for', function (): void {
    Finding::factory()->for($this->site)->rule('certificate_expiring')->resolved()->create([
        'title' => 'Certificate was renewed',
    ]);

    $this->actingAs($this->owner)->get('/security')->assertOk()->assertDontSee('Certificate was renewed');
    $this->actingAs($this->owner)->get('/security?resolved=1')->assertOk()->assertSee('Certificate was renewed');
});
