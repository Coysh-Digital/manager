<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Severity;
use App\Domain\Runtime\RuntimeIngestService;
use App\Models\CapabilityGrant;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\UpdateReport;
use App\Models\User;
use Database\Factories\RuntimeReportFactory;
use Database\Factories\UpdateReportFactory;

/**
 * `system.v3`: what a site can say about its own Craft install.
 *
 * Two things are being tested and they pull in opposite directions. The report has to be *accepted*
 * alongside the two versions before it, because the platform and each site's plugin are upgraded by
 * different people on different days and neither can assume the other moved first. And the rules
 * over it have to stay quiet on everything a connector too old to answer them sends, which is most
 * of a fleet on the day this ships.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'environment' => 'production',
    ]);

    CapabilityGrant::factory()->for($this->site)->capability('runtime:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();

    $this->evaluate = fn () => app(FindingsEvaluator::class)->evaluate($this->site->fresh());
    $this->finding = fn (string $rule) => Finding::query()
        ->where('site_id', $this->site->id)
        ->where('rule', $rule)
        ->first();
});

/*
 | Ingest
 |-------------------------------------------------------------------------------------------------
 */

it('accepts all three report versions, because the two sides upgrade on different days', function (string $schema): void {
    $payload = $schema === 'system.v3'
        ? RuntimeReportFactory::craftPayload()
        : ['schema_version' => $schema, 'collected_at' => now()->getTimestamp()];

    expect(app(RuntimeIngestService::class)->validate($payload))->toBe([]);
})->with(['system.v1', 'system.v2', 'system.v3']);

it('still refuses a filesystem path in the new sections', function (): void {
    // The whole reason `paths` is a fixed set of Craft's own directory names rather than a map. A
    // free-form label here would be a filesystem path with extra steps.
    $payload = RuntimeReportFactory::craftPayload();
    $payload['paths']['/var/www/vhosts/example.org/storage'] = true;

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
});

it('still refuses the identifying half of what v3 added', function (string $section, string $field, mixed $value): void {
    $payload = RuntimeReportFactory::craftPayload();
    $payload[$section][$field] = $value;

    expect(app(RuntimeIngestService::class)->validate($payload))->not->toBeEmpty();
})->with([
    // Deliberately not a realistic key. The assertion is that the *field* is refused, so the value
    // is irrelevant - and a random-looking string here trips secret scanning on every commit that
    // touches this file, which trains people to wave the scanner through.
    'the security key beside the boolean' => ['craft', 'security_key', 'not-a-real-security-key'],
    'the trigger beside the boolean' => ['craft', 'cp_trigger', 'acme-secret-panel'],
    'the deprecation messages beside the count' => ['craft', 'messages', ['templates/_layouts/base.twig line 42']],
    'a table breakdown beside the total' => ['database', 'tables', [['name' => 'users', 'rows' => 918]]],
    'the database name' => ['database', 'name', 'acme_production'],
]);

/*
 | Rules
 |-------------------------------------------------------------------------------------------------
 */

it('says nothing about a site with nothing wrong with it', function (): void {
    RuntimeReport::factory()->for($this->site)->describingCraft()->create();

    ($this->evaluate)();

    foreach ([
        'security_key_missing',
        'directories_not_writable',
        'required_extension_missing',
        'deprecation_warnings',
    ] as $rule) {
        expect(($this->finding)($rule))->toBeNull();
    }
});

it('says nothing at all to a connector too old to answer any of it', function (): void {
    /*
     * The expensive way these rules could be wrong. Most of a fleet is on the previous plugin
     * release on the day this ships, and a rule reading a missing section as a failing one would
     * open four findings against every one of them - each describing the plugin version rather than
     * the site it named.
     */
    RuntimeReport::factory()->for($this->site)->create();

    ($this->evaluate)();

    foreach ([
        'security_key_missing',
        'directories_not_writable',
        'required_extension_missing',
        'deprecation_warnings',
    ] as $rule) {
        expect(($this->finding)($rule))->toBeNull();
    }
});

it('reports a missing security key as critical', function (): void {
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['craft' => ['security_key_set' => false]])
        ->create();

    ($this->evaluate)();

    $finding = ($this->finding)('security_key_missing');

    expect($finding?->severity)->toBe(Severity::CRITICAL)
        // The consequence somebody has to be warned about before acting: setting a key makes
        // anything encrypted under a different one unreadable.
        ->and($finding?->detail)->toContain('stop decrypting');
});

