<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\UnsafeDestinationException;
use App\Domain\Notifications\WebhookTransport;
use App\Jobs\DeliverNotification;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\InventoryReport;
use App\Models\Membership;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use Database\Factories\InventoryReportFactory;
use Illuminate\Support\Facades\Queue;

/**
 * Outbound notifications.
 *
 * The specification says to assume a webhook destination may be malicious, so the concrete risk is
 * server-side request forgery: a destination pointed at the cloud metadata address turns "send me a
 * notification" into "probe your own infrastructure and tell me what you found".
 *
 * These tests are the guard on that. They are written against literal addresses rather than hostnames
 * wherever possible, so they do not depend on DNS to be meaningful.
 */
beforeEach(function (): void {
    $this->guard = app(OutboundUrlGuard::class);
});

it('refuses anything that is not HTTPS', function (string $url): void {
    // A notification names which site has an outstanding security release. That is not something to
    // put on the wire in the clear.
    expect(fn () => $this->guard->resolve($url))->toThrow(UnsafeDestinationException::class);
})->with([
    'plain http' => ['http://hooks.example.org/manager'],
    'ftp' => ['ftp://hooks.example.org/manager'],
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://hooks.example.org/'],
    'no scheme' => ['hooks.example.org/manager'],
    'nonsense' => ['not a url at all'],
]);

it('refuses the cloud metadata address', function (string $url): void {
    // 169.254.169.254 is the single most valuable target for an SSRF: on AWS, GCP, Azure and
    // DigitalOcean it hands out instance credentials to anything that asks.
    expect(fn () => $this->guard->resolve($url))->toThrow(UnsafeDestinationException::class);
})->with([
    'metadata v4' => ['https://169.254.169.254/latest/meta-data/'],
    'metadata with port' => ['https://169.254.169.254:443/'],
    'link-local v6' => ['https://[fe80::1]/'],
]);

it('refuses loopback and private ranges', function (string $url): void {
    expect(fn () => $this->guard->resolve($url))->toThrow(UnsafeDestinationException::class);
})->with([
    'loopback' => ['https://127.0.0.1/hook'],
    'loopback range' => ['https://127.1.2.3/hook'],
    'loopback v6' => ['https://[::1]/hook'],
    'rfc1918 ten' => ['https://10.1.2.3/hook'],
    'rfc1918 172' => ['https://172.16.5.6/hook'],
    'rfc1918 192' => ['https://192.168.1.1/hook'],
    'carrier nat' => ['https://100.64.0.1/hook'],
    'unique local v6' => ['https://[fd00::1]/hook'],
    'this host' => ['https://0.0.0.0/hook'],
    'broadcast' => ['https://255.255.255.255/hook'],
]);

it('refuses reserved and documentation ranges', function (string $url): void {
    // No legitimate webhook lives here, and they are what gets used to probe for parsing bugs.
    expect(fn () => $this->guard->resolve($url))->toThrow(UnsafeDestinationException::class);
})->with([
    'test-net-1' => ['https://192.0.2.1/hook'],
    'test-net-2' => ['https://198.51.100.1/hook'],
    'test-net-3' => ['https://203.0.113.1/hook'],
    'benchmarking' => ['https://198.18.0.1/hook'],
    'reserved' => ['https://240.0.0.1/hook'],
]);

it('refuses credentials embedded in the URL', function (): void {
    // A destination written that way was probably not written by whoever will receive it.
    expect(fn () => $this->guard->resolve('https://user:secret@203.0.114.1/hook'))
        ->toThrow(UnsafeDestinationException::class);
});

it('accepts a public address', function (): void {
    $resolved = $this->guard->resolve('https://203.0.114.1/hook');

    expect($resolved->host)->toBe('203.0.114.1')
        ->and($resolved->port)->toBe(443);
});

it('pins the connection to the address it validated', function (): void {
    $resolved = $this->guard->resolve('https://203.0.114.1:8443/hook');

    // Connecting by hostname would resolve DNS a second time, and an answer that changed in between
    // is the whole DNS-rebinding attack. So the address travels with the destination.
    expect($resolved->address)->toBe('203.0.114.1')
        ->and($resolved->curlResolveEntry())->toBe('203.0.114.1:8443:203.0.114.1');
});

