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

        // Stated here rather than inherited from whatever .env this happens to run against. The
        // shipped .env.example leaves APP_URL at the default on purpose, so a test that read it
        // would assert "correctly configured" while running against a deliberately unconfigured one.
        'app.url' => 'https://manager.example.org',
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

/*
|--------------------------------------------------------------------------------------------------
| Size ceilings
|--------------------------------------------------------------------------------------------------
|
| Two checks, and the second exists because the first can be right while backups still fail. The
| ceiling is a policy this application enforces and can explain; the upload path is plumbing that
| refuses first and explains nothing. When they disagree, the plumbing wins silently.
*/

it('states plainly when there is no backup ceiling, rather than reporting a number nobody set', function (): void {
    config(['manager.backups.max_bytes' => null]);

    $check = checkNamed('Backup size ceiling');

    // A pass, not a warning. The size is still bounded — by quota, by disk, and by the upload path
    // below — and an operator who has not asked for a ceiling has not misconfigured anything.
    expect($check->status)->toBe(Check::PASS)
        ->and($check->detail)->toContain('No ceiling');
});

it('fails on a ceiling too small to accept any real database', function (): void {
    config(['manager.backups.max_bytes' => 4096]);

    $check = checkNamed('Backup size ceiling');

    expect($check->failed())->toBeTrue()
        ->and($check->detail)->toContain('4096')
        ->and($check->remedy)->toContain('MANAGER_BACKUP_MAX_BYTES');
});

it('fails when PHP will refuse artifacts this platform has promised to accept', function (): void {
    /*
     | The failure the shipped Docker image had: nginx carved the upload route out of its body limit
     | and php.ini left post_max_size at 2M, so the claim response advertised a ceiling the request
     | path could not carry. A site dumps, encrypts and offers before finding out.
     |
     | Driven off the real ini value rather than a hardcoded one, because this is asserting a
     | relationship between two numbers and only one of them is ours to set.
    */
    $postMax = checkNamed('Upload path ceiling');

    if (str_contains($postMax->detail, 'any size')) {
        // post_max_size is unlimited on this runner, so there is no smaller number to contradict.
        expect($postMax->status)->toBe(Check::PASS);

        return;
    }

    config(['manager.backups.max_bytes' => 1024 ** 4]);

    $check = checkNamed('Upload path ceiling');

    expect($check->failed())->toBeTrue()
        ->and($check->detail)->toContain('post_max_size')
        ->and($check->remedy)->toContain('post_max_size');
});

it('names PHP as the effective ceiling when this platform sets none', function (): void {
    config(['manager.backups.max_bytes' => null]);

    $check = checkNamed('Upload path ceiling');

    // Whatever the runner's post_max_size is, this must not fail — an operator who set no ceiling
    // has not misconfigured anything — and it must say what the real limit turned out to be.
    expect($check->failed())->toBeFalse()
        ->and($check->detail)->toContain(
            str_contains($check->detail, 'any size') ? 'any size' : 'effective backup ceiling'
        );
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
