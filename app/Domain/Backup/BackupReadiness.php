<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Console\Commands\ScheduleBackupsCommand;
use App\Domain\Job\JobService;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;

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
 * **A recovery key is now required, whatever the format floor.** That disagreement used to be
 * described here as older than this class and left unresolved, so it is worth setting out what it
 * was and why it has gone.
 *
 * At floor v2 a missing key was already a hard block: a v2 artifact is encrypted to recovery keys
 * and to nothing else, so there is genuinely nothing to encrypt to. At v1 a backup was still taken,
 * because a v1 artifact is sealed to *this platform's* key — which is precisely the arrangement the
 * v2 format exists to end. The floor only ratchets to v2 on the first key activation, so the
 * organisations still on v1 were exactly the ones that had never added a key: every new organisation
 * could take a backup this platform could read, having been told nowhere that it could.
 *
 * Meanwhile `ScheduleBackupsCommand` refused those same organisations outright, and the settings
 * screen told them "No backups can be taken yet" — which was untrue for them and had been for as
 * long as both had existed. Three components, two rules, and the strictest one was the one nobody
 * could see.
 *
 * So the rule is the strict one, in one place, and the screens now say what the system does. The
 * cost is real and deliberate: an organisation with no key cannot take a backup at all, where before
 * it could take one of the readable kind. `manager-restore` is published, so making a key is a
 * two-command job, and the settings screen sets out both commands.
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

        // Loaded explicitly rather than read off the relation, because reading it implicitly is a
        // lazy load and `AppServiceProvider` prevents those outside production. That made both
        // backups screens throw a 500 on every non-production environment while production got away
        // with a silent query per row — the worst pairing, since the environment that would have
        // shown somebody the problem is the one nobody screenshots.
        //
        // `loadMissing` is a no-op where the caller has already eager-loaded it, which
        // `BackupController::index` now does so the fleet screen stays at one query.
        $organisation = $site->loadMissing('organisation')->organisation;
        $activeKeys = $organisation === null ? 0 : count($this->keys->recipientsFor($organisation));

        if ($activeKeys === 0) {
            // Worth stating as the reason rather than as a rule: backups are encrypted to keys the
            // customer holds, so "no key" is not a missing setting, it is nothing to encrypt to. The
            // same sentence is on the settings screen.
            $blockers[] = 'This organisation has no active recovery key, so there is nothing to encrypt a backup to.';
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
