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
     * Stop showing one notice.
     *
     * Scoped to the organisation rather than taking a job on trust, so an identifier from another
     * tenant matches nothing rather than being hidden from the people it belongs to. Returns the
     * number of rows affected, which is zero when the identifier is unknown or already dismissed.
     *
     * Through `toBase()` so Eloquent does not helpfully touch `updated_at` on the way past. That
     * column is when the job failed - {@see FailedBackupJob::$failedAt} reads it, and so does the
     * seven-day window above - and dismissing a notice must not rewrite when the thing happened.
     */
    public function dismiss(int $organisationId, string $jobExternalId): int
    {
        return $this->notices()
            ->where('external_id', $jobExternalId)
            ->whereHas('site', static fn ($query) => $query->where('organisation_id', $organisationId))
            ->toBase()
            ->update(['notice_dismissed_at' => Carbon::now()]);
    }

    /**
     * Stop showing every notice currently on the screen, optionally for one site.
     *
     * Deliberately the same set the screen draws, and not "every failed job there has ever been". A
     * button that also silenced a failure from six months ago - one nobody was being shown, and may
     * never have seen - would be doing more than it says.
     */
    public function dismissAll(int $organisationId, ?Site $site = null): int
    {
        $query = $this->notices()
            ->whereHas('site', static fn ($builder) => $builder->where('organisation_id', $organisationId));

        if ($site instanceof Site) {
            $query->where('site_id', $site->id);
        }

        return $query->toBase()->update(['notice_dismissed_at' => Carbon::now()]);
    }

    /**
     * @return Builder<RemoteJob>
     */
    private function jobs()
    {
        return $this->notices()
            ->with('site')
            ->orderByDesc('updated_at');
    }

    /**
     * The definition of a notice, without the ordering or the eager load the screen needs.
     *
     * Shared by the read and the write so the "Clear all" button cannot dismiss a different set to
     * the one somebody was looking at. An UPDATE also cannot carry an ORDER BY on every driver,
     * which is the mechanical reason the two are separated here rather than one method with a flag.
     *
     * @return Builder<RemoteJob>
     */
    private function notices()
    {
        return RemoteJob::query()
            ->where('type', Jobs::BACKUP_CREATE)

            // Expired and cancelled sit here with failed on purpose. To somebody waiting for a
            // backup they are the same event - it did not happen - and only the sentence differs.
            ->whereIn('state', [Jobs::STATE_FAILED, Jobs::STATE_EXPIRED, Jobs::STATE_CANCELLED])
            ->where('updated_at', '>=', Carbon::now()->subDays(self::DAYS))

            // Nothing was stored, so nothing is listed elsewhere. A job that did produce an artifact
            // is already on the screen, with a reason of its own.
            ->whereNotIn('id', BackupArtifact::query()->select('remote_job_id')->whereNotNull('remote_job_id'))

            // Read, understood, and either fixed or accepted. The seven-day window above is right
            // for a failure nobody has looked at and wrong for one somebody has already dealt with:
            // a panel people have learned to scroll past reports nothing. Dismissing hides the
            // notice and nothing else - the job, its reason and its audit row all stay.
            ->whereNull('notice_dismissed_at');
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
