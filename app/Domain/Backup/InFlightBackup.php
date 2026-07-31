<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * One backup that has been asked for and has not finished.
 *
 * Read only, and assembled for the screen. Nothing here is authoritative about anything: the phase is
 * partly the platform's own record of the job and partly the site's claim about itself, and the two
 * are kept distinct because a site can be wrong or lying. `BackupArtifact::$state` remains the only
 * thing that gates behaviour — see the note on the `stage` column, which says it is "a claim, where
 * `state` is a fact".
 */
final readonly class InFlightBackup
{
    /** Queued, and the site has not collected it. The longest wait, and the least informative. */
    public const PHASE_QUEUED = 'queued';

    /** The site has the job. Everything after this is the site describing itself. */
    public const PHASE_COLLECTED = 'collected';

    public const PHASE_DUMPING = 'dumping';

    public const PHASE_ENCRYPTING = 'encrypting';

    public const PHASE_UPLOADING = 'uploading';

    /**
     * In the order they happen, which is what the stepper draws.
     *
     * @var list<string>
     */
    public const PHASES = [
        self::PHASE_QUEUED,
        self::PHASE_COLLECTED,
        self::PHASE_DUMPING,
        self::PHASE_ENCRYPTING,
        self::PHASE_UPLOADING,
    ];

    public function __construct(
        public string $jobId,
        public Site $site,
        public string $phase,
        public Carbon $requestedAt,
        public ?string $requestedBy,
        public bool $reportedBySite,
    ) {}

    /**
     * Where this sits in {@see self::PHASES}, for drawing the stepper.
     */
    public function step(): int
    {
        $index = array_search($this->phase, self::PHASES, true);

        return $index === false ? 0 : $index;
    }

    /**
     * What to call the phase on screen.
     */
    public function label(): string
    {
        return match ($this->phase) {
            self::PHASE_QUEUED => 'Queued',
            self::PHASE_COLLECTED => 'Collected by the site',
            self::PHASE_DUMPING => 'Dumping the database',
            self::PHASE_ENCRYPTING => 'Encrypting',
            default => 'Uploading',
        };
    }

    /**
     * The sentence under the label.
     *
     * The queued case is the one worth explaining. Nothing is wrong and nothing is stuck — the
     * platform cannot reach into a site, so a request waits for the site to come and ask, and a
     * person watching a screen that says only "queued" has no way to know that is normal.
     */
    public function detail(string $checkInWindow): string
    {
        return match ($this->phase) {
            self::PHASE_QUEUED => "Waiting for this site to check in, usually within {$checkInWindow}.",
            self::PHASE_COLLECTED => 'The site has the request and has not reported a phase yet.',
            self::PHASE_DUMPING => 'The site reports it is dumping. This is normally the longest phase.',
            self::PHASE_ENCRYPTING => 'The site reports it is encrypting the dump.',
            default => 'The site reports it is uploading.',
        };
    }

    /**
     * @return array{
     *     job_id: string,
     *     site: string,
     *     phase: string,
     *     label: string,
     *     step: int,
     *     requested_at: string,
     *     reported_by_site: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'site' => $this->site->external_id,
            'phase' => $this->phase,
            'label' => $this->label(),
            'step' => $this->step(),
            'requested_at' => $this->requestedAt->toIso8601String(),
            'reported_by_site' => $this->reportedBySite,
        ];
    }
}
