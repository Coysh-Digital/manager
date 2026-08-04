<?php

declare(strict_types=1);

use App\Domain\Connector\NonceStore;
use App\Domain\Connector\NonceStoreUnavailableException;
use App\Models\Connector;
use App\Models\Heartbeat;
use App\Models\Site;
use coyshdigital\managerprotocol\CanonicalRequest;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;

/**
 * Invariants 15 and 16, plus the request-signing requirements.
 *
 *  15. Security-sensitive actions must fail closed.
 *  16. Retries must not cause an action to run twice.
 */
beforeEach(function (): void {
    $this->keypair = Keys::generateKeypair();
    $this->site = Site::factory()->create();
    $this->connector = Connector::factory()->for($this->site)->withKeypair($this->keypair)->create();
    $this->path = '/api/connector/v1/heartbeat';
});

it('accepts a correctly signed request', function (): void {
    $response = postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret']);

    $response->assertOk()->assertJson(['received' => true]);

    expect(Heartbeat::query()->count())->toBe(1)
        ->and($this->site->fresh()->status)->toBe(Site::STATUS_CONNECTED);
});

it('returns a correlation identifier on success and on rejection', function (): void {
    // The connector reports this back when something goes wrong, which is what lets a failure be
    // traced through the platform without the connector knowing anything about its internals.
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'])
        ->assertHeader(Protocol::HEADER_CORRELATION_ID);

    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], ['signature' => 'bogus'])
        ->assertHeader(Protocol::HEADER_CORRELATION_ID);
});

it('rejects a replayed request', function (): void {
    // Invariant 16. The nonce is what makes a retry safe: the connector may resend freely, but the
    // same signed request can only ever take effect once.
    $nonce = Nonce::generate();

    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], ['nonce' => $nonce])
        ->assertOk();

    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], ['nonce' => $nonce])
        ->assertUnauthorized();

    expect(Heartbeat::query()->count())->toBe(1);
});

it('rejects a request signed by the wrong key', function (): void {
    $attacker = Keys::generateKeypair();

    postSignedConnectorRequest($this->path, [], $this->site, $attacker['secret'])
        ->assertUnauthorized();

    expect(Heartbeat::query()->count())->toBe(0);
});

it('rejects a request whose body was altered in transit', function (): void {
    $body = json_encode(['tampered' => true], JSON_UNESCAPED_SLASHES);
    $nonce = Nonce::generate();
    $timestamp = time();

    // Signed over an empty body, sent with a different one.
    $signature = (new CanonicalRequest(
        $this->site->external_id, '1.0.0', $timestamp, $nonce, 'POST', $this->path, '{}'
    ))->sign($this->keypair['secret']);

    test()->call('POST', $this->path, server: connectorServerHeaders([
        Protocol::HEADER_SITE => $this->site->external_id,
        Protocol::HEADER_TIMESTAMP => (string) $timestamp,
        Protocol::HEADER_NONCE => $nonce,
        Protocol::HEADER_CONNECTOR_VERSION => '1.0.0',
        Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME.'='.$signature,
    ]), content: $body)->assertUnauthorized();
});

it('rejects a stale or future-dated request', function (int $offset): void {
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'timestamp' => time() + $offset,
    ])->assertUnauthorized();
})->with([
    'far in the past' => [-3600],
    'just outside tolerance' => [-121],
    // A clock running ahead is as much of a problem as one running behind.
    'just outside tolerance, ahead' => [121],
    'far in the future' => [3600],
]);

it('accepts a request within the clock tolerance', function (int $offset): void {
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'timestamp' => time() + $offset,
    ])->assertOk();
})->with([
    'slightly behind' => [-60],
    'slightly ahead' => [60],
]);

it('rejects a request missing any required header', function (string $header): void {
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'drop_headers' => [$header],
    ])->assertUnauthorized();
})->with(Protocol::requiredRequestHeaders());

it('rejects a malformed nonce before it reaches the store', function (string $nonce): void {
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], ['nonce' => $nonce])
        ->assertUnauthorized();
})->with([
    'empty' => [''],
    'key separator' => ['aaaaaaaaaa:aaaaaaaaaa'],
    'traversal' => ['../../../../../etc'],
    'overlong' => [str_repeat('a', 200)],
]);

