<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Capability\UnknownCapabilityException;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Invariants 4, 5, 9, 10 and 14.
 *
 *   4. The connector must not expose a public inbound management endpoint.
 *   5. Connections must be initiated outbound by the connector.
 *   9. Remote jobs must use a fixed allowlist of versioned job types.
 *  10. Every remote job must be authenticated, authorised, validated and audited.
 *  14. Removing a site must immediately revoke its credentials.
 *
 * Phase 1 has no remote jobs at all, so 9 and 10 are tested here as the absence of any execution
 * surface. That is the more useful test while none exists: it fails the moment somebody adds a way
 * to run something on a site without going through a registry.
 */
it('offers no route that could execute anything on a managed site', function (): void {
    $suspicious = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        // Anything that reads like a command channel. Phase 2 introduces jobs through a registry
        // with a required capability, a parameter schema and an audit description — not a route
        // that takes a command.
        foreach (['exec', 'command', 'console', 'shell', 'eval', 'query', 'sql', 'run', 'invoke'] as $word) {
            if (str_contains($uri, $word)) {
                $suspicious[] = $route->methods()[0].' '.$uri;
            }
        }
    }

    expect($suspicious)->toBe([], 'Unexpected execution-shaped routes: '.implode(', ', $suspicious));
});

it('exposes only reporting endpoints to connectors', function (): void {
    $connectorRoutes = [];

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/connector/')) {
            $connectorRoutes[] = $route->uri();
        }
    }

    sort($connectorRoutes);

    // The entire surface a connector can reach. Adding to this list is a deliberate act, and this
    // test is where it has to be justified:
    //
    //   pair            the one unsigned route, authenticated by a single-use enrolment code
    //   heartbeat       liveness, no capability, no data
    //   inventory       operational metadata, requires inventory:read
    //   updates         update availability, requires updates:read
    //   jobs/claim      asks what to do; the response is signed because it carries instructions
    //   jobs/*/result   reports how a job went; validated against the job's result schema
    //   backups         declares an artifact about to be uploaded, requires backups:create
    //   backups/*/content  the artifact bytes; authenticated before the body is read at all
    //
    // Note what is not here: nothing the platform can push, and nothing that takes a command. The two
    // backup routes are both connector-initiated uploads — the platform never asks a site for an
    // artifact, it queues a job and waits to be sent one.
    expect($connectorRoutes)->toBe([
        'api/connector/v1/backups',
        'api/connector/v1/backups/{artifactId}/content',
        'api/connector/v1/heartbeat',
        'api/connector/v1/inventory',
        'api/connector/v1/jobs/claim',
        'api/connector/v1/jobs/{job}/result',
        'api/connector/v1/pair',
        'api/connector/v1/updates',
    ]);
});

it('never calls out to a managed site', function (): void {
    // Invariants 4 and 5. The platform holds no site URL it could call: a site's domain is recorded
    // only to compare against what a connector reports when pairing. If the platform ever needed to
    // initiate a connection, connectors would need an inbound endpoint, and every managed site would
    // need a firewall hole.
    $columns = Schema::getColumnListing('sites');

    expect($columns)->toContain('expected_domain')
        ->and($columns)->not->toContain('callback_url')
        ->and($columns)->not->toContain('api_url')
        ->and($columns)->not->toContain('webhook_url');
});

it('registers only read capabilities as automatically grantable', function (): void {
    foreach (Protocol::autoGrantableCapabilities() as $capability) {
        expect($capability)->toEndWith(':read');
    }

    // Anything that writes to a site, or reads its content, needs a separate confirmation.
    expect(Protocol::autoGrantableCapabilities())->not->toContain('backups:create');
});

it('rejects a capability the platform does not define', function (): void {
    $site = Site::factory()->create();

    // The registry is a closed set, so a typo fails loudly instead of creating a permission nobody
    // ever reviews.
    expect(fn () => app(CapabilityService::class)
        ->revoke($site, 'shell:execute', null, 'test'))
        ->toThrow(UnknownCapabilityException::class);
});

it('revokes credentials in the same transaction as removing a site', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($user)->for($organisation)->owner()->create();

    $keypair = Keys::generateKeypair();
    $site = Site::factory()->for($organisation)->connected()->create(['expected_domain' => 'example.org']);
    Connector::factory()->for($site)->withKeypair($keypair)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();
    $code = EnrolmentCode::factory()->for($site)->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('sites.destroy', $site), ['confirm_domain' => 'example.org'])
        ->assertRedirect(route('sites.index'));

    $site->refresh();

    expect($site->archived_at)->not->toBeNull()
        ->and($site->activeConnector()->first())->toBeNull()
        ->and($site->grantedCapabilities())->toBe([])
        // An unconsumed code left behind would be a way back in to a site nobody is watching.
        ->and($code->fresh()->isConsumed())->toBeTrue();

    // And the credentials genuinely stop working, not merely appear revoked.
    postSignedConnectorRequest('/api/connector/v1/heartbeat', [], $site, $keypair['secret'])
        ->assertUnauthorized();
});

it('requires the domain to be typed before removing a site', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($user)->for($organisation)->owner()->create();

    $site = Site::factory()->for($organisation)->connected()->create(['expected_domain' => 'example.org']);
    Connector::factory()->for($site)->create();

    // A dialog people click through is not a confirmation.
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('sites.destroy', $site), ['confirm_domain' => 'wrong.example'])
        ->assertSessionHasErrors('confirm_domain');

    expect($site->fresh()->archived_at)->toBeNull()
        ->and($site->fresh()->activeConnector()->first())->not->toBeNull();
});

it('requires recent authentication before removing a site', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($user)->for($organisation)->owner()->create();

    $site = Site::factory()->for($organisation)->create(['expected_domain' => 'example.org']);

    $this->actingAs($user)
        ->delete(route('sites.destroy', $site), ['confirm_domain' => 'example.org'])
        ->assertRedirect(route('password.confirm'));

    expect($site->fresh()->archived_at)->toBeNull();
});

it('will not let a member remove a site', function (): void {
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($user)->for($organisation)->create(['role' => Membership::ROLE_MEMBER]);

    $site = Site::factory()->for($organisation)->create(['expected_domain' => 'example.org']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('sites.destroy', $site), ['confirm_domain' => 'example.org'])
        ->assertForbidden();

    expect($site->fresh()->archived_at)->toBeNull();
});
