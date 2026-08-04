<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\BackupSizeLimit;
use App\Domain\Connector\NonceStore;
use App\Models\Connector;
use App\Models\Site;
use App\Support\CorrelationId;
use Closure;
use coyshdigital\managerprotocol\CanonicalRequest;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates a connector by Ed25519 signature.
 *
 * Order is deliberate, cheapest and most protective first:
 *
 *   1. Rate limit by source network - before anything expensive happens.
 *   2. Payload size cap, before parsing.
 *   3. Required headers present and well formed.
 *   4. Timestamp within tolerance.
 *   5. Site and active connector lookup.
 *   6. Signature verification.
 *   7. Rate limit by site.
 *   8. Nonce claim.
 *
 * The nonce is claimed **last**, only once a request has proved authentic. Claiming earlier would
 * let anyone who can reach the endpoint fill the replay store with nonces that never belonged to a
 * real request.
 *
 * Every rejection returns the same body and status. A caller must not be able to distinguish "no
 * such site" from "bad signature", or the endpoint becomes an oracle for which site identifiers
 * exist.
 */
final class VerifyConnectorSignature
{
    /**
     * A syntactically valid key that no connector holds.
     *
     * Used to run a verification that is guaranteed to fail when the site is unknown, so that the
     * unknown-site path costs roughly what the bad-signature path costs. Without it, response time
     * alone would answer the question the uniform error refuses to.
     */
    private const DECOY_PUBLIC_KEY = 'wtTQtctkVi1Kxeol29zmBxJZiTP35EF6A51LAb12sRs=';

    public function __construct(
        private readonly NonceStore $nonceStore,
        private readonly RateLimiter $limiter,
        private readonly CorrelationId $correlationId,
        private readonly BackupSizeLimit $backupSize,
    ) {}

