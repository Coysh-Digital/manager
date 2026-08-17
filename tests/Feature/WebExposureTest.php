<?php

declare(strict_types=1);

use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\UnsafeDestinationException;
use App\Domain\Security\SiteProbe;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\ProbeReport;
use App\Models\Site;
use App\Models\User;

/**
 * Looking at a site the way a visitor does.
 *
 * The second check this platform makes itself rather than waiting to be told, and the one that makes
 * the most requests to somebody else's server - so most of this file is about where those requests
 * will and will not go, and about the one result that must never be reported as a certainty.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->site = fn (array $attributes = []) => Site::factory()
        ->for($this->organisation)
        ->connected()
        ->create($attributes);
});

/*
|--------------------------------------------------------------------------------------------------
| Where the check will not go
|--------------------------------------------------------------------------------------------------
*/

it('refuses to probe an address that is not a public host', function (string $domain): void {
    // The same reasoning as the certificate check, and more of it: this one makes nine requests
    // rather than opening one socket, so a domain resolving to 169.254.169.254 would be nine
    // attempts at cloud instance credentials rather than one.
    $reading = app(SiteProbe::class)->probe(($this->site)(['expected_domain' => $domain]));

    expect($reading->succeeded())->toBeFalse()
        ->and($reading->status)->toBeNull()
        ->and($reading->error)->not->toBeNull();
})->with([
    'metadata service' => '169.254.169.254',
    'loopback by name' => 'localhost',
    'an address rather than a name' => '10.1.2.3',
    'a URL' => 'https://example.com/path',
    'empty' => '',
]);

it('keeps the plain-HTTP entry point on the same address rules as every other request', function (string $url): void {
    /*
     * The redirect check is the one place this application makes a request that is not over TLS, and
     * the risk of adding it is that the address checks come with it as a second, drifting copy. They
     * do not - resolve() and resolvePlainHttp() share one implementation - and this is the test that
     * keeps that true.
     */
    expect(fn () => app(OutboundUrlGuard::class)->resolvePlainHttp($url))
        ->toThrow(UnsafeDestinationException::class);
})->with([
    'metadata service' => ['http://169.254.169.254/'],
    'loopback' => ['http://127.0.0.1/'],
    'loopback v6' => ['http://[::1]/'],
    'rfc1918' => ['http://10.1.2.3/'],
    'carrier-grade nat' => ['http://100.64.1.1/'],
    'credentials in the url' => ['http://user:pass@example.org/'],
]);

it('will not let the plain-HTTP entry point be used for anything but plain HTTP', function (string $url): void {
    // Not "anything that is not https" - that would accept file://, gopher:// and every other
    // scheme curl has ever supported.
    expect(fn () => app(OutboundUrlGuard::class)->resolvePlainHttp($url))
        ->toThrow(UnsafeDestinationException::class);
})->with([
    'https' => ['https://example.org/'],
    'file' => ['file:///etc/passwd'],
    'gopher' => ['gopher://example.org/'],
    'no scheme' => ['example.org/'],
]);

it('leaves the HTTPS-only rule on notification destinations exactly where it was', function (): void {
    // Adding a plain-HTTP path for one check must not have relaxed the one that carries a webhook
    // naming which site has an outstanding security release.
    expect(fn () => app(OutboundUrlGuard::class)->resolve('http://hooks.example.org/manager'))
        ->toThrow(UnsafeDestinationException::class);
});

/*
|--------------------------------------------------------------------------------------------------
| What gets recorded
|--------------------------------------------------------------------------------------------------
*/

it('records that a site could not be reached without inventing an all-clear', function (): void {
    $site = ($this->site)(['expected_domain' => 'localhost']);

    $this->artisan('manager:web:check')->assertSuccessful();

    $report = ProbeReport::query()->where('site_id', $site->id)->first();

    // A site nobody can reach has no headers to be missing. Storing zeroes here would put every
    // unreachable site at the top of a "no security headers" list.
    expect($report)->not->toBeNull()
        ->and($report->succeeded())->toBeFalse()
        ->and($report->error)->not->toBeNull()
        ->and($report->status)->toBeNull()
        ->and($report->exposed_count)->toBeNull();
});

