<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Capability\UnknownCapabilityException;
use App\Domain\Connector\NudgeDispatcher;
use App\Domain\Connector\NudgePath;
use App\Domain\Job\JobRegistry;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Invariants 4, 5, 9, 10 and 14.
 *
 *   4. The connector must not expose a public inbound endpoint that can carry an instruction.
 *   5. Every exchange that decides anything is initiated outbound by the connector.
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
        // with a required capability, a parameter schema and an audit description - not a route
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
    //   system          disk usage, PHP limits, sampled response timings, requires runtime:read
    //   logins          counts of failed control-panel sign-ins, requires logins:read
    //   jobs/claim      asks what to do; the response is signed because it carries instructions
    //   jobs/*/result   reports how a job went; validated against the job's result schema
    //   backups         declares an artifact about to be uploaded, requires backups:create
    //   backups/progress   which phase a backup has reached; a bounded enum and a timestamp, and
    //                      nothing on the platform acts on it
    //   backups/*/uploaded   says an artifact went straight to storage; carries nothing but the fact,
    //                        and the platform asks storage rather than believing it
    //   backups/*/content  the artifact bytes; authenticated before the body is read at all
    //   backups/*/content/{part}   the same bytes, one bounded piece at a time, so that no single
    //                              request has to outlive a proxy's patience. The part number is in
    //                              the path and the signature covers the path, so a captured part
    //                              cannot be replayed at a different offset.
    //   backups/*/assembled   says every part has been sent; carries nothing, and unlike `uploaded`
    //                         the platform can check for itself because it is holding the bytes
    //
    // Note what is not here: nothing the platform can push, and nothing that takes a command. Every
    // one of these is a connector posting something it decided to send - the backup routes included,
    // since the platform never asks a site for an artifact, it queues a job and waits to be sent one.
    expect($connectorRoutes)->toBe([
        'api/connector/v1/backups',
        'api/connector/v1/backups/progress',
        'api/connector/v1/backups/{artifactId}/assembled',
        'api/connector/v1/backups/{artifactId}/content',
        'api/connector/v1/backups/{artifactId}/content/{part}',
        'api/connector/v1/backups/{artifactId}/uploaded',
        'api/connector/v1/heartbeat',
        'api/connector/v1/inventory',
        'api/connector/v1/jobs/claim',
        'api/connector/v1/jobs/{job}/result',
        'api/connector/v1/logins',
        'api/connector/v1/pair',
        'api/connector/v1/system',
        'api/connector/v1/updates',
    ]);
});

it('holds no site address it could be told to call', function (): void {
    /*
     | Invariants 4 and 5, as they now stand.
     |
     | This used to assert that the platform never calls out to a site at all. It does now: a nudge
     | asks a site to check in sooner than its own schedule would. What has *not* changed, and what
     | these assertions exist to keep true, is that the platform holds no address it could be told to
     | call. The host is always the domain an operator typed; only a path comes from the wire.
     |
     | A column here for a URL, a host or a port would undo the whole design in one migration, so the
     | absence is asserted rather than assumed - on both tables, because the second one is new.
    */
    $sites = Schema::getColumnListing('sites');
    $connectors = Schema::getColumnListing('connectors');

    expect($sites)->toContain('expected_domain');

    foreach (['callback_url', 'api_url', 'webhook_url', 'nudge_url', 'base_url', 'host', 'endpoint'] as $address) {
        expect($sites)->not->toContain($address)
            ->and($connectors)->not->toContain($address);
    }

    // The path is the only thing the wire may contribute, and it is bounded.
    expect($connectors)->toContain('nudge_path');
});

it('refuses anything shaped like a destination as a nudge path', function (string $hostile): void {
    // Attacker-controlled in the case that matters - a compromised or impersonated connector. Every
    // one of these is refused outright rather than repaired, because a sanitiser that strips and
    // continues is one that can be fooled by an input carrying two of something.
    expect(NudgePath::sanitise($hostile))->toBeNull();
})->with([
    'absolute url' => 'https://evil.example/x',
    'protocol relative' => '//evil.example/x',
    'metadata service' => 'http://169.254.169.254/',
    'credentials' => 'https://user:pass@evil.example/x',
    'fragment' => '/x?a=b#frag',
    'traversal' => '/../../etc/passwd',
    'header injection' => "/x\r\nHost: evil.example",
    'newline' => "/x\ny",
    'null byte' => "/x\0y",
    'backslash' => '/x\\..\\y',
    'relative' => 'actions/manager-connector/nudge/poll',
    'empty' => '',
    'too long' => '/'.str_repeat('a', 300),
]);

it('keeps the paths a real Craft site actually answers on', function (string $legitimate): void {
    // Refusing these would refuse ordinary configurations, which is how a check like this ends up
    // switched off rather than fixed.
    expect(NudgePath::sanitise($legitimate))->toBe($legitimate);
})->with([
    'plain action url' => '/actions/manager-connector/nudge/poll',
    'subfolder install' => '/site/actions/manager-connector/nudge/poll',
    'script name in urls' => '/index.php?p=actions/manager-connector/nudge/poll',
    'renamed action trigger' => '/go/manager-connector/nudge/poll',
]);

