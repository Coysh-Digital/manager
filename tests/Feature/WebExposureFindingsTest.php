<?php

declare(strict_types=1);

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Severity;
use App\Models\Finding;
use App\Models\Organisation;
use App\Models\ProbeReport;
use App\Models\Site;
use Database\Factories\ProbeReportFactory;

/**
 * Rules over what a site serves to the public.
 *
 * These are the noisiest rules in the product if they are written carelessly - every site on the
 * internet is missing at least one security header - so most of this file is about what does *not*
 * fire. A rule that lands on most of a fleet trains people to stop reading the list, and the entries
 * it then costs them are the ones that mattered.
 *
 * None of them takes a capability. The probe is the platform's own observation of a public web page,
 * and a grant would be asking a site's permission to look at what it serves to everybody.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create([
        'environment' => 'production',
        'expected_domain' => 'example.org',
    ]);

    $this->evaluate = fn () => app(FindingsEvaluator::class)->evaluate($this->site->fresh());
    $this->finding = fn (string $rule) => Finding::query()
        ->where('site_id', $this->site->id)
        ->where('rule', $rule)
        ->first();

    $this->probeWith = function (array $headers, array $extra = []) {
        $payload = ProbeReportFactory::samplePayload();
        $payload['headers'] = $headers;

        return ProbeReport::factory()->for($this->site)->create([
            'payload' => [...$payload, ...$extra],
            ...$extra,
        ]);
    };
});

/*
 | Headers
 |-------------------------------------------------------------------------------------------------
 */

it('says nothing to a site that is already hardened', function (): void {
    ProbeReport::factory()->for($this->site)->create();

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing'))->toBeNull()
        ->and(($this->finding)('csp_not_enforced'))->toBeNull()
        ->and(($this->finding)('software_version_disclosed'))->toBeNull()
        ->and(($this->finding)('https_redirect_missing'))->toBeNull();
});

it('reports a site serving none of them as one finding rather than three', function (): void {
    // The cause is a server nobody configured for this, not three separate oversights, so three
    // rows would be three descriptions of one afternoon's work.
    ProbeReport::factory()->for($this->site)->bare()->create();

    ($this->evaluate)();

    $finding = ($this->finding)('security_headers_missing');

    expect($finding?->severity)->toBe(Severity::MEDIUM)
        ->and($finding?->title)->toContain('3 security headers')
        ->and($finding?->evidence['missing'])->toBe([
            'Strict-Transport-Security',
            'X-Content-Type-Options',
            'X-Frame-Options',
        ]);
});

it('drops to low when only one is missing', function (): void {
    // One header missing from an otherwise hardened site is worth mentioning next time somebody is
    // in the server configuration. Three is a different situation and wants a different rank.
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-frame-options' => 'SAMEORIGIN',
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('security_headers_missing');

    expect($finding?->severity)->toBe(Severity::LOW)
        ->and($finding?->title)->toContain('X-Content-Type-Options');
});

it('accepts a CSP frame-ancestors in place of X-Frame-Options', function (): void {
    /*
     * The check that stops this rule flagging the best-configured sites in a fleet. frame-ancestors
     * supersedes X-Frame-Options and browsers prefer it where both are present, so a site that has
     * moved to CSP alone is ahead rather than behind - and a rule that did not know would be telling
     * them to go backwards.
     */
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'content-security-policy' => "default-src 'self'; frame-ancestors 'none'",
    ]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing'))->toBeNull();
});

it('does not count a report-only policy as controlling framing', function (): void {
    // It enforces nothing by definition. A site mid-rollout hears about it from the CSP rule
    // instead, which is the one that has something useful to say to them.
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'content-security-policy-report-only' => "default-src 'self'; frame-ancestors 'none'",
    ]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing')?->evidence['missing'])->toBe(['X-Frame-Options']);
});

it('leaves the two headers that would fire on half the internet out of it', function (): void {
    // Referrer-Policy is defaulted sensibly by every current browser, and almost nobody sets
    // Permissions-Policy or needs to. Both are on the screen; neither is a finding.
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
    ]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing'))->toBeNull();
});

