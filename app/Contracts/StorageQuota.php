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
     */
    public function remainingBytes(Organisation $organisation): ?int;
}
