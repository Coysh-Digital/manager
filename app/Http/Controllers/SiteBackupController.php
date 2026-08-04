<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Backup\BackupReadiness;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\FailedBackupJobs;
use App\Domain\Backup\InFlightBackups;
use App\Domain\Backup\RecoveryKeyService;
use App\Domain\Backup\RetentionPolicy;
use App\Domain\Capability\CapabilityService;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Site;
use App\Support\ViewerTimezone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One site's backups.
 *
 * The fleet screen answers "what are we holding, and what is it costing"; this answers "is this site
 * protected, and how far back does that go" - which is the question asked while looking at a
 * particular site, and previously meant leaving it for a list of every artifact in the organisation.
 *
 * No download button and no restore button, for the reasons the fleet screen gives: decrypting a
 * multi-gigabyte artifact through a web request loses a race with a timeout, and restore has no
 * tested recovery path yet.
 */
final class SiteBackupController
{
    use ResolvesSiteContext;

    public function __construct(
        private readonly BackupService $backups,
        private readonly InFlightBackups $inFlight,
        private readonly FailedBackupJobs $failed,
        private readonly BackupReadiness $readiness,
        private readonly RecoveryKeyService $recoveryKeys,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * When this site is asked for a backup, without being asked.
     *
     * Moved here from the site's Settings screen, where it shared a form with the site's name, its
     * expected domain and its environment. Nothing about those is a backup decision, and the
     * schedule was the only control on that form whose effect is visible on a different screen
     * entirely - so somebody looking at a list of backups wondering why there were none had to know
     * to go and look somewhere else to find out.
     *
     * Administrators, and behind recent authentication, exactly as it was: this decides that a
     * production database is dumped in full on a repeating schedule, which is the same act the
     * "Back up now" button performs and that button is gated too.
     */
    public function updateSchedule(Request $request, Site $site): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        /*
         | The schedule decides *when* to ask, and nothing else.
         |
         | There is deliberately no field here naming a destination, a recipient or a format. Those
         | come from the organisation's recovery keys and from the site's own configuration file,
         | and a timing form that could influence any of them would be a way to change who can read
         | a backup from a screen that looks like it is about hours of the day.
         */
        $validated = $request->validate([
            'backup_schedule' => ['required', 'in:off,daily,weekly'],
            'backup_schedule_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'backup_schedule_day' => ['required', 'integer', 'min:1', 'max:7'],

            // The zone belongs with the hour rather than with retention, because it is the thing
            // that makes the hour mean anything. It used to be the organisation's, which cannot be
            // right for a fleet split between London and Sydney.
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        /*
         | Turning a schedule on with no recovery key produced the worst outcome this screen can:
         | the form saved, the audit log recorded it, the screen read "Every day" indefinitely, and
         | ScheduleBackupsCommand skipped the site every hour with the reason going to cron's stdout
         | and nowhere else. Somebody could believe a site had been backed up nightly for months.
         |
         | Refused rather than saved-and-warned, because a schedule that is set and never runs is
         | precisely the state that misleads. Turning one *off* is always allowed - needing a key to
         | stop asking for backups would be absurd.
        */
        if ($validated['backup_schedule'] !== 'off'
            && $validated['backup_schedule'] !== $site->backup_schedule
            && $site->organisation !== null
            && ! $this->recoveryKeys->hasActiveKey($site->organisation)) {
            return back()->withErrors([
                'backup_schedule' => 'Add a recovery key before scheduling backups. A backup is encrypted to '
                    .'keys you hold, so until this organisation has one there is nothing to encrypt to and every '
                    .'scheduled run would be skipped.',
            ])->withInput();
        }

        $before = [
            'backup_schedule' => $site->backup_schedule,
            'backup_schedule_hour' => $site->backup_schedule_hour,
            'backup_schedule_day' => $site->backup_schedule_day,
            'timezone' => $site->timezone,
        ];

        $after = [
            'backup_schedule' => $validated['backup_schedule'],
            'backup_schedule_hour' => (int) $validated['backup_schedule_hour'],
            'backup_schedule_day' => (int) $validated['backup_schedule_day'],
            'timezone' => $validated['timezone'],
        ];

        if ($before === $after) {
            return back()->with('status', 'Nothing to change.');
        }

        $site->forceFill($after)->save();

        $this->audit->record(
            action: 'site.backup_schedule.updated',
            site: $site,
            actor: $request->user(),
            targetType: 'site',
            targetId: $site->external_id,
            before: $before,
            after: $after,
        );

        return back()->with('status', $after['backup_schedule'] === 'off'
            ? 'Scheduled backups are off. This site will only be backed up when somebody asks.'
            : 'Saved. '.$site->fresh()?->backupScheduleSentence());
    }

    /**
     * How far back this site's backups are kept.
     *
     * Both were the organisation's until now, and both are decisions about a particular site: a busy
     * shop and a brochure site do not warrant the same history, and "03:00 where the site is" cannot
     * mean one thing for a fleet split between London and Sydney. Every site kept whatever its
     * organisation had, so nothing changed underneath anybody when they moved.
     *
     * Owner-level rather than administrator, which is what it was on the organisation form.
     * Shortening retention decides how far back this site can be recovered from, and that is a
     * different kind of decision from asking for a backup.
     *
     * Two things are deliberately not here. It cannot lengthen an existing artifact's life: expiry
     * is computed when a backup is stored, from the policy in force then, so this governs future
     * backups only. And it cannot set "keep the most recent N" - that is the rule this replaced,
     * because a site producing bad backups on a schedule pushes out the last known-good copy while
     * the count never drops below N and nothing looks wrong.
     */
    public function updateRetention(Request $request, Site $site): RedirectResponse
    {
        abort_unless(app(Membership::class)->isOwner(), 403);

        $validated = $request->validate([
            // Zero is meaningful in each of these and means "no window of this kind", not "unset".
            // The policy keeps the newest artifact regardless, so all three at zero still leaves the
            // site with its most recent backup rather than with nothing.
            'backup_retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'backup_retention_weeks' => ['required', 'integer', 'min:0', 'max:520'],
            'backup_retention_months' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        $before = [
            'backup_retention_days' => $site->backup_retention_days,
            'backup_retention_weeks' => $site->backup_retention_weeks,
            'backup_retention_months' => $site->backup_retention_months,
        ];

        $after = [
            'backup_retention_days' => (int) $validated['backup_retention_days'],
            'backup_retention_weeks' => (int) $validated['backup_retention_weeks'],
            'backup_retention_months' => (int) $validated['backup_retention_months'],
        ];

        if ($before === $after) {
            return back()->with('status', 'Nothing to change.');
        }

        $site->forceFill($after)->save();

        $this->audit->record(
            action: 'backup.retention.changed',
            site: $site,
            actor: $request->user(),
            targetType: 'site',
            targetId: $site->external_id,
            before: $before,
            after: $after,
        );

        return back()->with(
            'status',
            'Retention updated. Backups already taken keep the expiry they were given.',
        );
    }

    public function show(Site $site): View
    {
        $artifacts = BackupArtifact::query()
            ->where('site_id', $site->id)
            ->whereIn('state', [
                BackupArtifact::STATE_STORED,
                BackupArtifact::STATE_PENDING,
                BackupArtifact::STATE_FAILED,
            ])
            ->orderByDesc('taken_at')
            ->limit(60)
            ->get();

        $stored = $artifacts->where('state', BackupArtifact::STATE_STORED);

        return view('sites.backups', [
            ...$this->siteContext($site),
            'membership' => app(Membership::class),
            'timezones' => timezone_identifiers_list(),

            // The column can't tell "nobody has touched this" apart from "explicitly chose UTC", so
            // an untouched schedule (the field decides nothing until one runs) shows the viewer's own
            // zone instead. Once a real schedule is saved, the site's own zone always wins.
            'defaultTimezone' => $site->backup_schedule === 'off'
                ? ViewerTimezone::for()
                : $site->timezone,

            'retention' => RetentionPolicy::forSite($site),
            'artifacts' => $artifacts,
            'latest' => $stored->first(),
            'storedCount' => $stored->count(),
            'storedBytes' => (int) $stored->sum('ciphertext_bytes'),

            // Oldest first for the chart: a size trend read right to left is nobody's instinct.
            'trend' => $stored->sortBy('taken_at')->values()->map(fn (BackupArtifact $artifact): array => [
                // Built here rather than in the view, so it goes through the reader's zone here too.
                // A backup taken at 23:30 UTC is the next day in Sydney, and a chart labelled in the
                // server's zone disagrees with the table under it.
                'label' => ViewerTimezone::apply($artifact->taken_at)->format('j M'),
                'value' => round($artifact->plaintext_bytes / 1048576, 2),
                'size' => $artifact->humanSize(),
                'state' => $artifact->verified_at !== null ? 'verified' : 'stored',
                'text' => $artifact->humanSize(),
            ])->all(),

            'storage' => $this->backups->describeStorage(),
            'acknowledgement' => CapabilityService::acknowledgementFor('backups:create'),

            'inFlight' => $this->inFlight->forSite($site),

            // Asked for, did not happen, left no artifact - invisible everywhere else on this page.
            'failedJobs' => $this->failed->forSite($site),
            'checkInWindow' => $this->inFlight->checkInWindow(),

            // Whether the button can do anything, decided here rather than discovered minutes later
            // when the site checks in and the job is cancelled.
            'readiness' => $this->readiness->for($site),
        ]);
    }

    /**
     * This site's outstanding backups, as JSON. See BackupController::status().
     */
    public function status(Site $site): JsonResponse
    {
        return response()->json([
            'in_flight' => $this->inFlight->forSite($site)
                ->map(fn ($backup): array => $backup->toArray())
                ->all(),
        ]);
    }
}