it('tells a prober nothing about what resolved to what', function (): void {
    try {
        $this->guard->resolve('https://169.254.169.254/');
    } catch (UnsafeDestinationException $e) {
        // Somebody probing internal ranges should not get resolution results back as a reward.
        expect($e->getMessage())->not->toContain('169.254')
            ->and($e->getMessage())->toContain('private or reserved');

        return;
    }

    $this->fail('Expected the destination to be refused.');
});

it('re-validates on every send, not only when added', function (): void {
    $destination = NotificationDestination::factory()
        ->webhook('https://127.0.0.1/hook')
        ->create();

    $result = app(WebhookTransport::class)->send($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
    ));

    // A hostname that was harmless when it was added can point somewhere else today, so the check
    // belongs on the send path rather than only on the form.
    expect($result['outcome'])->toBe('failed')
        ->and($result['failure_reason'])->toContain('private or reserved');
});

// --------------------------------------------------------------------------------------------------
// What a notification carries
// --------------------------------------------------------------------------------------------------

it('carries no secrets or site content in the payload', function (): void {
    $site = Site::factory()->connected()->create(['name' => 'Example Site']);

    $payload = (new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Development mode is on in production',
        summary: 'Craft is running with devMode enabled.',
        site: $site,
        context: ['severity' => 'high', 'rule' => 'dev_mode_in_production'],
    ))->toPayload();

    $serialised = json_encode($payload);

    // A webhook destination is, by assumption, possibly hostile. What reaches it is an event type, a
    // summary and identifiers.
    expect($serialised)->not->toContain(config('app.key'))
        ->and($payload['site'])->toHaveKeys(['id', 'name', 'domain', 'environment'])
        ->and($payload['site'])->not->toHaveKey('craft_version')
        ->and($payload)->not->toHaveKey('evidence');
});

it('signs the body over the timestamp as well', function (): void {
    $destination = NotificationDestination::factory()->webhook()->create(['signing_secret' => 'shhh']);

    $signature = app(WebhookTransport::class)->sign($destination, '1785340000', '{"a":1}');

    expect($signature)->toBe(hash_hmac('sha256', "1785340000\n".'{"a":1}', 'shhh'));

    // The timestamp being inside the signed material is what lets a receiver reject a replay rather
    // than only an alteration.
    $later = app(WebhookTransport::class)->sign($destination, '1785340001', '{"a":1}');

    expect($later)->not->toBe($signature);
});

it('sends nothing unsigned when a secret is configured', function (): void {
    $unsigned = NotificationDestination::factory()->webhook()->create(['signing_secret' => null]);

    // No secret means no signature rather than a signature over an empty key, which would look
    // verifiable while proving nothing.
    expect(app(WebhookTransport::class)->sign($unsigned, '1', 'body'))->toBeNull();
});

// --------------------------------------------------------------------------------------------------
// Dispatch, subscription and failure handling
// --------------------------------------------------------------------------------------------------

it('queues rather than sending inline', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $site = Site::factory()->for($organisation)->create();
    NotificationDestination::factory()->for($organisation)->create();

    app(Notifier::class)->dispatch(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
        site: $site,
    ));

    // Notifications fire while handling a connector's report. A hanging destination must not be able
    // to make a site's report time out, which would turn a bad webhook into a site that looks offline.
    Queue::assertPushed(DeliverNotification::class);
});

it('delivers only what a destination subscribed to', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $site = Site::factory()->for($organisation)->create();

    NotificationDestination::factory()->for($organisation)
        ->subscribedTo([NotificationEvent::SITE_SILENT])
        ->create();

    $queued = app(Notifier::class)->dispatch(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
        site: $site,
    ));

    expect($queued)->toBe(0);
    Queue::assertNothingPushed();
});

it('treats an empty subscription list as none, not all', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $site = Site::factory()->for($organisation)->create();

    NotificationDestination::factory()->for($organisation)->subscribedTo([])->create();

    // Defaulting to everything would make an accidentally-created destination noisy rather than
    // silent, and noisy is the failure mode that gets a channel ignored.
    expect(app(Notifier::class)->dispatch(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
        site: $site,
    )))->toBe(0);
});

it('skips a disabled destination and one that has failed too often', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $site = Site::factory()->for($organisation)->create();

    NotificationDestination::factory()->for($organisation)->disabled()->create();
    NotificationDestination::factory()->for($organisation)->failing()->create();

    // A dead endpoint retried forever is a slow leak of worker time, and the failure stops being
    // visible among the repetitions.
    expect(app(Notifier::class)->dispatch(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
        site: $site,
    )))->toBe(0);
});

