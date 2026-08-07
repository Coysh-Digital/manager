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

it('does not call a blank trusted-proxy setting a pass when something terminates TLS in front', function (): void {
    /*
     | Blank is safe against forgery and unsafe for rate limiting, and it reported as a clean pass.
     |
     | With no trusted proxy every caller appears to come from the proxy, so the per-network
     | connector limit and the pairing limit collapse into one bucket shared by the whole fleet -
     | which any unauthenticated caller can then exhaust. The shipped .env.example leaves it blank
     | and the documented deployment puts a proxy in front, so this was the default state.
    */
    config(['manager.trusted_proxies' => '', 'app.url' => 'https://manager.example']);

    expect(checkNamed('Trusted proxies')->warned())->toBeTrue()
        ->and(checkNamed('Trusted proxies')->detail)->toContain('one bucket shared by the whole fleet');
});

it('leaves a blank trusted-proxy setting alone when nothing is in front', function (): void {
    // An installation with no proxy is correctly configured this way, and warning at it would teach
    // people to ignore the warning.
    config(['manager.trusted_proxies' => '', 'app.url' => 'http://localhost']);

    expect(checkNamed('Trusted proxies')->warned())->toBeFalse()
        ->and(checkNamed('Trusted proxies')->failed())->toBeFalse();
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

    // A pass, not a warning. The size is still bounded - by quota, by disk, and by the upload path
    // below - and an operator who has not asked for a ceiling has not misconfigured anything.
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

it('warns when PHP will refuse whole artifacts from a connector too old to send parts', function (): void {
    /*
     | The failure the shipped Docker image had: nginx carved the upload route out of its body limit
     | and php.ini left post_max_size at 2M, so the claim response advertised a ceiling the request
     | path could not carry. A site dumps, encrypts and offers before finding out.
     |
     | A warning rather than a failure since artifacts started arriving in parts, and the demotion is
     | the point rather than a softening. The largest body this platform now receives is one part, so
     | an installation whose post_max_size is above the part size and below the ceiling takes every
     | backup from every connector new enough to send them. What it cannot take is a whole artifact
     | from an older one - which is a real problem, affects a shrinking set of sites, and has an
     | upgrade rather than a configuration change as its remedy. Failing every installation for it
     | would be a permanent red, and this file's own arguments say what happens to those.
     |
     | The unconditional contradiction did not go away; it moved down to the part size, and is
     | asserted below.
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

    expect($check->status)->toBe(Check::WARN)
        ->and($check->detail)->toContain('post_max_size')
        ->and($check->remedy)->toContain('in parts');
});

it('fails when PHP will refuse a single part, which refuses every backup at any size', function (): void {
    /*
     | The stricter half of the check above, and the one that is not conditional on anything.
     |
     | Below the part size nothing can be uploaded at all - not a large database, not a small one, and
     | not by any connector, because every artifact now arrives as at least one part. There is no
     | version of a site that works around this and no size that squeaks under it, so it is stated as
     | a failure rather than as a comparison against a ceiling somebody may never reach.
    */
    $postMax = checkNamed('Upload path ceiling');

    if (str_contains($postMax->detail, 'any size')) {
        expect($postMax->status)->toBe(Check::PASS);

        return;
    }

    // A part larger than any plausible post_max_size on a runner, which is the same trick the test
    // above uses on the ceiling: only one of the two numbers is ours to set.
    config(['manager.backups.ingest_part_bytes' => 1024 ** 4]);

    $check = checkNamed('Upload path ceiling');

    expect($check->failed())->toBeTrue()
        ->and($check->detail)->toContain('post_max_size')
        ->and($check->remedy)->toContain('No backup can be uploaded');
});

it('states the part transfer time it cannot verify a timeout against', function (): void {
    /*
     | The 502 this whole path exists to remove was a timeout, not a body-size refusal, and every
     | runbook in these repositories was written for the latter. php-fpm's request_terminate_timeout
     | is a pool setting rather than an ini one and overrides set_time_limit(0) from outside the
     | process; nginx's fastcgi_read_timeout is further away still. Neither can be read from here.
     |
     | So what this check owes an operator is the arithmetic to check by hand, and an explicit
     | statement that it has not checked. A diagnostic that implied otherwise would be worse than not
     | having one - which is the standing lesson from a CI job that was red long enough that the note
     | explaining it got believed instead of the log.
    */
    $check = checkNamed('Upload path timeout');

    expect($check->failed())->toBeFalse()
        ->and($check->detail)->toContain('request_terminate_timeout')
        ->and($check->detail)->toContain('fastcgi_read_timeout')
        ->and($check->detail)->toContain('cannot confirm');
});

it('names PHP as the effective ceiling when this platform sets none', function (): void {
    config(['manager.backups.max_bytes' => null]);

    $check = checkNamed('Upload path ceiling');

    // Whatever the runner's post_max_size is, this must not fail - an operator who set no ceiling
    // has not misconfigured anything - and it must say what the real limit turned out to be.
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

    // A load balancer has no credentials, so this is necessarily public - which means it must not
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
