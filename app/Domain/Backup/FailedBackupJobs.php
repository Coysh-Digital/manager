<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupArtifact;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Backups that were asked for, did not happen, and left nothing behind.
 *
 * {@see InFlightBackups} reads queued and claimed work, and the backups screen lists artifacts. A
 * backup the site refused is in neither: the connector checks the dump size and its recovery-key
 * pinning before it declares anything, so when it refuses there is no artifact row to list, and the
 * job has left the queue so there is no in-flight row either. The request a person watched simply
 * vanished, and the only record was an audit entry and `remote_jobs.failure_reason`.
 *
 * That is how a site whose database has outgrown the connector's limit fails every night while the
 * screen keeps showing the last backup that worked.
 *
 * Deliberately excludes jobs that did produce an artifact. Those already appear on the screen with
 * their own failure reason, and listing both would report one failure twice under two different
 * descriptions.
 */
final class FailedBackupJobs
{
    /**
     * How far back to look. Long enough to cover a weekend, short enough that a fixed problem stops
     * being shouted about once it is fixed.
     */
    private const DAYS = 7;

    /**
     * @return Collection<int, FailedBackupJob>
     */
    public function forOrganisation(int $organisationId): Collection
    {
        return $this->assemble(
            $this->jobs()->whereHas('site', static fn ($query) => $query->where('organisation_id', $organisationId))
        );
    }

    /**
     * @return Collection<int, FailedBackupJob>
     */
    public function forSite(Site $site): Collection
    {
        return $this->assemble($this->jobs()->where('site_id', $site->id));
    }

    /**
     * @return Builder<RemoteJob>
     */
    private function jobs()
    {
        return RemoteJob::query()
            ->with('site')
            ->where('type', Jobs::BACKUP_CREATE)

            // Expired and cancelled sit here with failed on purpose. To somebody waiting for a
            // backup they are the same event — it did not happen — and only the sentence differs.
            ->whereIn('state', [Jobs::STATE_FAILED, Jobs::STATE_EXPIRED, Jobs::STATE_CANCELLED])
            ->where('updated_at', '>=', Carbon::now()->subDays(self::DAYS))

            // Nothing was stored, so nothing is listed elsewhere. A job that did produce an artifact
            // is already on the screen, with a reason of its own.
            ->whereNotIn('id', BackupArtifact::query()->select('remote_job_id')->whereNotNull('remote_job_id'))
            ->orderByDesc('updated_at');
    }

    /**
     * @param  Builder<RemoteJob>  $query
     * @return Collection<int, FailedBackupJob>
     */
    private function assemble($query): Collection
    {
        /** @var Collection<int, RemoteJob> $jobs */
        $jobs = $query->limit(20)->get();

        return $jobs->map(fn (RemoteJob $job): FailedBackupJob => new FailedBackupJob(
            jobId: $job->external_id,
            site: $job->site,
            reason: (string) ($job->failure_reason ?: 'The backup did not complete.'),
            failedAt: Carbon::instance($job->updated_at),
            requestedBy: $job->requested_by_label,
        ))->values();
    }
}