it('names which directory is not writable and what that costs', function (): void {
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['paths' => ['cpresources' => false]])
        ->create();

    ($this->evaluate)();

    $finding = ($this->finding)('directories_not_writable');

    expect($finding?->severity)->toBe(Severity::HIGH)
        ->and($finding?->evidence['unwritable'])->toBe(['cpresources'])
        // Each of the three fails differently, and the detail has to say how - "not writable" alone
        // does not tell somebody what they are about to discover.
        ->and($finding?->detail)->toContain('unstyled');
});

it('reports a missing required extension with the symptom, not just the name', function (): void {
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['php' => ['missing_extensions' => ['intl']]])
        ->create();

    ($this->evaluate)();

    $finding = ($this->finding)('required_extension_missing');

    expect($finding?->severity)->toBe(Severity::HIGH)
        ->and($finding?->title)->toContain('intl')
        // The symptom rarely looks like the cause, which is the argument for reporting it at all.
        ->and($finding?->detail)->toContain('date formatting');
});

it('tells an empty missing-extension list apart from an absent one', function (): void {
    // A site that checked and found none, and a connector that could not look, are different
    // answers. Only the first is an all-clear; neither is a finding.
    RuntimeReport::factory()->for($this->site)->describingCraft()->create();

    ($this->evaluate)();

    expect(($this->finding)('required_extension_missing'))->toBeNull()
        ->and(RuntimeReport::query()->latest('id')->first()->missingExtensions())->toBe([]);

    RuntimeReport::query()->delete();
    RuntimeReport::factory()->for($this->site)->create();

    expect(RuntimeReport::query()->latest('id')->first()->missingExtensions())->toBeNull();
});

/*
 | Deprecations, which are only a finding when they are about to matter
 |-------------------------------------------------------------------------------------------------
 */

it('ignores a handful of deprecations with no upgrade in sight', function (): void {
    // Every Craft site of any age has some, none of them break anything today, and a rule firing on
    // "you have three" would be amber across a fleet forever while describing nothing anybody
    // should act on this week.
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['craft' => ['deprecation_count' => 4]])
        ->create();

    ($this->evaluate)();

    expect(($this->finding)('deprecation_warnings'))->toBeNull();
});

it('raises deprecations to medium when a breaking Craft release is waiting', function (): void {
    /*
     * The case the rule exists for. Deprecated code is a bill that comes due at the next major
     * upgrade and at no other moment, so the interesting site is not the one with the most warnings
     * - it is the one with warnings and a breaking release available, which is about to have a bad
     * afternoon and still has time not to.
     */
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['craft' => ['deprecation_count' => 4]])
        ->create();

    UpdateReport::factory()->for($this->site)->create([
        'payload' => [
            ...UpdateReportFactory::samplePayload(),
            'craft' => [
                ...UpdateReportFactory::samplePayload()['craft'],
                'update_available' => true,
                'latest_is_breaking' => true,
            ],
        ],
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('deprecation_warnings');

    expect($finding?->severity)->toBe(Severity::MEDIUM)
        ->and($finding?->title)->toContain('breaking Craft update')
        // Manager holds the count and not the messages, and the finding has to say where the
        // messages are instead of implying it is withholding them.
        ->and($finding?->detail)->toContain('Utilities');
});

it('mentions a great many deprecations even with no upgrade pending', function (): void {
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['craft' => ['deprecation_count' => 40]])
        ->create();

    ($this->evaluate)();

    expect(($this->finding)('deprecation_warnings')?->severity)->toBe(Severity::LOW);
});

/*
 | Screens
 |-------------------------------------------------------------------------------------------------
 */

it('shows what Craft said about itself', function (): void {
    RuntimeReport::factory()->for($this->site)->describingCraft()->create();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('Deprecations')
        ->assertSee('Sessions table')
        ->assertSee('Security key')
        // The database size sits beside the disk figures rather than in its own panel: it is the
        // number those exist to be read against, because a backup is a dump of it.
        ->assertSee('Database')
        ->assertSee('20 MB')
        ->assertSee('imagick');
});

it('draws no Craft panel at all for a connector that cannot fill it', function (): void {
    // Rather than a panel of em-dashes, which teaches people to stop reading the panel.
    RuntimeReport::factory()->for($this->site)->create();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertDontSee('Sessions table');
});

it('says the control panel moved without saying where to', function (): void {
    // A site that moved its control panel moved it somewhere it would rather not have written down,
    // and the wire schema has nowhere to put the address - so the screen cannot leak what it was
    // never sent.
    RuntimeReport::factory()->for($this->site)
        ->describingCraft(['craft' => ['cp_trigger_default' => false]])
        ->create();

    $this->actingAs($this->user)
        ->get(route('sites.health', $this->site))
        ->assertOk()
        ->assertSee('Moved');
});