    /**
     * @param  string  $bodyMode  'body' to hash the request body, 'stream' to take the hash from a
     *                            header and leave the body unread
     */
    public function handle(Request $request, Closure $next, string $bodyMode = 'body'): Response
    {
        // The rate limiter is backed by the same shared store as replay protection, so it fails at
        // the same time. Treated as unavailable rather than allowed to throw: the decision is
        // identical either way, but this returns a correlation identifier and a status a connector
        // can act on, instead of a 500 that tells it nothing.
        try {
            if ($this->tooManyFrom($request->ip())) {
                return $this->throttled();
            }
        } catch (Throwable $e) {
            return $this->storeUnavailable($e, 'rate limiter unavailable');
        }

        $streamed = $bodyMode === 'stream';

        // In streamed mode the body is deliberately never touched. Reading it to hash it would mean
        // accepting gigabytes from an unauthenticated caller before deciding whether to trust them,
        // which is the whole thing this mode exists to avoid.
        $body = $streamed ? '' : $request->getContent();
        $declaredHash = null;

        if ($streamed) {
            $declaredHash = strtolower((string) $request->header(Protocol::HEADER_CONTENT_SHA256, ''));

            if (preg_match('~^[0-9a-f]{64}$~', $declaredHash) !== 1) {
                return $this->reject('malformed content hash');
            }

            $length = $request->server('CONTENT_LENGTH');

            // A declared length is required, and checked against the ceiling before the body is read.
            // Without it there is no way to refuse an oversized upload except by receiving it.
            if (! is_numeric($length) || (int) $length <= 0) {
                return $this->reject('missing content length', 411);
            }

            $ceiling = $this->backupSize->ceilingBytes();

            /*
             | Only when there is a ceiling to check against.
             |
             | Null is an edition or an operator that has not set one, and it is now the default —
             | so this is the ordinary path rather than the exception. Nothing is silently permitted
             | by it: the artifact is still bounded by the organisation's quota, by the disk, and by
             | whatever the web server in front of this will carry.
             |
             | That last one is worth naming here, because this is the code somebody will be reading
             | when they hit it. A body limit in nginx answers with its own HTML 413 before PHP is
             | invoked at all, so the connector reports "Correlation ID: unknown" and this log line
             | does not exist. If you are looking for a rejection that was never written down, it
             | was not written down here.
             */
            if ($ceiling !== null && (int) $length > $ceiling) {
                // The ceiling, not just the verdict. "Payload too large" alone sent somebody looking
                // at a 2.1 MB artifact for the fault, when the limit had been configured to zero by
                // a blank environment line - and the refusal happens here, before anything with more
                // context about the artifact has run.
                return $this->reject("payload too large, limit is {$ceiling} bytes (MANAGER_BACKUP_MAX_BYTES)", 413);
            }
        } elseif (strlen($body) > (int) config('manager.connector.max_payload_bytes')) {
            return $this->reject('payload too large', 413);
        }

        $siteId = (string) $request->header(Protocol::HEADER_SITE, '');
        $timestamp = (string) $request->header(Protocol::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(Protocol::HEADER_NONCE, '');
        $version = (string) $request->header(Protocol::HEADER_CONNECTOR_VERSION, '');
        $signature = $this->parseSignature((string) $request->header(Protocol::HEADER_SIGNATURE, ''));

        if ($siteId === '' || $timestamp === '' || $version === '' || $signature === null || ! Nonce::isValid($nonce)) {
            return $this->reject('malformed signature headers');
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return $this->reject('timestamp outside tolerance');
        }

        $site = Site::query()->where('external_id', $siteId)->first();
        $connector = $site?->activeConnector()->first();

        $canonical = new CanonicalRequest(
            siteId: $siteId,
            connectorVersion: $version,
            timestamp: (int) $timestamp,
            nonce: $nonce,
            method: $request->getMethod(),
            path: CanonicalRequest::canonicalPath($request->getPathInfo(), $request->query()),
            body: $body,

            // Null in the ordinary case, so the hash is computed from the body as it always was. One
            // signing format either way - only where the hash came from differs.
            declaredBodyHash: $declaredHash,
        );

        // Verified against a decoy when the site is unknown, so both paths do the same work.
        $publicKey = $connector === null ? self::DECOY_PUBLIC_KEY : $connector->public_key;

        $verified = Keys::verify($canonical->toString(), $signature, $publicKey);

        if (! $verified || $site === null || $connector === null) {
            return $this->reject('signature verification failed');
        }

        try {
            if ($this->tooManyFromSite($siteId)) {
                return $this->throttled();
            }

            $fresh = $this->nonceStore->claim($siteId, $nonce);
        } catch (Throwable $e) {
            // Fails closed. Without a working replay check, accepting this request would mean
            // accepting replays of it.
            return $this->storeUnavailable($e, 'replay protection unavailable');
        }

        if (! $fresh) {
            return $this->reject('nonce already used');
        }

        $this->recordVersion($connector, $version);

        // Handed to the route so it never has to re-derive who is calling.
        $request->attributes->set('manager.site', $site);
        $request->attributes->set('manager.connector', $connector);
        $request->attributes->set('manager.nonce', $nonce);

        // The authenticated hash, so the route can compare the stream it is about to read against a
        // promise that has already been verified as coming from this connector.
        if ($declaredHash !== null) {
            $request->attributes->set('manager.content_sha256', $declaredHash);
        }

        return $next($request);
    }

    /**
     * Keep the stored connector version in step with the one that just signed.
     *
     * This is called only after the signature verified and the nonce was claimed, so the value is
     * authenticated rather than merely asserted: `connectorVersion` is one of the eight fields in
     * the canonical string, and a site cannot sign for a version it is not sending.
     *
     * It was previously written at pairing and never again, which meant it recorded the version a
     * site paired on rather than the version it runs. Upgrading the plugin changed nothing here, so
     * the fleet screens and every activity line the job service labels kept naming a release the
     * site had left behind — and because `Site::$connector_version` *is* refreshed on each
     * inventory report, the two columns holding the same fact disagreed, with the stale one being
     * the one on screen.
     *
     * Written only when it changes. An unchanged fleet costs one comparison per request and no
     * writes, which is what makes this affordable in a path every signed request passes through.
     *
     * The length guard mirrors the `max:32` the pairing endpoint validates. A connector that could
     * not have paired cannot widen this column, and a malformed header is worth ignoring rather
     * than turning into a database error on an otherwise valid request.
     */
    private function recordVersion(Connector $connector, string $version): void
    {
        if ($connector->connector_version === $version || strlen($version) > 32) {
            return;
        }

        $connector->forceFill(['connector_version' => $version])->save();
    }

    /**
     * Pull the signature out of a "v1=..." header value.
     */
    private function parseSignature(string $header): ?string
    {
        $prefix = Protocol::SIGNATURE_SCHEME.'=';

        if (! str_starts_with($header, $prefix)) {
            return null;
        }

        $signature = substr($header, strlen($prefix));

        return $signature === '' ? null : $signature;
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('manager.connector.timestamp_tolerance');

        // Both directions: a clock ahead of the platform is as much of a problem as one behind.
        return abs(time() - (int) $timestamp) <= $tolerance;
    }

    private function tooManyFrom(?string $ip): bool
    {
        return $this->exceeded(
            'connector:ip:'.($ip ?? 'unknown'),
            (int) config('manager.connector.rate_limit_per_ip'),
        );
    }

    private function tooManyFromSite(string $siteId): bool
    {
        return $this->exceeded(
            'connector:site:'.$siteId,
            (int) config('manager.connector.rate_limit_per_site'),
        );
    }

    /**
     * Whether this key is over its per-minute allowance, counting the current attempt if not.
     */
    private function exceeded(string $key, int $maxPerMinute): bool
    {
        if ($this->limiter->tooManyAttempts($key, $maxPerMinute)) {
            return true;
        }

        $this->limiter->hit($key, 60);

        return false;
    }

    private function throttled(): Response
    {
        return $this->reject('too many requests', 429);
    }

    /**
     * The shared store backing rate limiting and replay protection is unreachable.
     *
     * A 503 rather than a 401: this is the platform's problem, not the connector's, and the
     * distinction tells a connector to retry later instead of alerting somebody about credentials
     * that are in fact fine.
     */
    private function storeUnavailable(Throwable $e, string $reason): Response
    {
        Log::critical('Connector request rejected: '.$reason.'.', [
            'correlation_id' => $this->correlationId->get(),
            'exception' => $e->getMessage(),
        ]);

        return $this->reject($reason, 503);
    }

    /**
     * The single rejection path.
     *
     * The reason is logged with the correlation identifier but never returned, so an operator can
     * find out what happened while a caller learns only that it did.
     */
    private function reject(string $reason, int $status = 401): Response
    {
        $correlationId = $this->correlationId->get();

        Log::info('Connector request rejected.', [
            'correlation_id' => $correlationId,
            'reason' => $reason,
        ]);

        return response()->json(
            ['error' => 'unauthenticated', 'correlation_id' => $correlationId],
            $status,
            [Protocol::HEADER_CORRELATION_ID => $correlationId],
        );
    }
}
