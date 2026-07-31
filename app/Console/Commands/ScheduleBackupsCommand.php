<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupTimeline;
use App\Domain\Backup\RecoveryKeyService;
use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Models\BackupEvent;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Asks sites for a backup when their schedule says so.
 *
 * Runs every hour and decides per site, rather than running nightly and doing everything at once. A
 * fleet of forty sites all dumping at 03:00 is forty databases being read at once on shared hosting,
 * and "nightly" means something different in Auckland from what it means in Bristol.
 *
 * Four things are checked before a job is queued, and every one of them exists because of what happens
 * if it is not:
 *
 *  - **The site has an active connector**, or the job sits queued until it expires and the fleet looks
 *    busy rather than disconnected.
 *  - **`backups:create` is granted.** {@see JobService::enqueue()} checks this too, and again at claim
 *    time; this only avoids queueing something certain to be refused.
 *  - **The organisation has an active recovery key.** Without one there is nothing to encrypt a backup
 *    to, and the connector would refuse before dumping. Queuing anyway would produce a nightly failed
 *    job whose real cause is a setting nobody has looked at.
 *  - **Nothing is already outstanding for this site.** A backup that takes longer than an hour must not
 *    have another queued behind it, because two concurrent dumps of the same database is how a backup
 *    schedule becomes an outage.
 *
 * The window guard is a timestamp comparison rather than a count of jobs. A job that failed and was
 * cleaned up would otherwise let the same hour fire again on the next run.
 */
final class ScheduleBackupsCommand extends Command
{
    protected $signature = 'manager:backups:schedule
                            {--dry-run : List what would be requested without requesting it}';

    protected $description = 'Request backups from sites whose schedule is due';

    public function handle(JobService $jobs, RecoveryKeyService $keys, BackupTimeline $timeline): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $requested = 0;
        $skipped = 0;

        $sites = Site::query()
            ->active()
            ->whereIn('backup_schedule', ['daily', 'weekly'])
            ->with(['organisation', 'activeConnector'])
            ->cursor();

        foreach ($sites as $site) {
            $timezone = $site->organisation->timezone ?: 'UTC';
            $now = Carbon::now($timezone);

            if ($now->hour !== $site->backup_schedule_hour) {
                continue;
            }

            if ($site->backup_schedule === 'weekly' && $now->dayOfWeekIso !== $site->backup_schedule_day) {
                continue;
            }

            // Already fired in this window. Compared against the recorded timestamp rather than
            // against existing jobs, so a job that failed and was swept up does not reopen the window.
            if ($site->backup_scheduled_at !== null
                && $site->backup_scheduled_at->setTimezone($timezone)->isSameHour($now)) {
                continue;
            }

            if ($site->activeConnector === null || ! $site->hasCapability('backups:create')) {
                $skipped++;

                continue;
            }

            if (! $keys->hasActiveKey($site->organisation)) {
                // Nothing to encrypt to. The connector would refuse before dumping, so queuing this
                // would turn a missing setting into a nightly failure whose cause is somewhere else.
                $this->line("  {$site->name}: no active recovery key, so no backup was requested");
                $skipped++;

                continue;
            }

            if ($this->hasOutstandingBackup($site)) {
                $this->line("  {$site->name}: a backup is already outstanding");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  would request a backup from {$site->name}");
                $requested++;

                continue;
            }

            try {
                $job = $jobs->enqueue(
                    $site,
                    Jobs::BACKUP_CREATE,
                    // No parameters. A backup job carries none at all — not a destination, not a
                    // recipient, not a format. Everything about the backup is decided elsewhere.
                    [],
                    actor: null,

                    // One job per site per hour, whatever else happens. Two scheduler containers, or a
                    // run that overlapped its predecessor, both resolve to the same job.
                    idempotencyKey: 'schedule:'.$site->external_id.':'.$now->format('Y-m-d-H'),
                );
            } catch (JobRejectedException $e) {
                $this->line("  {$site->name}: {$e->getMessage()}");
                $skipped++;

                continue;
            }

            // The same note the backups screen writes when a person asks. Both paths record it, so a
            // timeline with no `requested` row means the request was genuinely never made.
            $timeline->platform(
                event: BackupEvent::REQUESTED,
                site: $site,
                job: $job,
                detail: 'Requested by the schedule.',
            );

            $site->forceFill(['backup_scheduled_at' => Carbon::now()])->save();
            $requested++;
        }

        $this->info("Requested {$requested} backup(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Whether this site already owes us a backup.
     */
    private function hasOutstandingBackup(Site $site): bool
    {
        return RemoteJob::query()
            ->where('site_id', $site->id)
            ->where('type', Jobs::BACKUP_CREATE)
            ->whereIn('state', [Jobs::STATE_QUEUED, Jobs::STATE_CLAIMED])
            ->exists();
    }
}
