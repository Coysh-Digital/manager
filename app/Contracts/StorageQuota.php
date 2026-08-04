<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Organisation;

/**
 * How much more backup storage an organisation may consume.
 *
 * The per-artifact ceiling in config('manager.backups.max_bytes') stops one enormous dump. It does
 * nothing about forty ordinary ones, and the failure that causes is worse than a rejected backup:
 * the volume fills, and then *every* site's backups start failing at once, including the sites that
 * were behaving. An aggregate limit is how an operator with a finite disk avoids that.
 *
 * Self-hosted reads a configured number and usually has none set. Cloud meters it per organisation
 * and sells more.
 */
interface StorageQuota
{
    /**
     * Bytes this organisation may still store, or null when it is unlimited.
     *
     * Null rather than PHP_INT_MAX so that "no limit configured" and "an enormous limit" stay
     * distinguishable to a caller deciding whether to warn somebody.
     *
     * `$incomingBytes` is the artifact being declared right now, and an edition that sells storage
     * needs it. Self-hosted reads a fixed number and can ignore it - `ConfiguredQuota` does. A hosted
     * edition granting overage in blocks cannot: deciding how much room to open on the basis of what
     * is *already* stored quietly assumes no single artifact is larger than one block, which was true
     * only while the wire contract capped an artifact at 2 GiB. It no longer does.
     *
     * Defaulted so every existing *call site* is unchanged. Implementations still have to declare it
     * - PHP requires the signatures to match - which is the honest cost of widening a seam and is
     * still much cheaper than the alternative. A new contract would need a self-hosted binding and a
     * hand-maintained row in the hosted edition's own seam test, and that second one has no tripwire:
     * it is exactly how a contract once reached production with nothing bound behind it.
     */
    public function remainingBytes(Organisation $organisation, int $incomingBytes = 0): ?int;
}
