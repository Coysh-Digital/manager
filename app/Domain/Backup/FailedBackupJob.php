<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Notifications\FailureReason;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * One backup that was asked for and did not happen, assembled for the screen.
 *
 * Read only, and carries the site's own words for why. The reason is a fixed message from the
 * connector or from the platform - never anything the site said about its contents - which is what
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
     *
     * The list itself lives in {@see FailureReason}, which is also what the alert email asks. It was
     * here, and the email's copy was there, and the two had already drifted: a backup refused for
     * storage got advice in the inbox and none on the screen, which is the wrong way round - the
     * screen is where somebody is standing when they go to fix it.
     */
    public function remedy(): ?string
    {
        return FailureReason::from($this->reason)->advice;
    }
}