/*
 | Content-Security-Policy
 |-------------------------------------------------------------------------------------------------
 */

it('says nothing about a site with no CSP at all', function (): void {
    /*
     * The deliberate half of this rule. A useful policy is hard to write, it breaks things when it
     * is wrong, and plenty of well-run sites have decided against one - so "you have no CSP" would
     * fire across most of a fleet to say something everybody already knows.
     */
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
    ]);

    ($this->evaluate)();

    expect(($this->finding)('csp_not_enforced'))->toBeNull();
});

it('reports a policy that was written, deployed and enforces nothing', function (): void {
    // Somebody wrote a policy, deployed it report-only, meant to come back, and a sprint ended.
    // From that day the site has looked protected to anybody reading its headers while browsers
    // ignored every violation it describes.
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
        'content-security-policy-report-only' => "default-src 'self'",
    ]);

    ($this->evaluate)();

    expect(($this->finding)('csp_not_enforced')?->severity)->toBe(Severity::LOW);
});

it('says nothing to a site testing a stricter policy behind an enforced one', function (): void {
    // Both headers present is the correct way to tighten a CSP, and not something to interrupt.
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
        'content-security-policy' => "default-src 'self'",
        'content-security-policy-report-only' => "default-src 'none'",
    ]);

    ($this->evaluate)();

    expect(($this->finding)('csp_not_enforced'))->toBeNull();
});

/*
 | Plain HTTP
 |-------------------------------------------------------------------------------------------------
 */

it('reports a site that answers on port 80 without redirecting', function (): void {
    ProbeReport::factory()->for($this->site)->create([
        'redirects_to_https' => false,
        'payload' => [...ProbeReportFactory::samplePayload(), 'redirects_to_https' => false],
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('https_redirect_missing');

    expect($finding?->severity)->toBe(Severity::HIGH)
        // The consequence people do not think of: a session cookie set on that page is readable by
        // anybody on the same network.
        ->and($finding?->detail)->toContain('cookie');
});

it('does not report a host that simply does not listen on port 80', function (): void {
    // Nothing to redirect, and the best state available. Reporting it would flag the absence of a
    // problem, which is how a check loses the reader.
    ProbeReport::factory()->for($this->site)->create([
        'redirects_to_https' => null,
        'payload' => [...ProbeReportFactory::samplePayload(), 'redirects_to_https' => null],
    ]);

    ($this->evaluate)();

    expect(($this->finding)('https_redirect_missing'))->toBeNull();
});

/*
 | Version disclosure
 |-------------------------------------------------------------------------------------------------
 */

it('flags the version and not the header', function (): void {
    /*
     * The distinction the whole rule turns on. "Server: nginx" is something an attacker could have
     * guessed; "Server: nginx/1.24.0" is a build to look up in an advisory database. Flagging the
     * presence of a Server header would fire on nearly every site on the web and be muted within a
     * week, and the sites that then stopped being read are the ones publishing a patch level.
     */
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
        'server' => 'nginx',
    ]);

    ($this->evaluate)();

    expect(($this->finding)('software_version_disclosed'))->toBeNull();
});

it('reports a version somebody could look up', function (): void {
    ($this->probeWith)([
        'strict-transport-security' => 'max-age=31536000',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'SAMEORIGIN',
        'server' => 'nginx/1.24.0',
        'x-powered-by' => 'PHP/8.3.14',
    ]);

    ($this->evaluate)();

    $finding = ($this->finding)('software_version_disclosed');

    expect($finding?->severity)->toBe(Severity::LOW)
        ->and($finding?->evidence)->toBe(['Server' => 'nginx/1.24.0', 'X-Powered-By' => 'PHP/8.3.14'])
        // The remedy, because it is one line and lasts forever, which is the only reason this earns
        // a row at all.
        ->and($finding?->detail)->toContain('server_tokens off');
});

/*
 | Staleness
 |-------------------------------------------------------------------------------------------------
 */

