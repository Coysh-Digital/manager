<?php

declare(strict_types=1);

use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Site;
use coyshdigital\managerprotocol\Keys;
use Illuminate\Support\Facades\Route;

/**
 * An unhandled failure is still traceable.
 *
 * Every rejection the platform composes itself carries a correlation identifier - the signature
 * middleware, the capability middleware and each connector controller put one in the body and in the
 * Manager-Correlation-Id header. An unhandled exception carried neither, because it never reaches
 * any of that code.
 *
 * The cost was reported from a live site: a backup failing with
 *
 *     RuntimeException: The platform rejected the request (HTTP 500). Correlation ID: unknown
 *
 * "unknown" is the connector saying the body had no identifier, so there was nothing to search the
 * platform log for. The one failure that most needed tracing was the only one that could not be.
 */
beforeEach(function (): void {
    $this->keypair = Keys::generateKeypair();
    $this->site = Site::factory()->create();
    Connector::factory()->for($this->site)->withKeypair($this->keypair)->create();
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    /*
     | A connector route that throws once every piece of middleware has passed - signature verified,
     | site resolved - which is where the real fault was: BackupDeclareController catches only
     | BackupRejectedException, so anything else escapes as a 500.
     |
     | Registered here rather than mocking a service, so the request travels the whole genuine
     | pipeline. The path is signed by the caller, so it has to exist before the request is made.
    */
    $this->breakTheRoute = function (string $message = 'Something nobody planned for'): void {
        Route::prefix('api/connector/v1')
            ->middleware(['connector', 'connector.signed'])
            ->post('explode', function () use ($message): never {
                throw new RuntimeException($message);
            });
    };
});

it('carries a correlation identifier on an unhandled error', function (): void {
    ($this->breakTheRoute)();

    $response = postSignedConnectorRequest('/api/connector/v1/explode', [], $this->site, $this->keypair['secret']);

    $response->assertStatus(500);

    $body = $response->json('correlation_id');
    $header = $response->headers->get('Manager-Correlation-Id');

    expect($body)->not->toBeEmpty()
        ->and($header)->not->toBeEmpty()
        // The two must agree, or an operator handed one of them searches the log for the other.
        ->and($body)->toBe($header);
});

it('does not put the exception on the wire', function (): void {
    // The connector belongs to somebody else's Craft install. What went wrong inside the platform is
    // for the platform's log; the identifier is the whole of what the other side needs.
    config()->set('app.debug', false);

    ($this->breakTheRoute)('SENTINEL-INTERNAL-DETAIL');

    $response = postSignedConnectorRequest('/api/connector/v1/explode', [], $this->site, $this->keypair['secret']);

    $response->assertStatus(500);

    expect($response->getContent())->not->toContain('SENTINEL-INTERNAL-DETAIL')
        ->and($response->getContent())->not->toContain('RuntimeException');
});

it('leaves a response that already carries an identifier alone', function (): void {
    // A handled rejection has already chosen its identifier and put it in both places. Overwriting
    // it would make the response disagree with the audit row describing the same event.
    $response = postSignedConnectorRequest(
        '/api/connector/v1/heartbeat',
        [],
        $this->site,
        $this->keypair['secret'],
        ['timestamp' => time() - 3600],
    );

    $response->assertStatus(401);

    expect($response->json('correlation_id'))->not->toBeEmpty()
        ->and($response->json('correlation_id'))->toBe($response->headers->get('Manager-Correlation-Id'));
});

it('logs the identifier with the failure', function (): void {
    /*
     | Without this the identifier is a number the connector reports and the platform never wrote
     | down - which is worse than not having one, because it looks traceable and is not.
     |
     | A recording logger bound into the container rather than Log::spy(): the handler resolves its
     | logger through the `log` binding, and asserting against the real resolution path is the point
     | of the test.
    */
    $recorder = new class
    {
        /** @var array<string, mixed> */
        public array $context = [];

        /** @param array<int, mixed> $arguments */
        public function __call(string $method, array $arguments): void
        {
            $this->context = $arguments[2] ?? [];
        }
    };

    app()->bind('log', fn (): object => $recorder);

    ($this->breakTheRoute)();

    $response = postSignedConnectorRequest('/api/connector/v1/explode', [], $this->site, $this->keypair['secret']);

    expect($recorder->context['correlation_id'] ?? null)->not->toBeEmpty();
    expect($recorder->context['correlation_id'])->toBe($response->json('correlation_id'));
});
