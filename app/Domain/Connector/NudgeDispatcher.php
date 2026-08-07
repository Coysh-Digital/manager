<?php

declare(strict_types=1);

namespace App\Domain\Connector;

use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\ResolvedDestination;
use App\Domain\Notifications\UnsafeDestinationException;
use App\Models\Site;
use coyshdigital\managerprotocol\CanonicalNudge;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks a site to check in now.
 *
 * This is the second thing in Manager that reaches out to a managed site rather than waiting to be
 * told, after the certificate inspector - and unlike that one it is authenticated, because it causes
 * something rather than only observing.
 *
 * **What it can say is "check in".** There is no parameter for a job, a capability or a destination,
 * and the canonical string it signs has nowhere to put one. A site that receives a nudge makes the
 * ordinary signed claim it would have made on its own a few minutes later, and everything that decides
 * anything happens there: the capability is re-checked at claim time, the recipients are the ones the
 * site has pinned, the artifact goes where the site derives rather than where anybody says. So the
 * worst outcome of this class being wrong - wrong site, forged, replayed - is one early poll.
 *
 * **The address is composed, never accepted.** The host is `sites.expected_domain`, which an operator
 * typed and pairing is bound to; only the path comes from the wire, and only after
 * {@see NudgePath::sanitise()} has understood all of it. That is deliberately the mirror image of the
 * rule the connector enforces on itself, where an upload host is derived from the configured platform
 * URL rather than taken from a response - *a host the other side chose is a host a compromised other
 * side chose.* Both directions now pin to something a person stated.
 *
 * **Nothing depends on it working.** Every failure here is a site that finds out on its own schedule,
 * which is what happened before this existed. It is never surfaced as an error, never retried into a
 * queue backlog, and never blocks the request that queued the job.
 */
final class NudgeDispatcher
{
    /**
     * Consecutive failures after which this platform stops trying until the site says otherwise.
     *
     * Without a ceiling, a site behind NAT or a WAF would be knocked on every time anybody pressed a
     * button, forever, with the same outcome each time. The count resets whenever a nudge lands, and
     * whenever the site restates its path on a claim - so a site that becomes reachable again heals
     * without anybody intervening.
     */
    public const FAILURE_CEILING = 5;

    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly PlatformKeypair $keypair,
    ) {}

    /**
     * Where a nudge for this site would go, or null if it should not be sent.
     *
     * Composed rather than read. Note what is *not* consulted: nothing the connector reported beyond
     * the path, and no column holding an address, because there is none.
     */
    public function destinationFor(Site $site): ?string
    {
        if (! config('manager.connector.nudge.enabled')) {
            return null;
        }

        $connector = $site->activeConnector()->first();

        if ($connector === null) {
            return null;
        }

        $path = NudgePath::sanitise($connector->nudge_path);

        if ($path === null) {
            return null;
        }

        if ($connector->nudge_failures >= self::FAILURE_CEILING) {
            return null;
        }

        $domain = trim((string) $site->expected_domain);

        if ($domain === '') {
            return null;
        }

        // A connector may pair from a domain an operator approved despite it differing from the one
        // recorded. In that case `expected_domain` is not where the site answers, and knocking on it
        // would mean sending a signed nudge naming this site to a host that is not it.
        if ($connector->submitted_domain !== null && $connector->submitted_domain !== $domain) {
            return null;
        }

        return 'https://'.$domain.$path;
    }

    /**
     * Whether this site would be knocked on, were something queued for it right now.
     *
     * For screen copy, and only for that. It says a nudge will be *attempted*, never that one will
     * arrive - the site may be behind a firewall that answers nothing, and this platform will not know
     * until it has tried. So anything written from this must promise an attempt rather than an outcome.
     */
    public function canReach(Site $site): bool
    {
        return $this->destinationFor($site) !== null;
    }

    /**
     * Knock, and record whether anybody answered.
     *
     * Takes a site and nothing else, on purpose. A second parameter is how a class that composes its
     * own destination becomes one that accepts a destination, and there is a test asserting this
     * signature for that reason.
     */
    public function dispatch(Site $site): void
    {
        $url = $this->destinationFor($site);

        if ($url === null || ! $this->keypair->isConfigured()) {
            return;
        }

        try {
            // Re-validated on every send, exactly as a notification destination is. A hostname that
            // was a customer's site last week can point at a metadata service today, and the domain
            // here is one an operator typed rather than one this platform controls.
            $resolved = $this->guard->resolve($url);
        } catch (UnsafeDestinationException) {
            // Not counted as a failure. It is a statement about the address rather than about whether
            // the site answered, and it will keep being true until somebody changes the domain.
            return;
        }

        $nudge = new CanonicalNudge(
            siteId: $site->external_id,
            timestamp: now()->timestamp,
            nonce: Nonce::generate(),
        );

        try {
            $response = $this->client($resolved)->post($resolved->url, [
                'body' => '',
                'headers' => [
                    'User-Agent' => 'Manager/1.0 (+nudge)',
                    Protocol::HEADER_SITE => $nudge->siteId,
                    Protocol::HEADER_TIMESTAMP => (string) $nudge->timestamp,
                    Protocol::HEADER_NONCE => $nudge->nonce,
                    Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME.'='
                        .$nudge->sign($this->keypair->secretKey()),
                ],
            ]);
        } catch (Throwable) {
            // The exception can carry the resolved address, so it is not passed anywhere.
            $this->recordFailure($site);

            return;
        }

        if ($response->getStatusCode() === 202) {
            $this->recordSuccess($site);

            return;
        }

        $this->recordFailure($site);
    }

    /**
     * The same posture as an outbound webhook, for the same reasons.
     *
     * Short timeouts because a site that accepts a connection and stalls must not hold a worker; no
     * redirects, because a 302 to a private address is the simplest way around a check on the original
     * URL; HTTPS only; the connection pinned to the address the guard validated so DNS cannot move
     * underneath; and the body discarded, because there is nothing a site could usefully say back.
     */
    private function client(ResolvedDestination $resolved): Client
    {
        return new Client([
            'connect_timeout' => 5,

            // Short even by those standards. The site answers before it does any work - that is what
            // its 202 means - so anything slower than this is not a site that is about to check in.
            'timeout' => 5,

            'allow_redirects' => false,
            'http_errors' => false,
            'sink' => '/dev/null',

            'curl' => [
                CURLOPT_RESOLVE => [$resolved->curlResolveEntry()],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ],
        ]);
    }

    private function recordSuccess(Site $site): void
    {
        $site->activeConnector()->first()?->forceFill([
            'nudge_succeeded_at' => now(),
            'nudge_failures' => 0,
        ])->save();
    }

    private function recordFailure(Site $site): void
    {
        $connector = $site->activeConnector()->first();

        if ($connector === null) {
            return;
        }

        $connector->forceFill(['nudge_failures' => $connector->nudge_failures + 1])->save();

        if ($connector->nudge_failures === self::FAILURE_CEILING) {
            // Once, at the ceiling, rather than on every attempt. An operator whose site cannot be
            // reached should be able to find out why without the log filling up with it.
            Log::info('Stopped nudging a site that has not answered.', [
                'site_id' => $site->id,
                'failures' => $connector->nudge_failures,
            ]);
        }
    }
}
