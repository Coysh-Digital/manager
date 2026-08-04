<?php

declare(strict_types=1);

use App\Domain\Findings\Rules\CertificateExpiring;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;
use App\Domain\Security\CertificateInspector;
use App\Models\Organisation;
use App\Models\Site;

/**
 * TLS certificate monitoring, which is the one thing this platform goes and looks at itself.
 *
 * Everything else about a site is reported by its connector, deliberately - a platform that reaches
 * into the sites it manages is a platform worth attacking. A certificate is the exception because the
 * connector genuinely cannot see it: TLS terminates at the edge, so PHP on the origin sees whatever a
 * CDN or load balancer put in `$_SERVER`, which is not what a visitor's browser validates.
 *
 * That makes the outbound connection the thing to be careful about, so most of this file is about
 * where it will and will not go.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
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

it('refuses to connect to an address that is not a public host', function (string $domain): void {
    // The reason this needs guarding at all: the hostname is one an operator typed, and a domain
    // resolving to 169.254.169.254 would turn a monitoring check into a request for cloud instance
    // credentials. The same guard that protects notification destinations protects this.
    $reading = app(CertificateInspector::class)->inspect($domain);

    expect($reading->succeeded())->toBeFalse()
        ->and($reading->expiresAt)->toBeNull()
        ->and($reading->error)->not->toBeNull();
})->with([
    'metadata service' => '169.254.169.254',
    'loopback by name' => 'localhost',
    'private range by name' => 'localhost.localdomain',
]);

it('refuses anything that is not a hostname', function (string $value): void {
    $reading = app(CertificateInspector::class)->inspect($value);

    expect($reading->succeeded())->toBeFalse()
        ->and($reading->error)->toContain('hostname');
})->with([
    'empty' => '',
    'a URL' => 'https://example.com/path',
    'with a port' => 'example.com:443',
    'an address' => '93.184.216.34',
    'a path traversal' => '../etc/passwd',
]);

it('never puts a system message into what it stores', function (): void {
    // These strings are stored on the site row and rendered. A resolver's own message can name an
    // address, a search domain or a path, so it is replaced with a fixed phrase rather than passed on.
    $reading = app(CertificateInspector::class)
        ->inspect('this-host-does-not-exist-'.bin2hex(random_bytes(6)).'.invalid');

    expect($reading->succeeded())->toBeFalse()
        ->and($reading->error)->not->toContain('getaddrinfo')
        ->and($reading->error)->not->toContain('php_network')
        ->and(strlen((string) $reading->error))->toBeLessThan(120);
});

/*
|--------------------------------------------------------------------------------------------------
| What gets recorded
|--------------------------------------------------------------------------------------------------
*/

it('records that a site could not be reached without inventing an expiry', function (): void {
    $site = ($this->site)(['expected_domain' => 'localhost']);

    $this->artisan('manager:certificates:check')->assertSuccessful();

    $site->refresh();

    // "We could not reach this site" and "this certificate expires on Tuesday" are different facts.
    // A screen showing an unreachable site as having no expiry would look exactly like a site with a
    // problem it does not have.
    expect($site->certificate_checked_at)->not->toBeNull()
        ->and($site->certificate_expires_at)->toBeNull()
        ->and($site->certificate_error)->not->toBeNull();
});

it('leaves an archived site alone', function (): void {
    $archived = ($this->site)(['expected_domain' => 'localhost', 'archived_at' => now()->subDay()]);

    $this->artisan('manager:certificates:check')->assertSuccessful();

    // A site somebody has finished with should not keep generating findings, and should certainly not
    // keep generating outbound connections to a domain that may now belong to somebody else.
    expect($archived->fresh()->certificate_checked_at)->toBeNull();
});

it('clears a stale error when a site recovers', function (): void {
    $site = ($this->site)([
        'expected_domain' => 'localhost',
        'certificate_error' => 'something from last week',
        'certificate_expires_at' => now()->addYear(),
    ]);

    $this->artisan('manager:certificates:check')->assertSuccessful();

    // Not asserting success - localhost is refused - but asserting the row is rewritten wholesale
    // rather than merged. A stale error beside a fresh expiry is the kind of thing somebody acts on.
    expect($site->fresh()->certificate_expires_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------------------------------
| What an operator is told
|--------------------------------------------------------------------------------------------------
*/

it('says nothing about a certificate with plenty of life left', function (): void {
    $site = ($this->site)(['certificate_expires_at' => now()->addDays(90)]);

    expect((new CertificateExpiring)->evaluate(new Snapshot($site, null, null, [])))->toBeNull();
});

it('says nothing about a site whose certificate was never read', function (): void {
    // Never checked, or the check could not get there. Neither is a statement about the certificate,
    // and guessing would send somebody to look at the wrong thing.
    $site = ($this->site)(['certificate_expires_at' => null, 'certificate_error' => 'unreachable']);

    expect((new CertificateExpiring)->evaluate(new Snapshot($site, null, null, [])))->toBeNull();
});

it('escalates as expiry approaches', function (int $days, string $severity): void {
    $site = ($this->site)(['certificate_expires_at' => now()->addDays($days)]);

    $match = (new CertificateExpiring)->evaluate(new Snapshot($site, null, null, []));

    expect($match)->not->toBeNull()
        ->and($match->severity)->toBe($severity);
})->with([
    'a month out is worth noticing' => [25, Severity::MEDIUM],
    'a week out needs doing today' => [5, Severity::HIGH],
    'tomorrow' => [1, Severity::HIGH],
]);

it('reports an expired certificate as the outage it already is', function (): void {
    $site = ($this->site)(['certificate_expires_at' => now()->subDays(3)]);

    $match = (new CertificateExpiring)->evaluate(new Snapshot($site, null, null, []));

    expect($match->severity)->toBe(Severity::HIGH)
        ->and($match->title)->toContain('has expired')
        // Including the consequence people do not think of: the site's own connector talks over HTTPS.
        ->and($match->detail)->toContain('connector');
});

it('keeps its evidence to the smallest thing that supports the conclusion', function (): void {
    $site = ($this->site)([
        'certificate_expires_at' => now()->addDays(3),
        'certificate_issuer' => "Let's Encrypt",
    ]);

    $match = (new CertificateExpiring)->evaluate(new Snapshot($site, null, null, []));

    expect(array_keys($match->evidence))->toBe(['expires_at', 'issuer', 'days_remaining']);
});
