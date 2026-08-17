<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Connector\NudgeDispatcher;
use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\ResolvedDestination;
use App\Domain\Notifications\UnsafeDestinationException;
use App\Models\Site;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * What a site looks like to somebody who is not it.
 *
 * The second thing in Manager the platform goes and observes itself, after the TLS certificate, and
 * for the same reason: the connector cannot answer this and never will be able to. A response header
 * is added or removed by whatever serves the response - nginx, a CDN, a WAF - and PHP on the origin
 * sees none of that. A site behind Cloudflare with `Strict-Transport-Security` set at the edge and a
 * site with it set in `web.config` look identical from inside and identical to a visitor; a site
 * whose CDN *strips* a header the origin sets looks fine from inside and is not.
 *
 * This is the difference worth stating plainly, because it is a real advantage rather than a
 * consolation. A per-site plugin checking its own headers is checking them at the wrong end of the
 * chain, and can be wrong in both directions - passing a site whose edge removes what the origin
 * sends, and failing one whose edge adds what the origin does not. Manager asks the question from
 * where the answer matters, so the screen says so.
 *
 * ## The rules this operates under
 *
 * These are the same ones {@see NudgeDispatcher} operates under, and they are copied
 * deliberately rather than loosened for a check that makes more requests than it does.
 *
 *  - **The destination is composed here, from `expected_domain`.** This class takes a Site and
 *    nothing else. A second parameter is how a class that composes its own destination becomes one
 *    that accepts a destination, and that is the whole SSRF surface.
 *  - **The paths are a constant in this file.** Never configuration, never a parameter, never
 *    anything that arrived from a site. `SENSITIVE_PATHS` is the entire vocabulary.
 *  - **Re-validated every time**, through {@see OutboundUrlGuard}. A hostname that was a customer's
 *    site last week can point at a metadata service today.
 *  - **The connection is pinned to the validated address** and redirects are not followed, because a
 *    302 to a private address is the simplest way around a check on the original URL.
 *  - **No response body is read.** Every request discards it. For the sensitive paths the method is
 *    `HEAD`, which means a database dump sitting in a webroot is never transferred - see below.
 *
 * ## Why HEAD, with no fallback to GET
 *
 * The tempting fallback is: try `HEAD`, and if the server answers 405, try `GET` and throw the body
 * away. It must not, and the reason is `/backup.sql`. Discarding a body still transfers it, and a
 * database dump left in a webroot is exactly the thing this check exists to find - so the fallback
 * would pull several gigabytes off a customer's server, over their bandwidth, in order to tell them
 * that file should not be there. A server that refuses `HEAD` gets no exposure answer at all, and
 * that is recorded as no answer rather than as an all-clear.
 */
final class SiteProbe
{
    /**
     * Paths asked about, in full.
     *
     * A constant rather than configuration, and it does not grow without somebody thinking about it.
     * Each of these is a file that means something specific when a web server hands it over:
     *
     *  - `/.env` - every credential the site has, including the database and any API keys.
     *  - `/.git/config` - proof the whole repository is downloadable, history included, which
     *    usually means the credentials in it are too.
     *  - `/composer.lock` - the exact version of every dependency, which is a shopping list of known
     *    vulnerabilities rather than a leak in itself.
     *  - The dumps - somebody's entire database, available to anybody who guesses the filename.
     *    These are the reason this check is `HEAD` only.
     *
     * @var list<string>
     */
    private const SENSITIVE_PATHS = [
        '/.env',
        '/.git/config',
        '/composer.json',
        '/composer.lock',
        '/backup.sql',
        '/database.sql',
        '/db.sql.gz',
    ];

    /**
     * A path that does not exist and will not come to.
     *
     * Fixed rather than random, so two probes of the same site are the same request and a site's
     * access log shows one repeated entry instead of a stream of unique ones that reads like
     * somebody scanning it. Prefixed so that an operator finding it in a log can search for it and
     * arrive at the right answer.
     */
    private const CONTROL_PATH = '/.manager-probe-control-does-not-exist';

