<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * How large a backup may be, asked at both ends of the wire.
 *
 * Two limits, two machines, one edition decision — which is why they share a seam.
 *
 * `megabytes()` is about the dump on the *site's* disk. The connector carries `maxBackupMegabytes`,
 * defaulting to 2 GB, and it is a safety valve for the machine the dump is written on: an operator
 * running Manager on their own infrastructure owns both ends, and a runaway dump filling a
 * production volume is their problem to bound.
 *
 * `ceilingBytes()` is about the artifact arriving *here*. It is what the platform advertises on a
 * job claim so a site is refused before it dumps, and what the upload middleware, both declaration
 * paths and the direct-upload grant each check.
 *
 * On a hosted edition both are the wrong control in the wrong place. The storage belongs to whoever
 * runs the service, it is metered and billed, and a customer whose database has grown past a number
 * they never chose gets a refused backup and a setting they cannot reach — the plugin's config lives
 * on their own server, and most sites have no config file at all, so the default is simply a
 * ceiling. Charging for the space is the answer there, not refusing the work.
 *
 * The two methods spell "no limit" differently, and both spellings are load-bearing. See each.
 */
interface BackupSizeLimit
{
    /**
     * Megabytes a site may dump, or null to leave it to the site's own configuration.
     *
     * Zero is meaningful and distinct from null: it says this platform will take a backup of any
     * size, which is a promise only an edition that owns and bills for the storage can make.
     */
    public function megabytes(): ?int;

    /**
     * The largest artifact this platform will accept, in bytes, or null for no ceiling.
     *
     * The sibling above bounds a dump on somebody else's disk. This bounds what arrives on ours,
     * and it is a different question with a different answer per edition — which is why it is here
     * rather than read from config at each of the four places that enforce it. Self-hosted, the
     * operator's `MANAGER_BACKUP_MAX_BYTES` is the answer. Hosted, there is no ceiling, because the
     * storage is metered and sold and refusing a large database is refusing paid work.
     *
     * Null on this method and null on `megabytes()` mean opposite things, and nothing but these two
     * docblocks says so. `megabytes(): null` hands the decision back to the site. `ceilingBytes():
     * null` takes the decision and answers "no limit".
     *
     * Zero is not a permitted answer. A ceiling of zero has twice reached production from a blank
     * environment line and refused every upload on a platform that meant to refuse none, so the
     * value that used to express that mistake now cannot be expressed: callers treat null as "no
     * ceiling" and anything else as a real one.
     */
    public function ceilingBytes(): ?int;
}
