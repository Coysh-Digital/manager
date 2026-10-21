<?php

declare(strict_types=1);

use App\Domain\Runtime\RuntimeIngestService;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\User;
use Database\Factories\RuntimeReportFactory;

/**
 * `system.v4`: the Node.js version a site's project files declare.
 *
 * Declared, never observed. The connector reads `.nvmrc`, `.node-version` or `package.json` and
 * executes nothing, so the screens say what the project asks for and not what is installed - and
 * they keep three absences apart, because "the plugin is too old to say" and "the project does not
 * say" call for opposite responses.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create();
    Connector::factory()->for($this->site)->create();

    foreach (['inventory:read', 'runtime:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }
});

/**
 * Whether the installed manager-protocol is new enough to know `system.v4`.
 *
 * The validator is the package's, so the tests that run a v4 payload through it can only pass once
 * the package that ships the schema is installed. Until composer.lock moves to 1.10.0 they are
 * skipped, with the reason on the report, rather than deleted or left to fail: a skip says what
 * is not being proven and starts proving it the day the lock moves, with no edit.
 */
function protocolKnowsSystemV4(): bool
{
    return is_file(base_path('vendor/coysh-digital/manager-protocol/schemas/system.v4.json'));
}

const NEEDS_PROTOCOL_V4 = 'Needs coysh-digital/manager-protocol 1.10.0, which ships schemas/system.v4.json; composer.lock still resolves an older release.';

/*
 | Ingest
 |-------------------------------------------------------------------------------------------------
 */

it('advertises system.v4 first, so a connector that implements it is told to send it', function (): void {
    expect(RuntimeIngestService::SCHEMAS[0])->toBe('system.v4')
        ->and(RuntimeIngestService::SCHEMAS)->toContain('system.v3', 'system.v2', 'system.v1');
});

it('accepts system.v4 alongside every version before it', function (string $schema): void {
    $payload = match ($schema) {
        'system.v4' => RuntimeReportFactory::nodePayload(),
        'system.v3' => RuntimeReportFactory::craftPayload(),
        default => ['schema_version' => $schema, 'collected_at' => now()->getTimestamp()],
    };

    expect(app(RuntimeIngestService::class)->validate($payload))->toBe([]);
})->with(['system.v4', 'system.v3', 'system.v2', 'system.v1'])
    ->skip(fn (): bool => ! protocolKnowsSystemV4(), NEEDS_PROTOCOL_V4);

it('accepts a site that looked and found no Node.js version declared', function (): void {
    $payload = RuntimeReportFactory::nodePayload(null, null);

    expect(app(RuntimeIngestService::class)->validate($payload))->toBe([]);
})->skip(fn (): bool => ! protocolKnowsSystemV4(), NEEDS_PROTOCOL_V4);

it('refuses a v4 report with no node section, because v4 requires the answer', function (): void {
    $payload = RuntimeReportFactory::nodePayload();
    unset($payload['node']);

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
})->skip(fn (): bool => ! protocolKnowsSystemV4(), NEEDS_PROTOCOL_V4);

it('refuses a path or a sentence where the version should be', function (string $declared): void {
    $payload = RuntimeReportFactory::nodePayload($declared);

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
})->with([
    'a filesystem path' => ['/var/www/vhosts/example.org/.nvmrc'],
    'a URL' => ['https://example.org/node'],
    'longer than a version can be' => [str_repeat('1', 33)],
])->skip(fn (): bool => ! protocolKnowsSystemV4(), NEEDS_PROTOCOL_V4);

it('refuses a path in place of the file name', function (): void {
    $payload = RuntimeReportFactory::nodePayload('20.11.0', '/var/www/vhosts/example.org/.nvmrc');

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
})->skip(fn (): bool => ! protocolKnowsSystemV4(), NEEDS_PROTOCOL_V4);

it('stores the declaration in the payload and nowhere else', function (): void {
    $report = app(RuntimeIngestService::class)->store($this->site, RuntimeReportFactory::nodePayload('>=20 <23', 'package.json'));

    expect($report->schema_version)->toBe('system.v4')
        ->and($report->fresh()->declaredNode())->toBe(['declared' => '>=20 <23', 'source' => 'package.json']);
});

/*
 | What the model makes of it
 |-------------------------------------------------------------------------------------------------
 */

it('tells the three absences apart', function (): void {
    $declared = RuntimeReport::factory()->for($this->site)->declaringNode('20.11.0', '.nvmrc')->make();
    $none = RuntimeReport::factory()->for($this->site)->declaringNode(null, null)->make();
    $older = RuntimeReport::factory()->for($this->site)->describingCraft()->make();

    expect($declared->declaredNode())->toBe(['declared' => '20.11.0', 'source' => '.nvmrc'])
        ->and($declared->declaredNodeLabel())->toBe('20.11.0 (.nvmrc)')
        ->and($none->declaredNode())->toBe(['declared' => null, 'source' => null])
        ->and($none->declaredNodeLabel())->toBe('Not declared')
        ->and($older->declaredNode())->toBeNull()
        ->and($older->declaredNodeLabel())->toContain('Not reported');
});

it('reads a malformed node section as not reported rather than as a version', function (mixed $node): void {
    $report = RuntimeReport::factory()->for($this->site)->make([
        'payload' => [...RuntimeReportFactory::nodePayload(), 'node' => $node],
    ]);

    expect($report->declaredNode())->toBeNull();
})->with([
    'a string' => ['20.11.0'],
    'a number' => [20],
    'null' => [null],
]);

/*
 | Screens
 |-------------------------------------------------------------------------------------------------
 */

it('shows the declared version and the file it came from, on the overview and on health', function (string $route): void {
    RuntimeReport::factory()->for($this->site)->declaringNode('20.11.0', '.nvmrc')->create();

    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertSee('Node.js (declared)')
        ->assertSee('20.11.0 (.nvmrc)')
        // The caveat is the point: a reader who takes this for the installed version will conclude a
        // server is on a release it may not be on.
        ->assertSee('not what is installed on the server');
})->with(['sites.show', 'sites.health']);

it('says "Not declared" for a site whose project files name no version', function (string $route): void {
    RuntimeReport::factory()->for($this->site)->declaringNode(null, null)->create();

    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertSee('Node.js (declared)')
        ->assertSee('Not declared')
        ->assertDontSee('connector update needed');
})->with(['sites.show', 'sites.health']);

it('says the connector needs updating when it predates system.v4', function (string $route): void {
    RuntimeReport::factory()->for($this->site)->describingCraft()->create();

    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertSee('Node.js (declared)')
        ->assertSee('Not reported - connector update needed')
        ->assertDontSee('Not declared');
})->with(['sites.show', 'sites.health']);

it('draws no Node.js row for a site that has sent no runtime report', function (string $route): void {
    // Without runtime:read there is nothing to be "not reported" about, and telling somebody to
    // update a connector that is simply not allowed to say would send them the wrong way.
    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertDontSee('Node.js');
})->with(['sites.show', 'sites.health']);

it('escapes whatever the report holds', function (string $route): void {
    // The schema's pattern keeps markup out on the way in; this is the second lock on the same
    // door, for a payload stored before the pattern existed or by a platform on an older package.
    RuntimeReport::factory()->for($this->site)->declaringNode('<script>alert(1)</script>', '.nvmrc')->create();

    $this->actingAs($this->user)
        ->get(route($route, $this->site))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
})->with(['sites.show', 'sites.health']);
