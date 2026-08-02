<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * One backup that was asked for and did not happen, assembled for the screen.
 *
 * Read only, and carries the site's own words for why. The reason is a fixed message from the
 * connector or from the platform — never anything the site said about its contents — which is what
 * makes it safe to print.
 */
final readonly class FailedBackupJob
{
    public function __construct(
        public string $jobId,
        public Site $site,
        public string $reason,
        public Carbon $failedAt,
        public ?string $requestedBy,
    ) {}

    /**
     * What somebody can actually do about it, where there is a known answer.
     *
     * Only for reasons the platform recognises, and null otherwise. A guess dressed as advice is
     * worse than no advice: it sends somebody to change a setting that was not the problem.
     */
    public function remedy(): ?string
    {
        $reason = strtolower($this->reason);

        return match (true) {
            str_contains($reason, 'larger than this connector is configured') => 'Raise maxBackupMegabytes in the site\'s config/manager-connector.php, or point that site at its own S3 bucket.',
            str_contains($reason, 'no active recovery key') => 'Create a recovery key in Settings. Backups are encrypted to it, so there is nothing to encrypt to until one exists.',
            str_contains($reason, 'declared but never uploaded') => 'The site started the upload and it did not arrive. Check the site can reach this installation, then run the backup again.',
            str_contains($reason, 'did not match the declared checksum') => 'The bytes that arrived were not the bytes the site hashed. Run the backup again; if it repeats, the path between the two is altering data.',
            default => null,
        };
    }
}
