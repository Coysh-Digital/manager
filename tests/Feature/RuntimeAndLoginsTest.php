<?php

declare(strict_types=1);

use App\Domain\Logins\LoginsIngestService;
use App\Domain\Runtime\RuntimeIngestService;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\LoginReport;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\User;
use Database\Factories\LoginReportFactory;
use Database\Factories\RuntimeReportFactory;

/**
 * The two report types added with the connector 1.6 protocol.
 *
 * The allowlist assertions are the ones that matter. `logins.v1` exists to make it impossible to
 * store a username against a site, and `system.v1` to make it impossible to store a filesystem
 * path — both are promises kept by a schema rather than by a code review, and a test is how that
 * stays true.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);
    Connector::factory()->for($this->site)->create();

    foreach (['inventory:read', 'runtime:read', 'logins:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }
});

/*
 | The allowlist
 |-------------------------------------------------------------------------------------------------
 */

it('refuses a sign-in report carrying a username', function (): void {
    // The whole reason logins.v1 has additionalProperties: false. A connector that started sending
    // usernames — through a well-meaning change or a compromised one — is refused at the door rather
    // than having them stripped and stored anyway.
    $problems = app(LoginsIngestService::class)->validate([
        ...LoginReportFactory::samplePayload(),
        'usernames' => ['admin', 'editor'],
    ]);

    expect($problems)->not->toBeEmpty();
});

it('refuses a sign-in report carrying source addresses', function (): void {
    $problems = app(LoginsIngestService::class)->validate([
        ...LoginReportFactory::samplePayload(),
        'source_ips' => ['203.0.113.9'],
    ]);

    expect($problems)->not->toBeEmpty();
});

it('refuses a runtime report carrying a filesystem path', function (): void {
    $payload = RuntimeReportFactory::samplePayload();
    $payload['storage']['volumes'][0]['path'] = '/var/www/html/web/uploads';

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
});

it('accepts the shapes the connector actually sends', function (): void {
    expect(app(RuntimeIngestService::class)->validate(RuntimeReportFactory::samplePayload()))->toBe([])
        ->and(app(LoginsIngestService::class)->validate(LoginReportFactory::samplePayload()))->toBe([]);
});

/*
 | Ingest
 |-------------------------------------------------------------------------------------------------
 */

it('totals only the volumes it could actually measure', function (): void {
    // A volume nobody could walk contributes nothing rather than a guess. Counting an unmeasured
    // volume as zero and presenting the result as a total is how somebody concludes that an asset
    // volume was emptied overnight.
    $payload = RuntimeReportFactory::samplePayload();
    $payload['storage']['volumes'][] = ['handle' => 'archive', 'bytes' => 0, 'measured' => false];

    $report = app(RuntimeIngestService::class)->store($this->site, $payload);

    expect($report->storage_bytes)->toBe(4_294_967_296 + 214_748_364)
        ->and($report->hasUnmeasuredVolumes())->toBeTrue();
});

it('denormalises the figures the fleet sorts on', function (): void {
    $report = app(RuntimeIngestService::class)->store($this->site, RuntimeReportFactory::samplePayload());

    expect($report->response_p95_ms)->toBe(212)
        ->and($report->response_mean_ms)->toBe(84)
        ->and($report->diskUsedPercent())->toBe(80.0);
});

it('leaves disk usage unanswered rather than guessing', function (): void {
    // Remote and containerised filesystems often cannot answer, and a made-up percentage on a screen
    // somebody sizes a server from is worse than an em-dash.
    $payload = RuntimeReportFactory::samplePayload();
    unset($payload['storage']['disk_free_bytes'], $payload['storage']['disk_total_bytes']);

    expect(app(RuntimeIngestService::class)->store($this->site, $payload)->diskUsedPercent())->toBeNull();
});

/*
 | Screens
 |-------------------------------------------------------------------------------------------------
 */

it('shows storage, limits and response time on Health, and says what the timing is not', function (): void {
    RuntimeReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('Server response time')
        // Named honestly. It is render time, not TTFB, and the screen has to say so — asserted on a
        // fragment the template does not wrap, since the sentence spans two source lines.
        ->assertSee('first byte:')
        ->assertSee('212 ms')
        ->assertSee('images')
        ->assertSee('Memory limit');
});

it('carries the reset caveat wherever the sign-in counts appear', function (): void {
    LoginReport::factory()->for($this->site)->underAttack()->create();

    foreach (['sites.security', 'sites.audit'] as $route) {
        $this->actingAs($this->user)
            ->get(route($route, $this->site))
            ->assertOk()
            ->assertSee('214')
            ->assertSee('2 locked out')
            // A number without this invites the wrong conclusion from a reassuring zero.
            ->assertSee('These are a floor, not a total:', escape: false);
    }
});

it('distinguishes ungranted from unreported for sign-ins', function (): void {
    $this->site->capabilityGrants()->where('capability', 'logins:read')->delete();

    $this->actingAs($this->user)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('has not been')
        ->assertSee('logins:read');

    CapabilityGrant::factory()->for($this->site)->capability('logins:read')->create();

    $this->actingAs($this->user)
        ->get(route('sites.security', $this->site))
        ->assertOk()
        ->assertSee('the site has not reported yet');
});

it('tells somebody the runtime capability is missing rather than showing nothing', function (): void {
    $this->site->capabilityGrants()->where('capability', 'runtime:read')->delete();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('runtime:read');
});