it('gives nothing away about which site identifiers exist', function (): void {
    // An unknown site and a bad signature must be indistinguishable, or the endpoint becomes an
    // oracle for enumerating sites.
    $unknown = postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'site_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    ]);

    $badSignature = postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'signature' => base64_encode(random_bytes(64)),
    ]);

    expect($unknown->getStatusCode())->toBe($badSignature->getStatusCode());

    // Identical but for the correlation identifier, which is unique per request by design.
    $strip = fn (array $json): array => array_diff_key($json, ['correlation_id' => null]);

    expect($strip($unknown->json()))->toBe($strip($badSignature->json()));
});

it('rejects a request from a revoked connector', function (): void {
    $this->connector->forceFill([
        'state' => Connector::STATE_REVOKED,
        'revoked_at' => now(),
    ])->save();

    // Invariant 14's other half: revocation has to actually stop traffic, not merely be recorded.
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'])
        ->assertUnauthorized();

    expect(Heartbeat::query()->count())->toBe(0);
});

it('rejects an oversized payload before parsing it', function (): void {
    $huge = ['blob' => str_repeat('a', Protocol::MAX_PAYLOAD_BYTES + 1024)];

    postSignedConnectorRequest($this->path, $huge, $this->site, $this->keypair['secret'])
        ->assertStatus(413);
});

it('fails closed when replay protection is unavailable', function (): void {
    // Invariant 15. This is the one place where availability deliberately loses to correctness:
    // without a working replay check, accepting a request means accepting replays of it.
    //
    // Pointed at a genuinely dead port rather than mocked, so this exercises the real client and
    // the real failure, not just our handling of an exception we threw ourselves.
    breakRedisConnection();

    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'])
        ->assertStatus(503);

    expect(Heartbeat::query()->count())->toBe(0);
});

it('surfaces an unreachable store as a dedicated failure, not a silent pass', function (): void {
    breakRedisConnection();

    expect(fn () => app(NonceStore::class)->claim('site-a', Nonce::generate()))
        ->toThrow(NonceStoreUnavailableException::class);
});

it('claims a nonce only once per site', function (): void {
    $store = app(NonceStore::class);
    $nonce = Nonce::generate();

    expect($store->claim('site-a', $nonce))->toBeTrue()
        ->and($store->claim('site-a', $nonce))->toBeFalse()
        // Namespaced per site, so one site cannot exhaust or poison another's nonce space.
        ->and($store->claim('site-b', $nonce))->toBeTrue();
});

it('remembers a nonce for at least as long as its timestamp stays acceptable', function (): void {
    // Otherwise a request could be replayed after its nonce expired but before its timestamp did.
    expect(app(NonceStore::class)->ttl())
        ->toBeGreaterThanOrEqual(2 * (int) config('manager.connector.timestamp_tolerance'));
});

it('records the version a connector is running, not the one it paired on', function (): void {
    // This was recorded at pairing and never again, so a site that upgraded its plugin went on
    // being described by the release it had left behind — on the fleet screens and on every
    // activity line, which is where somebody looks first when deciding whether a version is
    // implicated in a failure.
    $this->connector->forceFill(['connector_version' => '1.8.0'])->save();

    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'connector_version' => '1.12.0',
    ])->assertOk();

    expect($this->connector->fresh()->connector_version)->toBe('1.12.0')
        // The heartbeat row reads through the connector, so it carries the new version too rather
        // than preserving the stale one alongside a fresh timestamp.
        ->and(Heartbeat::query()->latest('id')->first()?->connector_version)->toBe('1.12.0');
});

it('will not record a version from a request that failed to verify', function (): void {
    $this->connector->forceFill(['connector_version' => '1.8.0'])->save();

    // A version is only worth storing because it is signed. Take the signature away and it is an
    // unauthenticated string in a header, which must not reach the column the screens read.
    postSignedConnectorRequest($this->path, [], $this->site, $this->keypair['secret'], [
        'connector_version' => '9.9.9',
        'signature' => 'bogus',
    ])->assertUnauthorized();

    expect($this->connector->fresh()->connector_version)->toBe('1.8.0');
});
