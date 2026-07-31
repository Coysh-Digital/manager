<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Console\Commands\ScheduleBackupsCommand;
use App\Domain\Job\JobService;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;

/**
 * Whether a backup can actually be taken from this site right now, and if not, why not.
 *
 * This exists because the answer was previously spread across four places, none of which was the
 * screen with the button on it. `JobService::enqueue()` refuses a site with no connector,
 * `JobService::claimFor()` cancels a job for an organisation with no recovery key — *when the site
 * next checks in*, which may be five minutes later — the connector refuses again before dumping, and
 * `ScheduleBackupsCommand` checks all of it before queueing anything nightly.
 *
 * The backups screen checked none of it. It rendered "Back up now" whenever the viewer could
 * administer, and pressing it on an organisation with no recovery key produced "Backup requested. It
 * will run when the site next checks in", a REQUESTED row on the timeline, and then silence — the job
 * was cancelled minutes later by a rule stated nowhere on that screen. The most alarming version of
 * that is a site whose owner believes it is being backed up and is not.
 *
 * So the checks are gathered here and asked *before* the button is drawn as well as before the job is
 * queued. Same conditions, same order, one implementation.
 *
 * The recovery-key rule deserves its own note, because it is not the same rule everywhere:
 *
 *  - At **format floor v2** it is a hard block. A v2 artifact is encrypted to recovery keys and to
 *    nothing else, so with no active key there is genuinely nothing to encrypt to, and
 *    {@see JobService::claimFor()} cancels the job outright.
 *  - At **v1** a backup can still be taken, because a v1 artifact is sealed to the platform's own
 *    key. But `ScheduleBackupsCommand` skips *every* organisation without an active key regardless
 *    of floor — so a v1 organisation with no key can back up by hand while its scheduled backups are
 *    being skipped without saying so. That disagreement is older than this class and is not resolved
 *    here; it is reported, because a warning somebody can read beats two commands that quietly
 *    disagree.
 */
final class BackupReadiness
{
    public function __construct(private readonly RecoveryKeyService $keys) {}

    /**
     * @return array{
     *     ready: bool,
     *     blockers: list<string>,
     *     warnings: list<string>,
     *     needsRecoveryKey: bool,
     * }
     */
    public function for(Site $site): array
    {
        $blockers = [];
        $warnings = [];

        if (! $site->hasCapability('backups:create')) {
            $blockers[] = 'This site has not granted permission to create backups.';
        }

        if ($site->activeConnector()->first() === null) {
            $blockers[] = 'This site has no active connector, so there is nothing to ask.';
        }

        $organisation = $site->organisation;
        $activeKeys = $organisation === null ? 0 : count($this->keys->recipientsFor($organisation));
        $requiresKey = $organisation?->backup_format_floor === Protocol::BACKUP_FORMAT_V2;

        if ($activeKeys === 0) {
            if ($requiresKey) {
                // Worth stating as the reason rather than as a rule: backups are encrypted to keys
                // the customer holds, so "no key" is not a missing setting, it is nothing to encrypt
                // to. The same sentence is on the settings screen.
                $blockers[] = 'This organisation has no active recovery key, so there is nothing to encrypt a backup to.';
            } else {
                $warnings[] = 'This organisation has no active recovery key. A backup can still be taken, '
                    .'but scheduled backups are being skipped until one is added.';
            }
        } elseif ($activeKeys === 1) {
            $warnings[] = 'One recovery key. If it is lost, every backup encrypted to it becomes permanently unreadable.';
        }

        if ($this->hasOutstandingBackup($site)) {
            $blockers[] = 'A backup has already been requested and is waiting for this site to check in.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'needsRecoveryKey' => $activeKeys === 0,
        ];
    }

    /**
     * Whether this site already owes us a backup.
     *
     * The same query as {@see ScheduleBackupsCommand}, for the same reason: two
     * concurrent dumps of one database is how a backup turns into an outage. Kept as a separate read
     * rather than shared with the job service's idempotency check, which is keyed on the request and
     * would not see a scheduled backup already in flight.
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