it('leaves an archived site alone', function (): void {
    $archived = ($this->site)(['expected_domain' => 'localhost', 'archived_at' => now()->subDay()]);

    $this->artisan('manager:web:check')->assertSuccessful();

    // A site somebody has finished with should not keep generating requests to a domain that may now
    // belong to somebody else - and this check makes nine of them.
    expect(ProbeReport::query()->where('site_id', $archived->id)->count())->toBe(0);
});

it('never puts a system message into what it stores', function (): void {
    $site = ($this->site)(['expected_domain' => 'this-host-does-not-exist-'.bin2hex(random_bytes(6)).'.invalid']);

    $this->artisan('manager:web:check')->assertSuccessful();

    $report = ProbeReport::query()->where('site_id', $site->id)->first();

    // These strings are stored and rendered. A curl message names addresses, resolvers and file
    // paths, so it is replaced with a fixed phrase rather than passed through.
    expect($report->error)->not->toContain('cURL')
        ->and($report->error)->not->toContain('getaddrinfo')
        ->and(strlen((string) $report->error))->toBeLessThan(120);
});

/*
|--------------------------------------------------------------------------------------------------
| The result that must never be reported as a certainty
|--------------------------------------------------------------------------------------------------
*/

it('draws no conclusion about files on a site that answers 200 for anything', function (): void {
    /*
     * The single most damaging way this check could be wrong. A catch-all route, a single-page front
     * end or a permissive proxy fallback answers 200 for any path, and on one of those "/.env
     * returned 200" is a fact about the routing rather than about the file.
     *
     * So a probe that saw the control path answer is inconclusive, and every reader has to go
     * through exposureIsConclusive() rather than reading `exposed` directly.
     */
    $report = ProbeReport::factory()
        ->for(($this->site)())
        ->answersEverything()
        ->exposing('/.env')
        ->create();

    expect($report->exposureIsConclusive())->toBeFalse()
        ->and($report->exposedPaths())->toBe([]);
});

it('reports a reachable file when the control says the answer means something', function (): void {
    $report = ProbeReport::factory()->for(($this->site)())->exposing('/.env')->create();

    expect($report->exposureIsConclusive())->toBeTrue()
        ->and($report->exposedPaths())->toHaveCount(1)
        ->and($report->exposedPaths()[0]['path'])->toBe('/.env');
});

/*
|--------------------------------------------------------------------------------------------------
| What the screen says
|--------------------------------------------------------------------------------------------------
*/

it('shows what a site serves, and says where it was checked from', function (): void {
    $site = ($this->site)();
    ProbeReport::factory()->for($site)->create();

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        ->assertSee('Served to the public')
        ->assertSee('max-age=31536000; includeSubDomains')
        ->assertSee('Redirected to HTTPS')
        // The vantage point is the value, so the panel has to state it rather than leave it implied.
        ->assertSee('Requested from this server');
});

it('marks a missing header rather than leaving a blank', function (): void {
    $site = ($this->site)();
    ProbeReport::factory()->for($site)->bare()->create();

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        ->assertSee('Not set')
        // The disclosure headers are shown because they are the finding, not despite it.
        ->assertSee('nginx/1.24.0');
});

it('says a site was not checked rather than showing it as clean', function (): void {
    $site = ($this->site)();

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        ->assertSee('Not checked yet');
});

it('says on screen when nothing can be concluded about files', function (): void {
    $site = ($this->site)();
    ProbeReport::factory()->for($site)->answersEverything()->exposing('/.env')->create();

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        // The path must not appear. Naming it beside an explanation is still naming it, and somebody
        // scanning the screen takes the filename and not the caveat.
        ->assertDontSee('/.env')
        ->assertSee('answers');
});