    /**
     * Response headers worth keeping, all of which the site serves to every visitor.
     *
     * An allowlist, so a site cannot cause arbitrary strings to be stored on this platform by
     * inventing headers. Everything here is either a security control or the absence of one, plus
     * the two disclosure headers and the compression one.
     *
     * @var list<string>
     */
    private const KEPT_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'content-security-policy-report-only',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
        'server',
        'x-powered-by',
        'content-encoding',
    ];

    /**
     * How much of a header value is kept.
     *
     * A policy header is long by nature and the whole of it is the useful part, so it gets more room
     * than the rest. Everything stored here is public - the site sends it to every visitor - but a
     * truncated value is still the right default for something written to a database by a scheduled
     * job that runs against every site in a fleet.
     */
    private const MAX_POLICY_LENGTH = 1024;

    private const MAX_HEADER_LENGTH = 255;

    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * Look at one site from outside.
     *
     * Takes a Site and nothing else, on purpose - see the class docblock. Never throws: a probe that
     * could fail a scheduled command would mean one unreachable site stopping the sweep for every
     * other one.
     */
    public function probe(Site $site): ProbeReading
    {
        $host = strtolower(trim($site->expected_domain));

        if ($host === '' || ! preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $host)) {
            return ProbeReading::failed('That is not a hostname this check can look up.');
        }

        try {
            $secure = $this->guard->resolve('https://'.$host.'/');
        } catch (UnsafeDestinationException) {
            return ProbeReading::failed('That domain resolves to a private, loopback or metadata address.');
        }

        $home = $this->request($secure, 'GET', '/');

        if ($home === null) {
            // Nothing answered over HTTPS. That is a site which is down, moved or firewalled, and
            // reporting it as "no security headers" would be a finding about the wrong thing
            // entirely - a site nobody can reach has no headers to be missing.
            return ProbeReading::failed('The site did not answer over HTTPS.');
        }

        $control = $this->request($secure, 'HEAD', self::CONTROL_PATH);

        // Null when the control request itself failed, which is different from a site that answered
        // it. Both mean the exposure results below cannot be trusted, and both are recorded.
        $answersEverything = $control === null ? null : $this->looksPresent($control->getStatusCode());

        return new ProbeReading(
            status: $home->getStatusCode(),
            headers: $this->headers($home),
            duplicated: $this->duplicated($home),
            redirectsToHttps: $this->redirectsToHttps($host),

            // Skipped entirely when the control came back 200. Making seven more requests to a site
            // whose answers cannot mean anything is work done to produce a finding that would be
            // wrong.
            exposed: $answersEverything === true ? [] : $this->exposedPaths($secure),
            answersEverything: $answersEverything,
        );
    }

    /**
     * Whether plain HTTP is sent to HTTPS.
     *
     * The one request in this class that is not made over TLS, and it has to be: the question is
     * what the site does on port 80, and asking it on 443 cannot answer that. Nothing travels on it —
     * `GET /`, no credential, no signature, not even the site's name.
     *
     * Null rather than false when port 80 does not answer at all. A site whose host simply does not
     * listen on 80 has nothing to redirect and is not misconfigured; reporting it as "does not
     * redirect" would be a finding about an absence of a problem.
     */
    private function redirectsToHttps(string $host): ?bool
    {
        try {
            $plain = $this->guard->resolvePlainHttp('http://'.$host.'/');
        } catch (UnsafeDestinationException) {
            return null;
        }

        $response = $this->request($plain, 'GET', '/', secure: false);

        if ($response === null) {
            return null;
        }

        $status = $response->getStatusCode();

        if ($status < 300 || $status >= 400) {
            // Answered on port 80 without redirecting. Whatever it served, it served in the clear.
            return false;
        }

        $location = $response->getHeaderLine('Location');

        // A redirect to another plain-HTTP address is not a redirect to HTTPS, and a relative one
        // stays on the scheme it came in on.
        return str_starts_with(strtolower($location), 'https://');
    }

    /**
     * Ask for each sensitive path in turn.
     *
     * @return list<array{path: string, status: int, bytes: int|null}>
     */
    private function exposedPaths(ResolvedDestination $destination): array
    {
        $found = [];

        foreach (self::SENSITIVE_PATHS as $path) {
            $response = $this->request($destination, 'HEAD', $path);

            if ($response === null) {
                continue;
            }

            $status = $response->getStatusCode();

            if (! $this->looksPresent($status)) {
                continue;
            }

            $length = $response->getHeaderLine('Content-Length');

            $found[] = [
                'path' => $path,
                'status' => $status,

                // A size, because "0 bytes" and "8 megabytes" are different situations at the same
                // path - the first is often a placeholder somebody left, the second is the file.
                // Never the contents: this is a HEAD request and there is no body to have.
                'bytes' => is_numeric($length) ? (int) $length : null,
            ];
        }

        return $found;
    }

    /**
     * Whether a status means the server handed something over.
     *
     * 200 and 206 only. A 401 or a 403 means the file is there and protected, which is the correct
     * configuration rather than a finding - and a 301 to a login page is a site doing its job.
     */
    private function looksPresent(int $status): bool
    {
        return $status === 200 || $status === 206;
    }

    /**
     * The headers worth keeping, lowercased and truncated.
     *
     * @return array<string, string>
     */
    private function headers(ResponseInterface $response): array
    {
        $kept = [];

        foreach (self::KEPT_HEADERS as $name) {
            $value = trim($response->getHeaderLine($name));

            if ($value === '') {
                continue;
            }

            $limit = str_starts_with($name, 'content-security-policy') || $name === 'permissions-policy'
                ? self::MAX_POLICY_LENGTH
                : self::MAX_HEADER_LENGTH;

            $kept[$name] = mb_substr($value, 0, $limit);
        }

        return $kept;
    }

    /**
     * Header names the site sent more than once.
     *
     * Worth its own field because two `X-Frame-Options` headers with different values is not a
     * stricter site, it is an undefined one - browsers disagree about which wins, and the usual
     * cause is the application and the web server each setting it without knowing about the other.
     * It is invisible from inside the site for exactly that reason: each half is doing its job.
     *
     * @return list<string>
     */
    private function duplicated(ResponseInterface $response): array
    {
        $names = [];

        foreach (self::KEPT_HEADERS as $name) {
            if (count($response->getHeader($name)) > 1) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * One request, or null.
     *
     * Null on every kind of failure without distinguishing between them. A reason string here would
     * be a curl message, and those name addresses, resolvers and file paths - the same reason
     * {@see CertificateInspector} reduces its failures to fixed phrases.
     */
    private function request(ResolvedDestination $destination, string $method, string $path, bool $secure = true): ?ResponseInterface
    {
        $scheme = $secure ? 'https' : 'http';

        try {
            return $this->client($destination, $secure)->request($method, $scheme.'://'.$destination->host.$path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The same posture as every other outbound request this application makes.
     */
    private function client(ResolvedDestination $destination, bool $secure): Client
    {
        return new Client([
            'connect_timeout' => 5,
            'timeout' => 10,

            // A 302 to a private address is the simplest way around a check on the original URL —
            // and for the redirect check itself, following the redirect would destroy the answer.
            'allow_redirects' => false,

            // A 404 is data, not an exception. Every status this makes a decision on is one Guzzle
            // would otherwise throw for.
            'http_errors' => false,

            // Nothing a site returns is read. This is the line that makes that true rather than
            // aspirational.
            'sink' => '/dev/null',

            'headers' => [
                // Identifies the check in a customer's access log, so somebody finding these entries
                // can tell what they are rather than reporting a scanner.
                'User-Agent' => 'Manager/1.0 (+security-check)',

                // Asked for, so the site is given the chance to say whether it compresses. Nothing
                // is decompressed - there is no body to decompress.
                'Accept-Encoding' => 'gzip, br',
            ],

            'curl' => [
                CURLOPT_RESOLVE => [$destination->curlResolveEntry()],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => $secure ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            ],
        ]);
    }
}
