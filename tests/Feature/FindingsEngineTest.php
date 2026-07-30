<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Severity;
use App\Domain\Inventory\InventoryIngestService;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\InventoryReport;
use App\Models\Site;
use App\Models\UpdateReport;
use Database\Factories\InventoryReportFactory;

/**
 * The findings engine.
 *
 * Findings are derived and reconciled, so the behaviour worth testing is not "does a rule fire" but
 * what happens on the second, third and fourth run.
 */
beforeEach(function (): void {
    $this->site = Site::factory()->connected()->create(['environment' => 'production']);
    Connector::factory()->for($this->site)->create();

    foreach (['inventory:read', 'updates:read', 'security:read', 'system:read', 'licences:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }

    $this->evaluator = app(FindingsEvaluator::class);
});

/**
 * Store an inventory report with the given payload overrides.
 */
function reportInventory(Site $site, array $overrides = []): InventoryReport
{
    $payload = array_replace_recursive(InventoryReportFactory::samplePayload(), $overrides);

    return InventoryReport::factory()->for($site)->create(['payload' => $payload]);
}

it('opens a finding when a rule first matches', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);

    $this->evaluator->evaluate($this->site);

    $finding = Finding::query()->where('rule', 'dev_mode_in_production')->firstOrFail();

    expect($finding->severity)->toBe(Severity::HIGH)
        ->and($finding->state)->toBe(Finding::STATE_OPEN)
        // The detail says what to do, not just what is wrong.
        ->and($finding->detail)->toContain('Set devMode to false');
});

it('does not open the same finding twice', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);

    $this->evaluator->evaluate($this->site);
    $this->evaluator->evaluate($this->site);
    $this->evaluator->evaluate($this->site);

    // A findings list that grows on every report is a list nobody reads.
    expect(Finding::query()->where('rule', 'dev_mode_in_production')->count())->toBe(1);
});

it('resolves a finding once the problem is fixed', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    expect(Finding::query()->where('rule', 'dev_mode_in_production')->firstOrFail()->state)
        ->toBe(Finding::STATE_OPEN);

    // A later report with dev mode off.
    reportInventory($this->site, ['config_flags' => ['dev_mode' => false]]);
    $this->evaluator->evaluate($this->site);

    // Self-resolving. Asking somebody to tick off a problem the platform can see is fixed is asking
    // them to do its job.
    expect(Finding::query()->where('rule', 'dev_mode_in_production')->firstOrFail()->state)
        ->toBe(Finding::STATE_RESOLVED);
});

it('keeps the original first-seen date while a finding persists', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);

    $this->travelTo(now()->subDays(5));
    $this->evaluator->evaluate($this->site);
    $firstSeen = Finding::query()->firstOrFail()->first_seen_at;

    $this->travelBack();
    $this->evaluator->evaluate($this->site);

    // How long a problem has been true is often the most useful column on the screen, and it is lost
    // the moment a finding is deleted and recreated.
    expect(Finding::query()->firstOrFail()->first_seen_at->timestamp)->toBe($firstSeen->timestamp)
        ->and(Finding::query()->firstOrFail()->last_seen_at->gt($firstSeen))->toBeTrue();
});

it('keeps an acknowledgement across re-evaluation', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    Finding::query()->firstOrFail()->forceFill([
        'state' => Finding::STATE_ACKNOWLEDGED,
        'acknowledged_at' => now(),
        'acknowledgement_reason' => 'Deliberate here',
    ])->save();

    $this->evaluator->evaluate($this->site);

    // Somebody saying "we know" must not be undone by the next report five minutes later.
    $finding = Finding::query()->firstOrFail();

    expect($finding->state)->toBe(Finding::STATE_ACKNOWLEDGED)
        ->and($finding->acknowledgement_reason)->toBe('Deliberate here');
});

it('clears an acknowledgement when a resolved finding returns', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    Finding::query()->firstOrFail()->forceFill([
        'state' => Finding::STATE_ACKNOWLEDGED,
        'acknowledged_at' => now(),
        'acknowledgement_reason' => 'Just for today',
    ])->save();

    reportInventory($this->site, ['config_flags' => ['dev_mode' => false]]);
    $this->evaluator->evaluate($this->site);

    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    // A recurrence is a fresh occurrence: "we know about this" was said about a problem that then
    // went away, so it deserves a new decision.
    $finding = Finding::query()->firstOrFail();

    expect($finding->state)->toBe(Finding::STATE_OPEN)
        ->and($finding->acknowledgement_reason)->toBeNull();
});

