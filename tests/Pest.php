<?php

declare(strict_types=1);

use App\Models\Site;
use coyshdigital\managerprotocol\CanonicalRequest;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------------------------------
|
| Feature and Invariants tests hit a real Postgres database. The audit log depends on a trigger and
| on revoked table privileges, so testing against SQLite would be testing something other than what
| ships.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------------------------------
| Connector request helpers
|--------------------------------------------------------------------------------------------------
*/

/**
 * Send a signed connector request, exactly as a real connector would.
 *
 * The body is encoded once and both signed and transmitted as those same bytes. Using the
 * framework's postJson() helper would re-encode the payload, so the signature could cover
 * different bytes from the ones that arrive — which is the sort of thing that makes a test pass
 * while production fails.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $overrides  tamper with individual signed fields
 */
function postSignedConnectorRequest(
    string $path,
    array $payload,
    Site $site,
    string $secretKey,
    array $overrides = [],
): TestResponse {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $siteId = $overrides['site_id'] ?? $site->external_id;
    $version = $overrides['connector_version'] ?? '1.0.0';
    $timestamp = $overrides['timestamp'] ?? time();
    $nonce = $overrides['nonce'] ?? Nonce::generate();

    $canonical = new CanonicalRequest(
        siteId: $siteId,
        connectorVersion: $version,
        timestamp: $timestamp,
        nonce: $nonce,
        method: 'POST',
        path: $path,
        body: $body,
    );

    $signature = $overrides['signature'] ?? $canonical->sign($secretKey);

    $headers = [
        Protocol::HEADER_SITE => $siteId,
        Protocol::HEADER_TIMESTAMP => (string) $timestamp,
        Protocol::HEADER_NONCE => $nonce,
        Protocol::HEADER_CONNECTOR_VERSION => $version,
        Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME.'='.$signature,
    ];

    foreach ($overrides['drop_headers'] ?? [] as $drop) {
        unset($headers[$drop]);
    }

    return test()->call(
        method: 'POST',
        uri: $path,
        server: connectorServerHeaders($headers),
        content: $body,
    );
}

/**
 * Point Redis at a closed port for the rest of the test.
 *
 * Used to prove the replay-protection store fails closed. Breaking the real connection exercises
 * the real client and the real error path, which a mock that throws on cue would not.
 */
function breakRedisConnection(): void
{
    foreach (['default', 'cache'] as $connection) {
        config([
            "database.redis.{$connection}.host" => '127.0.0.1',
            "database.redis.{$connection}.port" => 63_999,
        ]);
    }

    // The RedisManager captures its configuration when it is constructed, so editing config() is
    // not enough on its own — the singleton has to go so it is rebuilt from the new values.
    app()->forgetInstance('redis');
    app()->forgetInstance('redis.connection');

    // The cache manager holds its own resolved store, pointing at the old connection.
    app('cache')->forgetDriver('redis');
}

/**
 * Convert header names into the CGI-style keys the test client expects.
 *
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function connectorServerHeaders(array $headers): array
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $server;
}
