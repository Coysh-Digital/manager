<?php

declare(strict_types=1);

use App\Domain\Findings\Rules\CertificateExpiring;
use App\Domain\Findings\Rules\CertificateUntrusted;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;
use App\Domain\Security\CertificateInspector;
use App\Domain\Security\CertificateNames;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;

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

    // Only the screen tests at the foot of this file need a reader. Created here rather than in each
    // of them because the site factory above is bound to this organisation, and a member of a
    // different one would get a 404 that looked like a rendering failure.
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();
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

/*
|--------------------------------------------------------------------------------------------------
| Whether a browser would accept it at all
|--------------------------------------------------------------------------------------------------
|
| Expiry is the question this check has always answered. These are the three it did not, and every
| one of them is a certificate that fails today rather than in three weeks - so a fleet screen
| reading "expires in 88 days" against any of them was not merely incomplete, it was reassuring.
*/

it('judges a wildcard by the rule browsers use, not the one people expect', function (string $host, bool $covered): void {
    // The apex case is the one that bites. A site on example.org holding only *.example.org is a
    // live outage, and a looser match here would report it as fine.
    $parsed = ['extensions' => ['subjectAltName' => 'DNS:*.example.org']];

    expect(CertificateNames::matches($parsed, $host))->toBe($covered);
})->with([
    'one label' => ['www.example.org', true],
    'a different label' => ['shop.example.org', true],
    'the apex itself' => ['example.org', false],
    'two labels deep' => ['a.b.example.org', false],
    'a domain that merely ends the same way' => ['notexample.org', false],
    'a different domain entirely' => ['example.com', false],
]);

it('judges a certificate on its subject alternative names alone when it has them', function (): void {
    // Every browser has worked this way since 2017: SANs present means the common name is
    // decorative. Reading the CN as a fallback here would pass a certificate visitors are refused.
    $parsed = [
        'subject' => ['CN' => 'example.org'],
        'extensions' => ['subjectAltName' => 'DNS:something-else.org'],
    ];

    expect(CertificateNames::matches($parsed, 'example.org'))->toBeFalse();
});

it('falls back to the common name only when there are no alternative names', function (): void {
    expect(CertificateNames::matches(['subject' => ['CN' => 'example.org']], 'example.org'))->toBeTrue()
        ->and(CertificateNames::matches(['subject' => ['CN' => 'other.org']], 'example.org'))->toBeFalse();
});

it('ignores alternative names that are not hostnames', function (): void {
    // An IP entry cannot cover a hostname, and treating one as a candidate would be a way to pass a
    // certificate a browser refuses.
    $parsed = ['extensions' => ['subjectAltName' => 'IP:203.0.113.4, email:ops@example.org, DNS:example.org']];

    expect(CertificateNames::matches($parsed, 'example.org'))->toBeTrue()
        ->and(CertificateNames::matches($parsed, '203.0.113.4'))->toBeFalse();
});

it('says nothing rather than no when a certificate names nothing to compare against', function (): void {
    // A certificate that fails and a check with nothing to go on are different answers, and the
    // second must not render as the first.
    expect(CertificateNames::matches([], 'example.org'))->toBeNull()
        ->and(CertificateNames::matches(['subject' => ['O' => 'Acme Ltd']], 'example.org'))->toBeNull();
});

it('reports a certificate issued for somebody else\'s domain', function (): void {
    $site = ($this->site)([
        'expected_domain' => 'example.org',
        'certificate_checked_at' => now(),
        'certificate_expires_at' => now()->addDays(300),
        'certificate_subject' => 'other-client.com',
        'certificate_hostname_matches' => false,
        'certificate_trusted' => false,
    ]);

    $match = (new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, []));

    expect($match)->not->toBeNull()
        ->and($match->severity)->toBe(Severity::HIGH)
        ->and($match->title)->toContain('different domain')
        // The wrong name outranks the untrusted chain it also produced. One bad install, one finding.
        ->and($match->detail)->toContain('other-client.com');
});

it('reports a self-signed certificate ahead of the chain problem it also causes', function (): void {
    $site = ($this->site)([
        'certificate_checked_at' => now(),
        'certificate_hostname_matches' => true,
        'certificate_self_signed' => true,
        'certificate_trusted' => false,
    ]);

    $match = (new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, []));

    expect($match->severity)->toBe(Severity::HIGH)
        ->and($match->title)->toContain('self-signed');
});

