<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Severity;
use App\Models\CapabilityGrant;
use App\Models\Finding;
use App\Models\LoginReport;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use Database\Factories\RuntimeReportFactory;

/**
 * Rules over the runtime and sign-in reports.
 *
 * The thresholds are the design of these rules, so the tests are mostly about where they sit. A rule
 * that fires on every site in every fleet is a rule that trains people to stop reading the list, and
 * the entries it then costs them are the ones that mattered.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'environment' => 'production',
    ]);

    foreach (['runtime:read', 'logins:read'] as $capability) {
        CapabilityGrant::factory()->for($this->site)->capability($capability)->create();
    }

    $this->evaluate = fn () => app(FindingsEvaluator::class)->evaluate($this->site->fresh());
    $this->finding = fn (string $rule) => Finding::query()
        ->where('site_id', $this->site->id)
        ->where('rule', $rule)
        ->first();
});

/*
 | Disk
 |-------------------------------------------------------------------------------------------------
 */

it('says nothing about a disk with room on it', function (): void {
    RuntimeReport::factory()->for($this->site)->create();

    ($this->evaluate)();

    expect(($this->finding)('disk_almost_full'))->toBeNull();
});

it('escalates as the disk fills', function (float $freeFraction, ?string $severity): void {
    $payload = RuntimeReportFactory::samplePayload();
    $total = 100_000_000_000;
    $payload['storage']['disk_total_bytes'] = $total;
    $payload['storage']['disk_free_bytes'] = (int) ($total * $freeFraction);

    RuntimeReport::factory()->for($this->site)->create([
        'payload' => $payload,
        'disk_total_bytes' => $total,
        'disk_free_bytes' => (int) ($total * $freeFraction),
    ]);

    ($this->evaluate)();

    expect(($this->finding)('disk_almost_full')?->severity)->toBe($severity);
})->with([
    'plenty of room' => [0.5, null],
    'getting tight' => [0.09, Severity::HIGH],
    'nearly gone' => [0.02, Severity::CRITICAL],
]);

it('treats a filesystem that cannot answer as unmeasured rather than fine', function (): void {
    // Common on containerised and remote storage. "We could not measure it" is not "it is fine", but
    // it is also not a finding — inventing a percentage would be worse than either.
    $payload = RuntimeReportFactory::samplePayload();
    unset($payload['storage']['disk_free_bytes'], $payload['storage']['disk_total_bytes']);

    RuntimeReport::factory()->for($this->site)->create([
        'payload' => $payload,
        'disk_free_bytes' => null,
        'disk_total_bytes' => null,
    ]);

    ($this->evaluate)();

    expect(($this->finding)('disk_almost_full'))->toBeNull();
});

it('ignores a runtime report old enough to be meaningless', function (): void {
    // Disk usage from last month is not evidence of anything, and a rule firing on it reports the
    // past.
    RuntimeReport::factory()->for($this->site)->create([
        'disk_free_bytes' => 1_000_000_000,
        'disk_total_bytes' => 100_000_000_000,
        'received_at' => now()->subWeeks(2),
    ]);

    ($this->evaluate)();

    expect(($this->finding)('disk_almost_full'))->toBeNull();
});

/*
 | Response times
 |-------------------------------------------------------------------------------------------------
 */

it('reads the 95th percentile rather than the mean', function (): void {
    // Nineteen fast requests and one eight-second one has an acceptable-looking mean and one visitor
    // in twenty waiting eight seconds.
    $payload = RuntimeReportFactory::samplePayload();
    $payload['response'] = [
        'samples' => 200, 'window_hours' => 6,
        'mean_ms' => 440.0, 'p50_ms' => 40.0, 'p95_ms' => 8000.0, 'max_ms' => 9100.0,
    ];

    RuntimeReport::factory()->for($this->site)->create(['payload' => $payload]);

    ($this->evaluate)();

    $finding = ($this->finding)('slow_response_times');

    expect($finding?->severity)->toBe(Severity::MEDIUM)
        // The detail has to say what was measured, because this is not what a visitor waits.
        ->and($finding?->detail)->toContain('server render time');
});

it('stays quiet on a sample too small to mean anything', function (): void {
    $payload = RuntimeReportFactory::samplePayload();
    $payload['response'] = ['samples' => 4, 'window_hours' => 6, 'p95_ms' => 9000.0, 'p50_ms' => 8000.0];

    RuntimeReport::factory()->for($this->site)->create(['payload' => $payload]);

    ($this->evaluate)();

    expect(($this->finding)('slow_response_times'))->toBeNull();
});

