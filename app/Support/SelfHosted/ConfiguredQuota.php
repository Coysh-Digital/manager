<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\StorageQuota;
use App\Models\BackupArtifact;
use App\Models\Organisation;

/**
 * A storage limit read from configuration, counted from the artifacts themselves.
 *
 * Unset by default, because a self-hosted operator who has not asked for a limit should not
 * discover one. Set MANAGER_BACKUP_QUOTA_BYTES and it applies to every organisation on the
 * installation, which for the usual single-organisation install is the same as applying to the
 * installation.
 *
 * Usage is summed rather than counted in a column. That is the slower answer and the correct one
 * here: a counter can drift from reality after a failed delete or a manual intervention, and an
 * operator who set a limit wants it enforced against what is actually on the disk. An edition
 * metering this per tenant at scale will want a maintained counter instead, which is why this sits
 * behind {@see StorageQuota} rather than inline in the backup service.
 *
 * Pending artifacts count. They have been declared, the bytes are on their way, and not counting
 * them lets a site declare its way past the limit before any of it arrives.
 */
final class ConfiguredQuota implements StorageQuota
{
    /**
     * `$incomingBytes` is deliberately ignored.
     *
     * A configured limit is a fixed number an operator chose about a disk they own. Nothing here is
     * bought, so there is no decision to make about how much room to open - the artifact either fits
     * in what is left or it does not, and the caller compares the two.
     */
    public function remainingBytes(Organisation $organisation, int $incomingBytes = 0): ?int
    {
        $limit = config('manager.backups.quota_bytes');

        if ($limit === null || $limit === '') {
            return null;
        }

        // The bytes storage actually holds, which is not `ciphertext_bytes`. See
        // BackupArtifact::storedBytesExpression() for why those are different numbers, and why
        // admitting on one while measuring the other was a quota that did not measure the thing it
        // was limiting.
        $used = (int) BackupArtifact::query()
            ->where('organisation_id', $organisation->id)
            ->whereIn('state', [BackupArtifact::STATE_PENDING, BackupArtifact::STATE_STORED])
            ->sum(BackupArtifact::storedBytesExpression());

        return max(0, (int) $limit - $used);
    }
}