/*
 | system.v2: where a volume is, and why it went unmeasured
 |-------------------------------------------------------------------------------------------------
 |
 | Reported from use: one volume showed "Not measured" while its neighbours were measured, with
 | nothing on the screen saying why — and nothing anywhere saying that a volume on S3 contributes
 | none of its bytes to the disk figures directly above it.
 */

it('accepts both report versions, because the two sides upgrade on different days', function (): void {
    /*
     * The platform is upgraded by whoever runs it; each site upgrades its own plugin. Pinning one
     * version meant a flag day, and the failure was silent in the worst way — a runtime report is
     * fire-and-forget, so a refused one shows up as a Health screen that quietly stops moving.
     */
    $v1 = RuntimeReportFactory::samplePayload();

    $v2 = [
        ...$v1,
        'schema_version' => 'system.v2',
        'storage' => [
            ...$v1['storage'],
            'volumes' => [
                ['handle' => 'images', 'bytes' => 4_294_967_296, 'files' => 18_402, 'measured' => true, 'location' => 'local'],
                ['handle' => 'archive', 'bytes' => 0, 'measured' => false, 'location' => 'remote', 'unmeasured_reason' => 'remote'],
            ],
        ],
    ];

    expect(app(RuntimeIngestService::class)->validate($v1))->toBe([])
        ->and(app(RuntimeIngestService::class)->validate($v2))->toBe([]);
});

it('names what it accepts rather than blaming the payload for a version it never heard of', function (): void {
    // Falling back to v1 would validate a v3 payload against v1's allowlist and report the result
    // as a schema violation — true, and completely misleading about what to do next.
    $payload = [...RuntimeReportFactory::samplePayload(), 'schema_version' => 'system.v9'];

    $problems = app(RuntimeIngestService::class)->validate($payload);

    expect(implode(' ', $problems))->toContain('system.v2')
        ->and(implode(' ', $problems))->toContain('system.v1');
});

it('tells the connector which versions it understands', function (): void {
    // How a site ever learns it may send the newer one. Without this the connector would have to
    // assume, and assuming is what makes an upgrade a flag day.
    expect(RuntimeIngestService::SCHEMAS)->toBe(['system.v2', 'system.v1']);
});

it('still refuses a bucket, a region or an adapter class under the new version', function (): void {
    // The point of location being an enum of two. A provider name invites a bucket beside it, and a
    // bucket names somebody's infrastructure.
    $payload = [...RuntimeReportFactory::samplePayload(), 'schema_version' => 'system.v2'];
    $payload['storage']['volumes'][0]['location'] = 'remote';
    $payload['storage']['volumes'][0]['bucket'] = 'acme-production-uploads';

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
});

it('separates the three reasons a volume goes unmeasured', function (): void {
    expect(RuntimeReport::unmeasuredReason(['measured' => false, 'unmeasured_reason' => 'remote']))
        ->toContain('API call per directory')
        ->and(RuntimeReport::unmeasuredReason(['measured' => false, 'unmeasured_reason' => 'timeout']))
        ->toContain('storageWalkSeconds')
        ->and(RuntimeReport::unmeasuredReason(['measured' => false, 'unmeasured_reason' => 'unreadable']))
        ->toContain('fault');

    // Measured, and unmeasured by an older connector that cannot say. Both give nothing rather than
    // a guess: inventing a reason from the shape of what arrived would put a confident sentence on
    // a screen with nothing behind it.
    expect(RuntimeReport::unmeasuredReason(['measured' => true]))->toBeNull()
        ->and(RuntimeReport::unmeasuredReason(['measured' => false]))->toBeNull();
});

it('does not claim to know where a volume is when the connector did not say', function (): void {
    expect(RuntimeReport::isLocalVolume(['location' => 'local']))->toBeTrue()
        ->and(RuntimeReport::isLocalVolume(['location' => 'remote']))->toBeFalse()
        ->and(RuntimeReport::isLocalVolume([]))->toBeNull();
});

it('shows where each volume lives, and says a remote one is not on the disk', function (): void {
    $payload = [...RuntimeReportFactory::samplePayload(), 'schema_version' => 'system.v2'];
    $payload['storage']['volumes'] = [
        ['handle' => 'images', 'bytes' => 4_294_967_296, 'files' => 18_402, 'measured' => true, 'location' => 'local'],
        ['handle' => 'archive', 'bytes' => 0, 'measured' => false, 'location' => 'remote', 'unmeasured_reason' => 'remote'],
        ['handle' => 'video', 'bytes' => 8_589_934_592, 'files' => 214, 'measured' => false, 'location' => 'local', 'unmeasured_reason' => 'timeout'],
    ];

    app(RuntimeIngestService::class)->store($this->site, $payload);

    $this->actingAs($this->user)->get("/sites/{$this->site->external_id}/health")
        ->assertOk()
        ->assertSee('This server')
        ->assertSee('Remote storage')
        ->assertSee('API call per directory')
        ->assertSee('storageWalkSeconds')
        // A timed-out walk keeps the figure it reached, said as a floor rather than as a total.
        ->assertSee('at least 8.00 GB')
        ->assertSee("not on this server's disk", false);
});

it('draws no location column for a connector too old to fill it', function (): void {
    // A column of em-dashes teaches people to ignore a column.
    app(RuntimeIngestService::class)->store($this->site, RuntimeReportFactory::samplePayload());

    $this->actingAs($this->user)->get("/sites/{$this->site->external_id}/health")
        ->assertOk()
        ->assertDontSee('Remote storage')
        ->assertDontSee('This server');
});
