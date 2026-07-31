<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupEvent;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Backups that have been asked for and have not arrived.
 *
 * Before this existed, pressing "Back up now" produced a flash message and then nothing: no row, no
 * state, no evidence the request had been made at all until an artifact appeared minutes later. The
 * data was already being recorded — `remote_jobs` has the request and `backup_events` has the
 * phases, and the migration that added the events table says explicitly that its artifact id is
 * nullable because "`requested` and `dump_started` both happen before an artifact row exists". It
 * simply had no reader.
 *
 * Nothing here is a progress percentage, and the protocol will not supply one. The progress schema
 * carries a phase name and a timestamp, and its description records that a byte count or a table
 * count was rejected deliberately: those describe the contents of somebody's database. A phase is
 * what there is, so a phase is what this shows.
 */
final class InFlightBackups
{
    /**
     * Every outstanding backup in an organisation, newest request first.
     *
     * @return Collection<int, InFlightBackup>
     */
    public function forOrganisation(int $organisationId): Collection
    {
        return $this->assemble(
            $this->jobs()->whereHas('site', static fn ($query) => $query->where('organisation_id', $organisationId))
        );
    }

    /**
     * @return Collection<int, InFlightBackup>
     */
    public function forSite(Site $site): Collection
    {
        return $this->assemble($this->jobs()->where('site_id', $site->id));
    }

    /**
     * How long a person should expect to wait before a site collects the request.
     *
     * Phrased as a window rather than a countdown. The connector runs its job task every five
     * minutes, but on a site with no cron it runs off ordinary web traffic instead — so the honest
     * answer is "about five minutes, once somebody visits", and a ticking clock would be a promise
     * this cannot keep.
     */
    public function checkInWindow(): string
    {
        $seconds = max(60, (int) config('manager.health.heartbeat_interval', 300));

        return $seconds < 3600
            ? (int) ceil($seconds / 60).' minutes'
            : (int) ceil($seconds / 3600).' hours';
    }

    /**
     * @return Builder<RemoteJob>
     */
    private function jobs()
    {
        return RemoteJob::query()
            ->with('site')
            ->where('type', Jobs::BACKUP_CREATE)
            ->whereIn('state', [Jobs::STATE_QUEUED, Jobs::STATE_CLAIMED])
            ->orderByDesc('created_at');
    }

    /**
     * @param  Builder<RemoteJob>  $query
     * @return Collection<int, InFlightBackup>
     */
    private function assemble($query): Collection
    {
        /** @var Collection<int, RemoteJob> $jobs */
        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            return collect();
        }

        /*
         | The latest phase each job reported, in one query rather than one per job.
         |
         | Ordered by recorded_at — ours — rather than occurred_at, which is the site's own clock.
         | BackupTimeline keeps both for exactly this reason: a site with a wrong clock, or a
         | deliberately wrong one, must not be able to reorder its own timeline.
         */
        $latest = BackupEvent::query()
            ->whereIn('remote_job_id', $jobs->pluck('id'))
            ->whereIn('event', [BackupEvent::DUMP_STARTED, BackupEvent::ENCRYPTED, BackupEvent::UPLOAD_STARTED])
            ->orderBy('recorded_at')
            ->get(['remote_job_id', 'event', 'occurred_at'])
            ->keyBy('remote_job_id');

        return $jobs->map(function (RemoteJob $job) use ($latest): InFlightBackup {
            $event = $latest->get($job->id);

            $phase = match ($event?->event) {
                BackupEvent::DUMP_STARTED => InFlightBackup::PHASE_DUMPING,
                BackupEvent::ENCRYPTED => InFlightBackup::PHASE_ENCRYPTING,
                BackupEvent::UPLOAD_STARTED => InFlightBackup::PHASE_UPLOADING,
                default => $job->state === Jobs::STATE_CLAIMED
                    ? InFlightBackup::PHASE_COLLECTED
                    : InFlightBackup::PHASE_QUEUED,
            };

            return new InFlightBackup(
                jobId: $job->external_id,
                site: $job->site,
                phase: $phase,
                requestedAt: Carbon::instance($job->created_at),
                requestedBy: $job->requested_by_label,

                // Everything past "collected" is the site describing itself, and the screen says so.
                reportedBySite: $event !== null,
            );
        })->values();
    }
}