it('says nothing at all about a site that has never been probed', function (): void {
    ($this->evaluate)();

    foreach ([
        'security_headers_missing',
        'csp_not_enforced',
        'https_redirect_missing',
        'software_version_disclosed',
    ] as $rule) {
        expect(($this->finding)($rule))->toBeNull();
    }
});

it('draws nothing from a probe that failed', function (): void {
    // A site nobody could reach has no headers to be missing, and reporting it as though it served
    // none would be a finding about the wrong thing entirely.
    ProbeReport::factory()->for($this->site)->create([
        'payload' => ['error' => 'The site did not answer over HTTPS.'],
        'status' => null,
        'error' => 'The site did not answer over HTTPS.',
    ]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing'))->toBeNull();
});

it('does not resolve everything on one missed sweep', function (): void {
    /*
     * The staleness window is two days rather than the six hours the sign-in reports get, because
     * the sweep behind it runs once a day. A tighter window would resolve every header finding in
     * the fleet the morning after one failed run and reopen them all the morning after that, which
     * turns a findings list into a notification storm about nothing.
     */
    ProbeReport::factory()->for($this->site)->bare()->create(['probed_at' => now()->subHours(30)]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing'))->not->toBeNull();

    // And a genuinely stale one stops being evidence.
    ProbeReport::query()->update(['probed_at' => now()->subDays(4)]);

    ($this->evaluate)();

    expect(($this->finding)('security_headers_missing')?->state)->toBe(Finding::STATE_RESOLVED);
});

/*
 | Files that should not be reachable
 |-------------------------------------------------------------------------------------------------
 |
 | The most serious finding this platform can raise, and therefore the one where being wrong is most
 | expensive: it names a customer's site and tells somebody their credentials are public. Everything
 | below is about the gate that stops it saying so when the evidence does not support it.
 */

it('reports a readable environment file as critical', function (): void {
    ProbeReport::factory()->for($this->site)->exposing('/.env')->create();

    ($this->evaluate)();

    $finding = ($this->finding)('sensitive_file_exposed');

    expect($finding?->severity)->toBe(Severity::CRITICAL)
        ->and($finding?->evidence['paths'])->toBe(['/.env'])
        // Moving the file is not the remedy. The credentials have been published and have to be
        // treated that way, which is the part somebody skips.
        ->and($finding?->detail)->toContain('rotate them');
});

it('will not say a file is exposed on a site that answers 200 for anything', function (): void {
    /*
     * The single most damaging way this rule could be wrong. A catch-all route or a permissive proxy
     * fallback answers 200 for every path, and the loudest finding in the product, naming a
     * customer's site and their credentials, would land on sites whose only fault is routing.
     *
     * The gate is on the model rather than in this rule, so it cannot be forgotten by the next rule
     * that wants to read the same list.
     */
    ProbeReport::factory()->for($this->site)->answersEverything()->exposing('/.env')->create();

    ($this->evaluate)();

    expect(($this->finding)('sensitive_file_exposed'))->toBeNull();
});

it('ranks a lockfile below credentials', function (): void {
    // Not itself a leak - it is the exact version of every dependency, which is a shopping list of
    // published vulnerabilities rather than a way in on its own.
    ProbeReport::factory()->for($this->site)->exposing('/composer.lock')->create();

    ($this->evaluate)();

    expect(($this->finding)('sensitive_file_exposed')?->severity)->toBe(Severity::HIGH);
});

it('says nothing about a site with nothing reachable', function (): void {
    ProbeReport::factory()->for($this->site)->create();

    ($this->evaluate)();

    expect(($this->finding)('sensitive_file_exposed'))->toBeNull();
});

it('keeps a size in the evidence and no part of the contents', function (): void {
    // A size tells a real file apart from a zero-byte placeholder somebody left behind. Anything
    // more would be storing what the check exists to say should not be readable.
    ProbeReport::factory()->for($this->site)->exposing('/.env')->create();

    ($this->evaluate)();

    $evidence = ($this->finding)('sensitive_file_exposed')?->evidence;

    expect(array_keys($evidence))->toBe(['paths', 'sizes'])
        ->and($evidence['sizes'])->toBe(['/.env' => 812]);
});
