<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\BackupSizeLimit;

/**
 * The self-hosted answer: the site's own setting stands.
 *
 * Whoever runs this installation also runs the machines being backed up, or at least answers to the
 * same person. `maxBackupMegabytes` in the connector's config is theirs to set, and overriding it
 * from here would be this installation deciding how much disk somebody else's server may spend.
 */
final class SiteDecidesBackupSize implements BackupSizeLimit
{
    public function megabytes(): ?int
    {
        return null;
    }

    /**
     * The operator's own ceiling, if they set one.
     *
     * Unlike `megabytes()` this is not somebody else's disk - the artifact lands here, so this
     * installation does get a say. It just declines to invent one: `MANAGER_BACKUP_MAX_BYTES` is
     * null unless an operator wrote a number, and null is no ceiling.
     *
     * Read through config rather than env so that a test can set it, and returned rather than
     * clamped so the refusal downstream can name the number the operator actually chose.
     */
    public function ceilingBytes(): ?int
    {
        $bytes = config('manager.backups.max_bytes');

        return is_int($bytes) && $bytes > 0 ? $bytes : null;
    }
}
