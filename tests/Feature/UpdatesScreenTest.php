<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Capability\UnknownCapabilityException;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\UpdateReport;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();
});

it('shows a security release prominently', function (): void {
    UpdateReport::factory()->for($this->site)->create();
    $this->site->forceFill(['has_security_release' => true, 'available_updates' => 2])->save();

    $this->actingAs($this->user)
        ->get('/updates')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertSee('Security release')
        ->assertSee('5.6.2')
        ->assertSee('5.6.4');
});

it('separates sites that have not reported from sites that are up to date', function (): void {
    UpdateReport::factory()->for($this->site)->upToDate()->create();

    $silent = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Silent Site']);
    Connector::factory()->for($silent)->create();

    $this->actingAs($this->user)
        ->get('/updates')
        ->assertOk()
        // "No updates available" and "we do not know" are different answers, and conflating them is
        // how a stale site gets ignored.
        ->assertSee('Not reporting updates')
        ->assertSee('Silent Site')
        ->assertSee('Not granted');
});

it('never shows release notes or download URLs', function (): void {
    UpdateReport::factory()->for($this->site)->create();

    $html = $this->actingAs($this->user)->get('/updates')->getContent();

    // Not merely absent from the view: absent from the schema, so there is nothing to render.
    expect($html)->not->toContain('release_notes')
        ->and($html)->not->toContain('download_url');
});

it('queues a job rather than reaching into the site', function (): void {
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $this->site))
        ->assertRedirect();

    $job = RemoteJob::query()->firstOrFail();

    // The platform cannot contact a connector, so this waits for the site to come and ask. The
    // message says so rather than implying an immediate result.
    expect($job->type)->toBe(Jobs::UPDATES_CHECK)
        ->and($job->state)->toBe(Jobs::STATE_QUEUED)
        ->and(session('status'))->toContain('next time the site checks in');
});

it('does not queue two checks when the button is pressed twice', function (): void {
    $acting = $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $acting->post(route('updates.refresh', $this->site));
    $acting->post(route('updates.refresh', $this->site));

    expect(RemoteJob::query()->count())->toBe(1);
});

it('explains itself when the capability is missing rather than failing silently', function (): void {
    $bare = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Bare Site']);
    Connector::factory()->for($bare)->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $bare))
        ->assertRedirect();

    expect(session('warning'))->toContain('Grant updates:read')
        ->and(RemoteJob::query()->count())->toBe(0);
});

it('requires recent authentication to request a check', function (): void {
    $this->actingAs($this->user)
        ->post(route('updates.refresh', $this->site))
        ->assertRedirect(route('password.confirm'));

    expect(RemoteJob::query()->count())->toBe(0);
});

it('hides another organisation site from the refresh action', function (): void {
    $other = Site::factory()->for(Organisation::factory())->connected()->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('updates.refresh', $other))
        ->assertNotFound();
});

it('grants a read capability from the interface', function (): void {
    $site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($site)->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.capabilities.grant', $site), ['capability' => 'updates:read'])
        ->assertRedirect();

    expect($site->fresh()->hasCapability('updates:read'))->toBeTrue();
});

it('refuses to grant a capability that modifies the site', function (): void {
    // backups:create reads the full database including user records. A toggle beside the read
    // switches would understate that considerably, so it needs its own confirmation flow.
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.capabilities.grant', $this->site), ['capability' => 'backups:create'])
        ->assertSessionHasErrors('capability');

    expect($this->site->fresh()->hasCapability('backups:create'))->toBeFalse();
});

it('refuses at the service layer too, however it is called', function (): void {
    // The route validates against the grantable list; this is the check behind it, so a caller that
    // bypasses the route still cannot grant something without a confirmation flow.
    expect(fn () => app(CapabilityService::class)
        ->grant($this->site, 'backups:create', $this->user))
        ->toThrow(UnknownCapabilityException::class);
});

it('shows the capability section with unavailable capabilities explained', function (): void {
    // Capabilities became a section of Settings rather than a screen of its own. The old route still
    // resolves and redirects, which is what the following test asserts.
    $this->actingAs($this->user)
        ->get(route('sites.settings', $this->site))
        ->assertOk()
        ->assertSee('Take a database backup')
        // Says why it is not on offer rather than just showing it greyed out.
        ->assertSee('Needs separate confirmation');
});

it('keeps the old capabilities link working', function (): void {
    // Links to it exist on the fleet screens and in people's bookmarks. A moved section should not
    // produce a 404 for either.
    $this->actingAs($this->user)
        ->get(route('sites.capabilities', $this->site))
        ->assertRedirect(route('sites.settings', $this->site).'#capabilities');
});

it('counts fleet updates in the sidebar', function (): void {
    $this->site->forceFill(['available_updates' => 3, 'has_security_release' => true])->save();

    $this->actingAs($this->user)
        ->get('/sites')
        ->assertOk()
        // Amber, because a security release anywhere is the one number that should interrupt.
        ->assertSee('border-amber-line', false);
});
