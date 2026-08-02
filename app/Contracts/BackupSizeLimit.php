<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Whether a site's own backup size limit applies, and what replaces it if not.
 *
 * The connector carries `maxBackupMegabytes`, defaulting to 2 GB. It is a safety valve for the
 * machine the dump is written on: an operator running Manager on their own infrastructure owns both
 * ends, and a runaway dump filling a production volume is their problem to bound.
 *
 * On a hosted edition it is the wrong control in the wrong place. The storage belongs to whoever
 * runs the service, it is metered and billed, and a customer whose database has grown past a number
 * they never chose gets a refused backup and a setting they cannot reach — the plugin's config lives
 * on their own server, and most sites have no config file at all, so the default is simply a
 * ceiling. Charging for the space is the answer there, not refusing the work.
 *
 * Null means the site decides, which is the self-hosted answer and the historical behaviour. A
 * number means the platform is overriding it, and zero means no limit at all.
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
}