it('composes a nudge destination from the operator domain, never from the wire', function (): void {
    $site = Site::factory()->connected()->create(['expected_domain' => 'real.example']);

    // The connector reports an absolute URL naming somewhere else entirely. The host must come from
    // expected_domain regardless, and here the path is not even a path, so there is nothing to send.
    Connector::factory()->for($site)->create([
        'nudge_path' => 'https://evil.example/x',
        'submitted_domain' => 'real.example',
    ]);

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))->toBeNull();

    $site->activeConnector()->first()->forceFill([
        'nudge_path' => '/actions/manager-connector/nudge/poll',
    ])->save();

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))
        ->toBe('https://real.example/actions/manager-connector/nudge/poll');
});

it('will not nudge a site whose connector paired from a different domain', function (): void {
    // An operator can approve a domain mismatch at pairing. When they have, expected_domain is not
    // where the site answers - so a signed nudge naming this site would go to a host that is not it.
    $site = Site::factory()->connected()->create(['expected_domain' => 'real.example']);

    Connector::factory()->for($site)->create([
        'nudge_path' => '/actions/manager-connector/nudge/poll',
        'submitted_domain' => 'somewhere.else',
    ]);

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))->toBeNull();
});

it('gives up on a site that never answers, rather than knocking forever', function (): void {
    $site = Site::factory()->connected()->create(['expected_domain' => 'real.example']);

    $connector = Connector::factory()->for($site)->create([
        'nudge_path' => '/actions/manager-connector/nudge/poll',
        'submitted_domain' => 'real.example',
        'nudge_failures' => NudgeDispatcher::FAILURE_CEILING,
    ]);

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))->toBeNull();

    // And heals the moment the site restates its path on a claim.
    $connector->forceFill(['nudge_failures' => 0])->save();

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))->not->toBeNull();
});

it('can be told to make no outbound request to a site at all', function (): void {
    config(['manager.connector.nudge.enabled' => false]);

    $site = Site::factory()->connected()->create(['expected_domain' => 'real.example']);
    Connector::factory()->for($site)->create([
        'nudge_path' => '/actions/manager-connector/nudge/poll',
        'submitted_domain' => 'real.example',
    ]);

    expect(app(NudgeDispatcher::class)->destinationFor($site->fresh()))->toBeNull();
});

it('takes a site and nothing else when it dispatches a nudge', function (): void {
    // The exact mirror of the connector's own check on uploadHostFor(), and for the same reason a
    // comment there gives: a signature gaining a second parameter is how this would be undone. A
    // `dispatch(Site $site, string $url)` is one refactor away from a destination arriving from
    // somewhere that has not been thought about.
    $method = new ReflectionMethod(NudgeDispatcher::class, 'dispatch');

    expect($method->getNumberOfParameters())->toBe(1)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(Site::class);
});

it('composes a site-facing address in only the two reviewed places', function (): void {
    /*
     | Two, and both are here because somebody decided they should be:
     |
     |   CertificateInspector   reads the certificate a visitor would validate, which the connector
     |                          genuinely cannot see because TLS terminates at the edge
     |   NudgeDispatcher        asks a site to check in now
     |
     | Both build the address from `sites.expected_domain` and hand it to OutboundUrlGuard. A third
     | file appearing here is a third place to review whenever that rule changes, and the one nobody
     | remembers - so it fails until somebody adds it deliberately.
    */
    $permitted = [
        'Domain/Connector/NudgeDispatcher.php',
        'Domain/Security/CertificateInspector.php',
    ];

    $found = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match("/'https:\/\/'\s*\.\s*\\\$/", (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    sort($found);

    expect($found)->toBe($permitted, 'Composes a URL from a variable host: '.implode(', ', $found));
});

it('cannot turn a nudge into a channel for anything else', function (): void {
    // A nudge says "check in". If this class knew about jobs it could start saying which one, and the
    // guarantee that the worst outcome is an early poll would quietly stop holding.
    $source = (string) file_get_contents(app_path('Domain/Connector/NudgeDispatcher.php'));

    foreach (['RemoteJob', 'JobService', 'JobRegistry', 'JobDefinition'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    foreach (app(JobRegistry::class)->types() as $type) {
        expect($source)->not->toContain($type);
    }
});

it('sends a nudge with no body and nothing but the four signed headers', function (): void {
    // The canonical string covers four fields and no body. Anything else travelling would be
    // unsigned material the receiving site had been handed.
    $source = (string) file_get_contents(app_path('Domain/Connector/NudgeDispatcher.php'));

    expect($source)->toContain("'body' => ''")
        ->and(Protocol::nudgeHeaders())->toBe([
            Protocol::HEADER_SITE,
            Protocol::HEADER_TIMESTAMP,
            Protocol::HEADER_NONCE,
            Protocol::HEADER_SIGNATURE,
        ]);

    // The transport posture a hostile destination is assumed to deserve, same as an outbound webhook.
    foreach ([
        "'allow_redirects' => false",
        "'sink' => '/dev/null'",
        'CURLOPT_FOLLOWLOCATION => false',
        'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS',
        'CURLOPT_RESOLVE',
    ] as $posture) {
        expect($source)->toContain($posture);
    }
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
