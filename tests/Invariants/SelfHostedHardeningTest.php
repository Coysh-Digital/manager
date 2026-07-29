<?php

declare(strict_types=1);

use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Keys;

/**
 * Self-hosted hardening.
 *
 * The specification's hardening list is only worth anything if a misconfigured installation is
 * actually caught. These tests are about the checks noticing, not about them existing.
 */
function checkNamed(string $name): Check
{
    foreach (app(Diagnostics::class)->all() as $check) {
        if ($check->name === $name) {
            return $check;
        }
    }

    throw new RuntimeException("No diagnostic named '{$name}'.");
}

it('passes every check on a correctly configured installation', function (): void {
    config([
        'manager.signing.public_key' => ($keypair = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $keypair['secret'],
    ]);

    User::factory()->create();

    $failed = array_filter(app(Diagnostics::class)->all(), fn (Check $check): bool => $check->failed());

    expect(array_map(fn (Check $c): string => $c->name.': '.$c->detail, $failed))->toBe([]);
});

it('fails when the platform cannot sign its own responses', function (): void {
    config(['manager.signing.public_key' => null, 'manager.signing.secret_key' => null]);

    // Otherwise the first connector to need a signed response is what discovers this.
    expect(checkNamed('Platform signing key')->failed())->toBeTrue();
});

it('refuses a replay store that is not shared and atomic', function (string $store): void {
    config(['manager.connector.nonce_store' => $store]);

    // An in-process or non-atomic store lets a captured request through on a second worker, which
    // silently removes replay protection while appearing to work.
    expect(checkNamed('Replay-protection store')->failed())->toBeTrue();
})->with(['array', 'file', 'null']);

it('refuses a wildcard trusted-proxy setting', function (): void {
    config(['manager.trusted_proxies' => '*']);

    // Trusting every proxy lets any caller forge X-Forwarded-For, defeating per-network rate limits
    // and the source addresses in the audit log.
    expect(checkNamed('Trusted proxies')->failed())->toBeTrue();
});

it('reads trusted proxies from config, not the environment', function (): void {
    // env() returns null once the config is cached, so a check reading it from application code
    // would silently pass on exactly the installations that matter.
    config(['manager.trusted_proxies' => '10.0.0.0/8']);

    expect(checkNamed('Trusted proxies')->detail)->toContain('10.0.0.0/8');
});

it('warns while first-run setup is still open', function (): void {
    User::query()->delete();

    // While it is open, anyone who can reach the installation can create the first owner.
    expect(checkNamed('First-run setup')->warned())->toBeTrue();

    User::factory()->create();

    expect(checkNamed('First-run setup')->status)->toBe(Check::PASS);
});

it('notices if the audit log loses its protection', function (): void {
    DB::unprepared('DROP TRIGGER IF EXISTS audit_events_reject_mutation ON audit_events');

    $check = checkNamed('Audit log protection');

    expect($check->failed())->toBeTrue()
        ->and($check->detail)->toContain('audit_events_reject_mutation');
});

it('reports readiness narrowly', function (): void {
    // Readiness is polled by orchestrators. A probe that failed on an unverified mail
    // configuration would take a working instance out of rotation for no good reason.
    $names = array_map(fn (Check $c): string => $c->name, app(Diagnostics::class)->readiness());

    expect($names)->toBe(['Database', 'Migrations', 'Replay-protection store', 'Storage'])
        ->and($names)->not->toContain('Optional diagnostics');
});

it('serves readiness without authentication and without leaking configuration', function (): void {
    $response = $this->get('/ready');

    $response->assertOk()->assertJsonPath('ready', true);

    // A load balancer has no credentials, so this is necessarily public — which means it must not
    // become a way to read the environment.
    $body = $response->getContent();

    expect($body)->not->toContain(config('app.key'))
        ->and($body)->not->toContain((string) config('database.connections.pgsql.password'))
        ->and($body)->not->toContain('pgsql');
});

it('reports not ready when replay protection is unreachable', function (): void {
    breakRedisConnection();

    $this->get('/ready')
        ->assertStatus(503)
        ->assertJsonPath('ready', false);
});

it('rejects a connector cleanly when the shared store is unreachable', function (): void {
    $keypair = Keys::generateKeypair();
    $site = Site::factory()->create();
    Connector::factory()->for($site)->withKeypair($keypair)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();

    breakRedisConnection();

    // Fails closed, but as a 503 carrying a correlation identifier rather than an unhandled 500.
    // The rate limiter uses the same store as replay protection, so it fails first; letting that
    // throw told the connector nothing and left no traceable record.
    $response = postSignedConnectorRequest('/api/connector/v1/heartbeat', [], $site, $keypair['secret']);

    $response->assertStatus(503);

    expect($response->json('correlation_id'))->not->toBeEmpty()
        ->and($response->headers->get('Manager-Correlation-Id'))->not->toBeEmpty();
});

it('marks the session cookie secure whenever the canonical URL is HTTPS', function (): void {
    // Secure by default rather than relying on somebody remembering the variable.
    expect(config('session.secure'))->toBe(str_starts_with((string) config('app.url'), 'https://'));
});

it('exposes liveness and readiness as separate questions', function (): void {
    // /up answers "is PHP running". /ready answers "can this instance serve a request". Conflating
    // them means either restarting healthy containers or routing traffic to broken ones.
    $this->get('/up')->assertOk();
    $this->get('/ready')->assertOk()->assertJsonStructure(['ready', 'checks']);
});