/*
 | Opcache
 |-------------------------------------------------------------------------------------------------
 */

it('mentions opcache only in production, and only quietly', function (): void {
    $payload = RuntimeReportFactory::samplePayload();
    $payload['php']['opcache_enabled'] = false;

    RuntimeReport::factory()->for($this->site)->create(['payload' => $payload]);

    ($this->evaluate)();

    expect(($this->finding)('opcache_disabled_in_production')?->severity)->toBe(Severity::LOW);

    // A development container without opcache is behaving exactly as intended.
    $this->site->forceFill(['environment' => 'development'])->save();
    ($this->evaluate)();

    expect(($this->finding)('opcache_disabled_in_production')?->state)->toBe(Finding::STATE_RESOLVED);
});

it('does not report an unreadable opcache as a disabled one', function (): void {
    $payload = RuntimeReportFactory::samplePayload();
    unset($payload['php']['opcache_enabled']);

    RuntimeReport::factory()->for($this->site)->create(['payload' => $payload]);

    ($this->evaluate)();

    expect(($this->finding)('opcache_disabled_in_production'))->toBeNull();
});

/*
 | Sign-ins
 |-------------------------------------------------------------------------------------------------
 */

it('ignores the background noise every public control panel gets', function (): void {
    // Bots find /admin and try a handful of passwords. A rule firing on that fires on every site in
    // every fleet forever.
    LoginReport::factory()->for($this->site)->create();

    ($this->evaluate)();

    expect(($this->finding)('repeated_failed_logins'))->toBeNull();
});

it('raises volume as medium and a targeted administrator as high', function (): void {
    LoginReport::factory()->for($this->site)->create([
        'failed_attempts' => 80,
        'accounts_with_failures' => 3,
        'admin_accounts_affected' => 0,
    ]);

    ($this->evaluate)();

    expect(($this->finding)('repeated_failed_logins')?->severity)->toBe(Severity::MEDIUM);

    // Somebody who knows which account is an administrator has done homework. Worth interrupting a
    // person for at a much lower count than a spray.
    LoginReport::factory()->for($this->site)->create([
        'failed_attempts' => 6,
        'accounts_with_failures' => 1,
        'admin_accounts_affected' => 1,
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('repeated_failed_logins');

    expect($finding?->severity)->toBe(Severity::HIGH)
        // The caveat travels with the number wherever it appears.
        ->and($finding?->detail)->toContain('floor rather than a total');
});

it('separates a locked-out colleague from an attack', function (): void {
    LoginReport::factory()->for($this->site)->create([
        'failed_attempts' => 12,
        'accounts_with_failures' => 1,
        'accounts_locked' => 1,
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('accounts_locked_out');

    expect($finding?->severity)->toBe(Severity::MEDIUM)
        // As likely a forgotten password as an intrusion, and the wording has to allow for both.
        ->and($finding?->detail)->toContain('forgotten password')
        // Below the volume threshold and no administrator targeted, so the other rule stays quiet.
        ->and(($this->finding)('repeated_failed_logins'))->toBeNull();
});

it('resolves a sign-in finding once the site stops reporting failures', function (): void {
    LoginReport::factory()->for($this->site)->underAttack()->create();
    ($this->evaluate)();

    expect(($this->finding)('repeated_failed_logins')?->state)->toBe(Finding::STATE_OPEN);

    LoginReport::factory()->for($this->site)->create([
        'failed_attempts' => 0,
        'accounts_with_failures' => 0,
        'accounts_locked' => 0,
        'admin_accounts_affected' => 0,
    ]);
    ($this->evaluate)();

    // Self-resolving: there is nothing for anybody to tick off.
    expect(($this->finding)('repeated_failed_logins')?->state)->toBe(Finding::STATE_RESOLVED);
});

/*
 | Capability gating
 |-------------------------------------------------------------------------------------------------
 */

it('skips the new rules rather than passing them when the capability is missing', function (): void {
    $this->site->capabilityGrants()->whereIn('capability', ['runtime:read', 'logins:read'])->delete();

    RuntimeReport::factory()->for($this->site)->create([
        'disk_free_bytes' => 1_000_000_000,
        'disk_total_bytes' => 100_000_000_000,
    ]);
    LoginReport::factory()->for($this->site)->underAttack()->create();

    $tally = ($this->evaluate)();

    // "We were not allowed to look" and "there is nothing wrong" are different answers.
    expect(($this->finding)('disk_almost_full'))->toBeNull()
        ->and(($this->finding)('repeated_failed_logins'))->toBeNull()
        ->and($tally['skipped'])->toBeGreaterThanOrEqual(4);
});