it('ranks an incomplete chain below the other two, and says why', function (): void {
    $site = ($this->site)([
        'certificate_checked_at' => now(),
        'certificate_hostname_matches' => true,
        'certificate_self_signed' => false,
        'certificate_trusted' => false,
        'certificate_chain_length' => 1,
    ]);

    $match = (new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, []));

    // Medium rather than high, because desktop browsers hide it. The detail has to say so, or the
    // reader checks the site in Chrome, sees nothing wrong and dismisses the finding.
    expect($match->severity)->toBe(Severity::MEDIUM)
        ->and($match->title)->toContain('chain is incomplete')
        ->and($match->detail)->toContain('Android')
        ->and($match->detail)->toContain('1 certificate');
});

it('says nothing about a certificate with nothing wrong with it', function (): void {
    $site = ($this->site)([
        'certificate_checked_at' => now(),
        'certificate_expires_at' => now()->addDays(300),
        'certificate_hostname_matches' => true,
        'certificate_trusted' => true,
        'certificate_self_signed' => false,
        'certificate_chain_length' => 3,
    ]);

    expect((new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, [])))->toBeNull();
});

it('treats an unjudged certificate as unjudged rather than as a failure', function (array $attributes): void {
    /*
     * The expensive way for this rule to be wrong. Null means the check could not tell - never swept,
     * host did not answer, or a Manager installation on a container with no certificate authorities —
     * and a rule reading null as false would open a high-severity finding against every site in a
     * fleet on the same morning, each of them describing this server rather than the site it names.
     */
    $site = ($this->site)($attributes);

    expect((new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, [])))->toBeNull();
})->with([
    'never checked' => [['certificate_checked_at' => null, 'certificate_trusted' => null]],
    'checked but nothing determined' => [[
        'certificate_checked_at' => now(),
        'certificate_hostname_matches' => null,
        'certificate_trusted' => null,
        'certificate_self_signed' => null,
    ]],
]);

it('does not open a second finding on the day a certificate expires', function (): void {
    // Expiry belongs to CertificateExpiring entirely. Two findings with different titles, different
    // thresholds and the same remedy is the failure mode of folding it in here.
    $site = ($this->site)([
        'certificate_checked_at' => now(),
        'certificate_expires_at' => now()->subDay(),
        'certificate_hostname_matches' => true,
        'certificate_trusted' => true,
        'certificate_self_signed' => false,
    ]);

    expect((new CertificateUntrusted)->evaluate(new Snapshot($site, null, null, [])))->toBeNull()
        ->and((new CertificateExpiring)->evaluate(new Snapshot($site, null, null, [])))->not->toBeNull();
});

it('clears a previous judgement when a later check could not make one', function (): void {
    $site = ($this->site)([
        'expected_domain' => 'localhost',
        'certificate_trusted' => false,
        'certificate_hostname_matches' => false,
        'certificate_self_signed' => true,
        'certificate_chain_length' => 1,
    ]);

    $this->artisan('manager:certificates:check')->assertSuccessful();

    // localhost is refused by the guard, so nothing was judged. A "not trusted" badge from last week
    // sitting beside today's failed check is a claim about the certificate that nothing has checked.
    $site->refresh();

    expect($site->certificate_trusted)->toBeNull()
        ->and($site->certificate_hostname_matches)->toBeNull()
        ->and($site->certificate_self_signed)->toBeNull()
        ->and($site->certificate_chain_length)->toBeNull();
});

/*
|--------------------------------------------------------------------------------------------------
| What the screen says
|--------------------------------------------------------------------------------------------------
*/

it('shows the certificate whether or not anything is wrong with it', function (): void {
    // Findings say what is broken. This panel says what was looked at - and the two answers most
    // worth keeping apart are "checked, and fine" and "never checked", which without it look
    // identical because both show nothing.
    $site = ($this->site)([
        'certificate_checked_at' => now(),
        'certificate_expires_at' => now()->addDays(300),
        'certificate_issuer' => "Let's Encrypt",
        'certificate_hostname_matches' => true,
        'certificate_trusted' => true,
        'certificate_self_signed' => false,
        'certificate_chain_length' => 3,
    ]);

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        ->assertSee('TLS certificate')
        ->assertSee('Chain trusted')
        ->assertSee("Let's Encrypt");
});

it('says a certificate was not checked rather than showing it as passing', function (): void {
    $site = ($this->site)(['certificate_checked_at' => null]);

    $this->actingAs($this->owner)
        ->get(route('sites.security', $site))
        ->assertOk()
        ->assertSee('Not checked yet');
});
