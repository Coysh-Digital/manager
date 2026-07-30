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
