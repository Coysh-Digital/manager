<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorder;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
});

it('sends an unauthenticated visitor to sign in', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/sites', '/activity', '/account']);

it('renders the sign-in page', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Sign in')
        // The claim on the sign-in page is one the schema actually backs up.
        ->assertSee('never holds an administrator password', false);
});

it('closes the setup route once an account exists', function (): void {
    // Not merely hidden: the route stops resolving, so there is nothing to probe for.
    $this->get('/setup')->assertNotFound();
});

it('opens the setup route on a genuinely empty installation', function (): void {
    User::query()->delete();

    $this->get('/setup')->assertOk()->assertSee('Set up Manager');
});

it('creates the first organisation and owner through setup', function (): void {
    User::query()->delete();
    Organisation::query()->delete();

    $this->post('/setup', [
        'organisation' => 'Coysh Digital',
        'name' => 'Tim Coysh',
        'email' => 'owner@example.org',
        'password' => 'correct-horse-battery-staple-42',
        'password_confirmation' => 'correct-horse-battery-staple-42',
    ])->assertRedirect(route('account.show'));

    $owner = User::query()->where('email', 'owner@example.org')->firstOrFail();

    expect($owner->memberships()->first()->role)->toBe(Membership::ROLE_OWNER)
        // Whoever completed setup demonstrably had access to the installation, which is a stronger
        // claim than a click on an emailed link.
        ->and($owner->email_verified_at)->not->toBeNull()
        ->and(auth()->id())->toBe($owner->id);
});

it('rejects a weak password at setup', function (): void {
    User::query()->delete();

    $this->post('/setup', [
        'organisation' => 'Coysh Digital',
        'name' => 'Tim Coysh',
        'email' => 'owner@example.org',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('password');

    expect(User::query()->count())->toBe(0);
});

it('shows the fleet to a member', function (): void {
    $this->actingAs($this->user)
        ->get('/sites')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertSee($this->site->expected_domain);
});

it('filters the fleet from the query string', function (): void {
    Site::factory()->for($this->organisation)->connected()->create(['name' => 'Other Site']);

    // Bound to the URL rather than the session, so a filtered view can be linked and bookmarked.
    $this->actingAs($this->user)
        ->get('/sites?q=Example')
        ->assertOk()
        ->assertSee('Example Site')
        ->assertDontSee('Other Site');
});

it('renders a site and its capabilities', function (): void {
    Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->actingAs($this->user)->get(route('sites.show', $this->site))->assertOk()->assertSee('Example Site');

    $this->actingAs($this->user)
        ->get(route('sites.settings', $this->site))
        ->assertOk()
        // The section shows what is not permitted as plainly as what is.
        ->assertSee('Read status and versions')
        ->assertSee('Take a database backup')
        ->assertSee('Not granted');
});

it('hides a site belonging to another organisation', function (): void {
    $other = Site::factory()->for(Organisation::factory())->create();

    // Route binding resolves on the external identifier alone, so this is what stops one tenant
    // reading another's site by pasting in a ULID.
    $this->actingAs($this->user)->get(route('sites.show', $other))->assertNotFound();
    $this->actingAs($this->user)->get(route('sites.capabilities', $other))->assertNotFound();
});

it('refuses everything once membership is revoked', function (): void {
    $this->user->memberships()->update(['revoked_at' => now()]);

    // Resolved from live membership on every request, so revocation bites immediately rather than
    // whenever the session happens to expire.
    $this->actingAs($this->user)->get('/sites')->assertForbidden();
});

it('requires a recent password confirmation before changing capabilities', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->actingAs($this->user)
        ->post(route('sites.capabilities.revoke', $this->site), ['capability' => 'inventory:read'])
        ->assertRedirect(route('password.confirm'));

    // Nothing changed: a session alone is not enough.
    expect($this->site->fresh()->hasCapability('inventory:read'))->toBeTrue();
});

it('revokes a capability once the password has been confirmed', function (): void {
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.capabilities.revoke', $this->site), ['capability' => 'inventory:read'])
        ->assertRedirect();

    expect($this->site->fresh()->hasCapability('inventory:read'))->toBeFalse();
});

it('revokes a connector and its capabilities together', function (): void {
    $connector = Connector::factory()->for($this->site)->create();
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.connector.revoke', $this->site))
        ->assertRedirect();

    // Credentials and permissions go together. A connector that could still authenticate while
    // holding no capabilities would be a state nobody could reason about.
    expect($connector->fresh()->state)->toBe(Connector::STATE_REVOKED)
        ->and($this->site->fresh()->grantedCapabilities())->toBe([])
        ->and($this->site->fresh()->status)->toBe(Site::STATUS_NOT_CONNECTED);
});

it('confirms a connector that paired from an unexpected domain', function (): void {
    $connector = Connector::factory()->for($this->site)->awaitingConfirmation('moved.example.org')->create();

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('sites.connector.confirm', $this->site))
        ->assertRedirect();

    // Confirming also adopts the domain, so the same mismatch does not resurface next time and
    // train people to click through it.
    expect($connector->fresh()->state)->toBe(Connector::STATE_ACTIVE)
        ->and($this->site->fresh()->expected_domain)->toBe('moved.example.org')
        ->and($this->site->fresh()->grantedCapabilities())->toBe(['inventory:read']);
});

it('shows only this organisation on the activity log', function (): void {
    $mine = app(AuditRecorder::class);

    $mine->record(action: 'site.paired', organisation: $this->organisation, site: $this->site);
    $mine->record(action: 'other.thing', organisation: Organisation::factory()->create());

    $this->actingAs($this->user)
        ->get('/activity')
        ->assertOk()
        ->assertSee('site.paired')
        ->assertDontSee('other.thing');
});

it('renders the account page and offers both kinds of second factor', function (): void {
    $this->actingAs($this->user)
        ->get('/account')
        ->assertOk()
        // Named separately, because "two-factor authentication" covering both an authenticator app
        // and a passkey makes it unclear which one the buttons act on.
        ->assertSee('Authenticator app')
        ->assertSee('Passkeys')
        ->assertSee('Add a passkey')
        ->assertSee('Set up two-factor authentication');
});

it('renders in both themes from one set of templates', function (): void {
    $html = $this->actingAs($this->user)->get('/sites')->getContent();

    // No template names a literal colour: light and dark are one switch, not two sets of markup.
    expect($html)->toContain('data-theme')
        ->and($html)->toContain('data-theme-option="dark"')
        ->and($html)->toContain('data-theme-option="system"');
});