it('skips a rule whose capability is not granted rather than passing it', function (): void {
    $site = Site::factory()->connected()->create(['environment' => 'production']);
    Connector::factory()->for($site)->create();
    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();

    // Dev mode is on, but security:read was never granted, so the platform was not told.
    reportInventory($site, ['config_flags' => ['dev_mode' => true]]);

    $tally = $this->evaluator->evaluate($site);

    // "We were not allowed to look" and "there is nothing wrong" are different answers. Reporting
    // clean here is how a real problem gets a clean bill of health.
    expect($tally['skipped'])->toBeGreaterThan(0)
        ->and(Finding::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('leaves an existing finding alone when its capability is revoked', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    $this->site->capabilityGrants()->where('capability', 'security:read')->delete();

    $this->evaluator->evaluate($this->site->fresh());

    // Losing the capability tells us nothing about whether the problem went away, so resolving it
    // would be inventing a fix.
    expect(Finding::query()->firstOrFail()->state)->toBe(Finding::STATE_OPEN);
});

it('does not report configuration findings outside production', function (): void {
    $staging = Site::factory()->connected()->create(['environment' => 'staging']);
    Connector::factory()->for($staging)->create();
    CapabilityGrant::factory()->for($staging)->capability('security:read')->create();

    reportInventory($staging, ['config_flags' => ['dev_mode' => true, 'allow_admin_changes' => true]]);

    $this->evaluator->evaluate($staging);

    // Dev mode on a staging site is correct. Reporting it would train people to ignore the screen,
    // which costs more than the finding is worth.
    expect(Finding::query()->where('site_id', $staging->id)->where('rule', 'dev_mode_in_production')->count())->toBe(0);
});

it('treats a Craft security release as critical', function (): void {
    UpdateReport::factory()->for($this->site)->create();

    $this->evaluator->evaluate($this->site);

    $finding = Finding::query()->where('rule', 'craft_security_release')->firstOrFail();

    // The only critical rule in the set: a fix exists for a known problem and the site does not
    // have it.
    expect($finding->severity)->toBe(Severity::CRITICAL)
        ->and($finding->detail)->toContain('5.6.2')
        ->and($finding->detail)->toContain('5.6.4')
        // And it points at the changelog rather than repeating what we deliberately never fetched.
        ->and($finding->detail)->toContain('does not retrieve release notes');
});

it('never puts advisory detail in a finding', function (): void {
    UpdateReport::factory()->for($this->site)->create();
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);

    $this->evaluator->evaluate($this->site);

    $serialised = Finding::query()->get()->toJson();

    expect($serialised)->not->toContain('release_notes')
        ->and($serialised)->not->toContain('download_url')
        ->and($serialised)->not->toContain('CVE-');
});

it('flags a site that has gone quiet, with no capability needed', function (): void {
    $silent = Site::factory()->connected()->create(['last_seen_at' => now()->subDay()]);
    Connector::factory()->for($silent)->create();

    $this->evaluator->evaluate($silent);

    // Derived from the platform's own records, so it works even for a site that has granted nothing.
    // It is also the rule that matters most: every other finding depends on reports.
    expect(Finding::query()->where('site_id', $silent->id)->where('rule', 'site_not_reporting')->exists())
        ->toBeTrue();
});

it('does not flag a site that has never connected as having stopped reporting', function (): void {
    $fresh = Site::factory()->create(['last_seen_at' => null]);

    $this->evaluator->evaluate($fresh);

    // Not set up is a different thing from stopped working, and the Sites screen says so already.
    expect(Finding::query()->where('site_id', $fresh->id)->where('rule', 'site_not_reporting')->count())
        ->toBe(0);
});

it('mirrors the worst outstanding severity onto the site', function (): void {
    UpdateReport::factory()->for($this->site)->create();
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true, 'allow_updates' => true]]);

    $this->evaluator->evaluate($this->site);

    // So the fleet table can rank by urgency in one scan rather than aggregating per row.
    expect($this->site->fresh()->worst_finding_severity)->toBe(Severity::CRITICAL)
        ->and($this->site->fresh()->open_findings)->toBeGreaterThan(1);
});

it('counts an acknowledged finding as still outstanding', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    Finding::query()->firstOrFail()->forceFill([
        'state' => Finding::STATE_ACKNOWLEDGED,
        'acknowledged_at' => now(),
    ])->save();

    $this->evaluator->evaluate($this->site);

    // Acknowledgement is not resolution. Counting it as closed would let a fleet look clean while
    // every problem in it has merely been read.
    expect($this->site->fresh()->open_findings)->toBeGreaterThan(0);
});

it('audits a finding opening and resolving', function (): void {
    reportInventory($this->site, ['config_flags' => ['dev_mode' => true]]);
    $this->evaluator->evaluate($this->site);

    reportInventory($this->site, ['config_flags' => ['dev_mode' => false]]);
    $this->evaluator->evaluate($this->site);

    $actions = AuditEvent::query()->pluck('action')->all();

    expect($actions)->toContain('finding.opened')
        ->and($actions)->toContain('finding.resolved');
});

it('evaluates automatically when a report arrives', function (): void {
    // The screen must never be stale relative to the report that produced it.
    app(InventoryIngestService::class)->store(
        $this->site,
        array_replace_recursive(InventoryReportFactory::samplePayload(), ['config_flags' => ['dev_mode' => true]]),
    );

    expect(Finding::query()->where('rule', 'dev_mode_in_production')->exists())->toBeTrue();
});

it('gives every rule a distinct, stable key', function (): void {
    $keys = array_map(fn ($rule): string => $rule->key(), $this->evaluator->rules());

    // Acknowledgements are keyed on these, so a collision would silently merge two findings and a
    // rename would lose an acknowledgement.
    expect($keys)->toHaveCount(count(array_unique($keys)));

    foreach ($keys as $key) {
        expect($key)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

it('assigns every rule a severity the scale defines', function (): void {
    reportInventory($this->site, [
        'config_flags' => ['dev_mode' => true, 'allow_admin_changes' => true, 'allow_updates' => true, 'https_enforced' => false],
        'queue' => ['failed' => 3],
        'migrations' => ['pending' => 2],
        'licence' => ['craft' => 'invalid'],
    ]);
    UpdateReport::factory()->for($this->site)->create();

    $this->evaluator->evaluate($this->site);

    foreach (Finding::query()->get() as $finding) {
        expect($finding->severity)->toBeIn(Severity::ordered());
    }

    // And a scale where everything drifts to "high" tells nobody anything, so check the spread.
    expect(Finding::query()->distinct()->pluck('severity'))->toHaveCount(4);
});