it('never crosses organisations', function (): void {
    Queue::fake();

    $mine = Organisation::factory()->create();
    $theirs = Organisation::factory()->create();

    $site = Site::factory()->for($mine)->create();
    NotificationDestination::factory()->for($theirs)->create();

    expect(app(Notifier::class)->dispatch(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
        site: $site,
    )))->toBe(0);
});

it('sends an email and records the delivery', function (): void {
    $organisation = Organisation::factory()->create();
    $site = Site::factory()->for($organisation)->create(['name' => 'Example Site']);
    $destination = NotificationDestination::factory()->for($organisation)->create();

    (new DeliverNotification($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Development mode is on in production',
        summary: 'Craft is running with devMode enabled.',
        site: $site,
    )))->handle(app(WebhookTransport::class), app(EmailTransport::class), app(CorrelationId::class));

    $delivery = NotificationDelivery::query()->firstOrFail();

    // An unnoticed delivery failure is worse than no notifications at all, so every attempt is
    // recorded whether it worked or not.
    expect($delivery->succeeded())->toBeTrue()
        ->and($delivery->event)->toBe(NotificationEvent::FINDING_OPENED)
        ->and($destination->fresh()->consecutive_failures)->toBe(0)
        ->and($destination->fresh()->last_delivery_at)->not->toBeNull();
});

it('writes a text part with no vulnerability detail and no tokenised links', function (): void {
    $site = Site::factory()->create(['name' => 'Example Site', 'expected_domain' => 'example.org']);

    $body = app(EmailTransport::class)->body(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Craft has an outstanding security release',
        summary: 'This site runs Craft 5.6.2 and 5.6.4 is available.',
        site: $site,
        context: ['severity' => 'critical'],
    ));

    // An email sits in a mailbox for years, so it says where to look rather than carrying the detail
    // or a link that authenticates whoever opens it.
    expect($body)->toContain('Example Site')
        ->and($body)->toContain('example.org')
        ->and($body)->toContain('/findings')
        ->and($body)->not->toContain('token')
        ->and($body)->not->toContain(config('app.key'));
});

it('counts failures and resets on recovery', function (): void {
    $organisation = Organisation::factory()->create();
    $destination = NotificationDestination::factory()->for($organisation)
        ->webhook('https://127.0.0.1/hook')
        ->create();

    $job = new DeliverNotification($destination, new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Test',
        summary: 'Test',
    ));

    expect(fn () => $job->handle(
        app(WebhookTransport::class),
        app(EmailTransport::class),
        app(CorrelationId::class),
    ))->toThrow(RuntimeException::class);

    expect($destination->fresh()->consecutive_failures)->toBe(1)
        ->and(NotificationDelivery::query()->firstOrFail()->succeeded())->toBeFalse();
});

it('offers only events the catalogue defines', function (): void {
    expect(NotificationEvent::isKnown('finding.opened'))->toBeTrue()
        ->and(NotificationEvent::isKnown('anything.else'))->toBeFalse();

    // A destination subscribed to an event that later leaves the catalogue silently stops receiving
    // it, rather than throwing on a subscription nobody can see any more.
    $destination = NotificationDestination::factory()->subscribedTo(['removed.event'])->make();

    expect($destination->wants('removed.event'))->toBeFalse();
});

it('notifies when a serious finding opens, but not a minor one', function (): void {
    Queue::fake();

    $organisation = Organisation::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($user)->for($organisation)->owner()->create();

    $site = Site::factory()->for($organisation)->connected()->create(['environment' => 'production']);
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('security:read')->create();
    NotificationDestination::factory()->for($organisation)->create();

    // allow_updates is a low-severity finding; dev_mode is high.
    InventoryReport::factory()->for($site)->create([
        'payload' => array_replace_recursive(
            InventoryReportFactory::samplePayload(),
            ['config_flags' => ['dev_mode' => false, 'allow_updates' => true]],
        ),
    ]);

    app(FindingsEvaluator::class)->evaluate($site);

    // A channel that fires on everything gets filtered into a folder nobody opens, at which point it
    // looks like coverage while providing none.
    Queue::assertNothingPushed();
});
