<?php

declare(strict_types=1);

namespace App\Domain\Connector;

use coyshdigital\managerprotocol\Nonce;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Remembers which request nonces have been used, so a captured request cannot be replayed.
 *
 * The claim has to be atomic across every worker process, which is why it goes through `add()`
 * rather than a read followed by a write: on Redis that is a single Lua script that either creates
 * the key or reports that somebody else already had. A check-then-set would leave a window in
 * which two workers both saw the same nonce as fresh.
 *
 * `add()` also keeps this portable across phpredis and predis, so the deployment's choice of
 * extension is not something replay protection depends on.
 *
 * If the store is unreachable this **fails closed** — see {@see self::claim()}. That is invariant
 * 15, and it is the one place in the system where availability deliberately loses to correctness.
 */
final class NonceStore
{
    public function __construct(private readonly CacheFactory $cache) {}

    /**
     * Claim a nonce for a site.
     *
     * @return bool true if this is the first use, false if it is a replay
     *
     * @throws NonceStoreUnavailableException if the store cannot be reached
     */
    public function claim(string $siteExternalId, string $nonce): bool
    {
        // Validated for shape before it becomes part of a key, so a malformed value cannot be used
        // to write somewhere it should not.
        if (! Nonce::isValid($nonce)) {
            return false;
        }

        try {
            return $this->store()->add($this->key($siteExternalId, $nonce), true, $this->ttl());
        } catch (Throwable $e) {
            // Rejecting here means an outage stops connectors reporting. That is the correct
            // trade: without a working replay check, accepting a request means accepting replays.
            throw new NonceStoreUnavailableException(
                'The replay-protection store is unavailable, so the request cannot be verified.',
                previous: $e,
            );
        }
    }

    /**
     * How long a nonce is remembered.
     *
     * Twice the accepted clock skew, so a nonce is still on file for as long as a request bearing
     * it could possibly be accepted. Widening the tolerance widens this automatically; the two are
     * not independent settings.
     */
    public function ttl(): int
    {
        return 2 * (int) config('manager.connector.timestamp_tolerance');
    }

    /**
     * The configured store.
     *
     * It must be shared and atomic. An in-process store such as "array", or a non-atomic one such
     * as "file", would let a replay through on a second worker — `manager:doctor` checks for this.
     */
    private function store(): Repository
    {
        return $this->cache->store((string) config('manager.connector.nonce_store'));
    }

    private function key(string $siteExternalId, string $nonce): string
    {
        return "manager:nonce:{$siteExternalId}:{$nonce}";
    }
}
