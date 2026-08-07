<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Console\Commands\ScheduleBackupsCommand;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * When a site's schedule is due, and when it will next be due.
 *
 * Two questions with one answer, and they were previously answered in different places or not at
 * all. {@see ScheduleBackupsCommand} asked the first one inline; nothing asked
 * the second, so a screen could tell somebody *that* a site was backed up weekly and never when the
 * next one was coming.
 *
 * The reason they belong together is that a projection nobody can check is worse than none. "Next
 * run: Saturday 03:00" is a claim about what a scheduled command will do, and if the two ever
 * disagree it is the screen people will believe. So the command asks this class rather than
 * re-deriving the rule, and the projection walks forward asking the same question the command asks
 * on the hour.
 *
 * Everything here works in the site's own timezone. That is not a display convenience - it is what
 * the scheduler reads, and 03:00 has to mean the quiet hour where the site is.
 */
final class BackupSchedule
{
    /**
     * How far ahead {@see nextRuns()} will look before giving up.
     *
     * Generous enough for a weekly schedule to find its day from any starting point, bounded so a
     * schedule that can never fire - see the spring-forward note below - returns an empty list
     * rather than spinning.
     */
    private const HORIZON_DAYS = 400;

    /**
     * The zone the schedule is read in.
     *
     * The `?: 'UTC'` matters and is not defensive noise: `timezone` is a non-null column with a
     * default, so it is never missing, but it can be an empty string on a row written before the
     * column existed. An empty zone passed to Carbon throws.
     */
    public function timezoneFor(Site $site): string
    {
        return $site->timezone ?: 'UTC';
    }

    /**
     * Whether this site's schedule says now.
     *
     * The hour and, weekly, the day. Deliberately *not* whether it has already fired - that is
     * {@see hasFiredInWindow()}, and it is state rather than schedule. Keeping them apart is what
     * lets the projection reuse this without needing to invent a future value for
     * `backup_scheduled_at`.
     */
    public function isDue(Site $site, ?Carbon $now = null): bool
    {
        if (! $site->hasBackupSchedule()) {
            return false;
        }

        $now ??= Carbon::now($this->timezoneFor($site));

        if ($now->hour !== $site->backup_schedule_hour) {
            return false;
        }

        return $site->backup_schedule !== 'weekly'
            || $now->dayOfWeekIso === $site->backup_schedule_day;
    }

    /**
     * Whether the current window has already been used.
     *
     * Compared against the recorded timestamp rather than against existing jobs, so a job that
     * failed and was swept up does not reopen the window.
     */
    public function hasFiredInWindow(Site $site, ?Carbon $now = null): bool
    {
        if ($site->backup_scheduled_at === null) {
            return false;
        }

        $timezone = $this->timezoneFor($site);
        $now ??= Carbon::now($timezone);

        return $site->backup_scheduled_at->copy()->setTimezone($timezone)->isSameHour($now);
    }

    /**
     * The next run, or null where there is no schedule.
     */
    public function nextRun(Site $site, ?Carbon $from = null): ?Carbon
    {
        return $this->nextRuns($site, 1, $from)[0] ?? null;
    }

    /**
     * The next few runs, in the site's own timezone, soonest first.
     *
     * Includes the current hour when the schedule is due and has not fired yet, so a site whose
     * backup is about to be requested reads as "in a few minutes" rather than skipping to tomorrow.
     * That case is the whole hour between the scheduler's tick and the schedule's hour ending, and
     * it is exactly when somebody is most likely to be looking.
     *
     * @return list<Carbon>
     */
    public function nextRuns(Site $site, int $limit = 3, ?Carbon $from = null): array
    {
        if (! $site->hasBackupSchedule() || $limit < 1) {
            return [];
        }

        $timezone = $this->timezoneFor($site);
        $from ??= Carbon::now($timezone);
        $from = $from->copy()->setTimezone($timezone);

        $runs = [];
        $day = $from->copy()->startOfDay();

        for ($offset = 0; $offset <= self::HORIZON_DAYS && count($runs) < $limit; $offset++) {
            $candidate = $day->copy()->addDays($offset)->setTime($site->backup_schedule_hour, 0);

            /*
             | The hour has to survive being set.
             |
             | On the morning a zone springs forward, the scheduled hour may not exist - 01:00 in
             | Europe/London on the last Sunday in March becomes 02:00 - and PHP shifts it rather
             | than refusing. Checking afterwards keeps this honest with the command, which simply
             | never sees its hour that day and does not fire. Printing the shifted time would
             | promise a run that will not happen.
             */
            if ($candidate->hour !== $site->backup_schedule_hour) {
                continue;
            }

            if (! $this->isDue($site, $candidate)) {
                continue;
            }

            // Strictly past, or this window already spent. Either way the scheduler will not fire
            // it, so it is not upcoming.
            if ($candidate->lessThan($from) && ! $candidate->isSameHour($from)) {
                continue;
            }

            if ($candidate->isSameHour($from) && $this->hasFiredInWindow($site, $from)) {
                continue;
            }

            $runs[] = $candidate;
        }

        return $runs;
    }
}
