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
}
