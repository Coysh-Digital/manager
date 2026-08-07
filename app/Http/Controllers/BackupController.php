<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Backup\BackupReadiness;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\BackupTimeline;
use App\Domain\Backup\FailedBackupJobs;
use App\Domain\Backup\InFlightBackups;
use App\Domain\Backup\RecoveryKeyService;
use App\Domain\Capability\CapabilityService;
use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The backups screen.
 *
 * Shows artifacts, what they cost in storage, and when each will be deleted.
 *
 *  - **Download.** Ciphertext only, and it goes through {@see BackupDownloadController} - on Cloud as
 *    a redirect to the store, so the bytes never pass through a worker. This screen used to say there
 *    was deliberately no download button, and the argument given was that decrypting a multi-gigabyte
 *    artifact inside a web request holds a worker against a timeout it will lose. That is still true,
 *    and nothing here decrypts. What the argument missed is that a customer whose backup is sealed to
 *    their own recovery keys was being told to run `manager-restore decrypt` and given no way to
 *    obtain the file to run it on.
 *  - **Decryption.** Still a command, still off the web request. `manager:backups:fetch` for an
 *    artifact this platform holds a key for; `manager-restore decrypt` on the customer's own machine
 *    for one it does not.
 *  - **Restore.** Not built at all. The specification is explicit that it waits until its threat model,
 *    confirmation flow and failure recovery are designed and tested, and a button that only worked
 *    sometimes would be worse than the absence of one.
 */
final class BackupController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly JobService $jobs,
        private readonly InFlightBackups $inFlight,
        private readonly FailedBackupJobs $failed,
        private readonly BackupTimeline $timeline,
        private readonly BackupReadiness $readiness,
        private readonly RecoveryKeyService $recoveryKeys,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * The tombstone reason a bulk deletion writes.
     *
     * Not a field, and deliberately not the same sentence the single-row button writes.
     *
     * Not a field, because the screen has never asked anybody to type a reason - both per-row buttons
     * hand `destroy()` a canned "Deleted by hand" - so a free-text box here would be the first place
     * in the product that did, on the least deliberate gesture in it. And one typed sentence
     * stretched across forty artifacts is a blanket claim about forty separate acts, which is the
     * kind of half-truth {@see storeMany()} was built to refuse.
     *
     * Different wording, because the tombstone is the record and "was this one chosen, or swept up
     * with thirty-nine others" is a question somebody reading an audit log a year from now is
     * entitled to an answer to. That answer costs one string here and is unrecoverable later. It is
     * also the reason `destroyMany()` writes no summary audit row: the distinction it would carry is
     * already carried here, in the per-artifact entries that were going to be written anyway.
     */
    private const BULK_DELETE_REASON = 'Deleted by hand, as one of several selected together.';

    public function index(Organisation $organisation): View
    {
        $artifacts = BackupArtifact::query()
            // Recipients so each row can name the recovery key that opens it. Without this the only
            // way to learn which of several keys a given backup needs is to download it and run
            // `manager-restore inspect`, which is a strange thing to make somebody do when the
            // platform recorded the answer at the moment it sealed the artifact.
            ->with(['site', 'recipients'])
            ->where('organisation_id', $organisation->id)
            ->whereIn('state', [BackupArtifact::STATE_STORED, BackupArtifact::STATE_PENDING, BackupArtifact::STATE_FAILED])
            ->orderByDesc('taken_at')
            ->limit(100)
            ->get();

        $permitted = Site::query()
            // Readiness needs each site's organisation to count its recovery keys. Every row here
            // belongs to the same one, so loading it once beats a query per row.
            ->with('organisation')
            ->where('organisation_id', $organisation->id)
            ->active()
            ->whereHas('capabilityGrants', fn ($query) => $query
                ->where('capability', 'backups:create')
                ->where('state', 'granted'))
            ->orderBy('name')
            ->get();

        // The same readiness the site screen uses, per row. A fleet where none of these buttons can
        // do anything is worth seeing as a fleet rather than one site at a time - the usual cause is
        // one organisation-wide setting.
        $readiness = $permitted->mapWithKeys(
            fn (Site $site): array => [$site->id => $this->readiness->for($site)],
        )->all();

        return view('backups.index', [
            'organisation' => $organisation,
            'membership' => app(Membership::class),
            'artifacts' => $artifacts,

            // Backups that were asked for and left nothing behind. Without this they are invisible:
            // no artifact to list, and the job has left the queue so InFlightBackups cannot see it
            // either.
            'failedJobs' => $this->failed->forOrganisation($organisation->id),

            // Sites that could be backed up, so somebody who has granted the permission but never seen
            // an artifact can tell which of the two things is missing.
            'permittedSites' => $permitted,
            'readiness' => $readiness,

            // An organisation-level fact rather than a per-site one, so the screen can say it once
            // and offer somewhere to go, instead of repeating the same sentence down every row.
            'needsRecoveryKey' => ! $this->recoveryKeys->hasActiveKey($organisation),

            // What storage holds, matching the quota rather than reporting a different number to it.
            'storedBytes' => $artifacts
                ->where('state', BackupArtifact::STATE_STORED)
                ->sum(fn (BackupArtifact $artifact): int => $artifact->expectedUploadBytes()),

            'storage' => $this->backups->describeStorage(),
            'acknowledgement' => CapabilityService::acknowledgementFor('backups:create'),

            'inFlight' => $this->inFlight->forOrganisation($organisation->id),
            'checkInWindow' => $this->inFlight->checkInWindow(),
        ]);
    }

    /**
     * The same outstanding work, as JSON, for the screen to keep itself current.
     *
     * Read only and deliberately thin: identifiers and a phase name, nothing a page cannot already
     * see. A backup waits on a site checking in, which can be five minutes away, and a screen that
     * looks frozen for five minutes teaches people to press the button again.
     */
    public function status(Organisation $organisation): JsonResponse
    {
        return response()->json([
            'in_flight' => $this->inFlight->forOrganisation($organisation->id)
                ->map(fn ($backup): array => $backup->toArray())
                ->all(),
        ]);
    }

    /**
     * Ask a site for a backup now.
     *
     * Queues the job; the connector claims it on its next run. Nothing here reaches into a site - the
     * platform never calls out, which is why a site behind NAT works at all.
     */
    public function store(Request $request, Site $site, Organisation $organisation): RedirectResponse
    {
        abort_if($site->organisation_id !== $organisation->id, 404);
        abort_unless(app(Membership::class)->canAdminister(), 403);

        /*
         | Every reason a backup cannot be taken, asked at the moment of asking.
         |
         | This used to check the capability alone. Everything else was enforced further downstream —
         | most consequentially the recovery key, which JobService::claimFor() checks when the site
         | next checks in and then cancels the job. So pressing this on an organisation with no key
         | flashed "Backup requested", wrote a REQUESTED row on the timeline, and produced nothing,
         | with the actual reason stated on a different screen. The same conditions now decide whether
         | the button is drawn at all; this is the guard for the gap between the two.
        */
        $readiness = $this->readiness->for($site);

        if (! $readiness['ready']) {
            return back()->withErrors(['site' => $readiness['blockers'][0]]);
        }

        /*
         | Attributed, and idempotent, neither of which it was.
         |
         | Without a key, JobService::outstandingFor() returns immediately and two presses queue two
         | backups of the same database - and the second is not free: it is another full dump on a
         | production site. The Refresh button has always passed one. Without an actor, the audit row
         | for a job that reads an entire database says only that the system asked for it.
        */
        try {
            $job = $this->jobs->enqueue(
                $site,
                Jobs::BACKUP_CREATE,
                actor: $request->user(),
                idempotencyKey: 'backup:manual',
            );
        } catch (JobRejectedException $e) {
            // Reachable despite the readiness check above: a connector can go away between the screen
            // rendering and the button being pressed. Uncaught, that was a 500 on a routine race —
            // every other caller of enqueue() already handled it.
            return back()->withErrors(['site' => match ($e->reason) {
                JobRejectedException::SITE_NOT_CONNECTED => "{$site->name} has no active connector, so it cannot be asked for a backup.",
                JobRejectedException::CAPABILITY_NOT_GRANTED => "{$site->name} does not have permission to create backups.",
                // Same race as the connector one: a key can be revoked between the screen rendering
                // and the button being pressed.
                JobRejectedException::NO_RECOVERY_KEY => 'This organisation has no active recovery key, so there is nothing to encrypt a backup to.',
                default => "Could not request a backup from {$site->name}.",
            }]);
        }

        // The request itself is now visible on the screen, so the timeline should carry it too. The
        // vocabulary already had a word for this and nothing was writing it.
        $this->timeline->platform(
            event: BackupEvent::REQUESTED,
            site: $site,
            job: $job,
            detail: 'Requested from the backups screen.',
        );

        return back()->with(
            'status',
            "Backup requested for {$site->name}. It will run when the site next checks in."
        );
    }

    /**
     * Ask several sites for a backup at once.
     *
     * The fleet equivalent of the button above, and it queues exactly what that queues, per site,
     * through the same readiness check and the same idempotency key. Nothing here is a bulk code
     * path: a request for twelve backups is twelve independent requests, so one site refusing
     * changes nothing about the other eleven.
     *
     * **What it will not do is report success for sites it skipped.** Modelled on
     * {@see SiteController::refreshAll()}, which learned the same lesson: silently backing up four
     * of twelve and flashing "requested" is a half-truth an operator discovers at the worst possible
     * moment. The count that was queued goes in the status band; everything that did not, and why,
     * goes in the warning band beside it.
     *
     * Outside `site.scoped`, which binds a single {site}. The scoping is done here instead, in the
     * query - a ULID belonging to another organisation matches nothing rather than 404ing the whole
     * request, because one stale identifier in a form should not cancel eleven good ones.
     */
    public function storeMany(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate([
            'sites' => ['required', 'array', 'max:200'],

            // Length rather than a ULID rule: the value is looked up against this organisation's own
            // rows, so a malformed one finds nothing. The bound is here to keep a large paste out of
            // the query, not to validate the format.
            'sites.*' => ['required', 'string', 'max:64'],
        ]);

        $requested = array_unique($validated['sites']);

        $sites = Site::query()
            ->where('organisation_id', $organisation->id)
            ->whereIn('external_id', $requested)
            ->active()
            ->with(['organisation', 'capabilityGrants'])
            ->orderBy('name')
            ->get();

        $queued = 0;

        /** @var array<string, int> $skipped reason => how many sites gave it */
        $skipped = [];

        foreach ($sites as $site) {
            $readiness = $this->readiness->for($site);

            if (! $readiness['ready']) {
                $skipped[$readiness['blockers'][0]] = ($skipped[$readiness['blockers'][0]] ?? 0) + 1;

                continue;
            }

            try {
                $job = $this->jobs->enqueue(
                    $site,
                    Jobs::BACKUP_CREATE,
                    actor: $request->user(),
                    idempotencyKey: 'backup:manual',
                );
            } catch (JobRejectedException $e) {
                // The same race the single-site button catches: a connector or a key can go away
                // between the checkbox being ticked and the button being pressed. Counted as a skip
                // rather than thrown, because eleven other sites are mid-loop.
                $reason = match ($e->reason) {
                    JobRejectedException::SITE_NOT_CONNECTED => 'This site has no active connector, so there is nothing to ask.',
                    JobRejectedException::CAPABILITY_NOT_GRANTED => 'This site has not granted permission to create backups.',
                    JobRejectedException::NO_RECOVERY_KEY => 'This organisation has no active recovery key, so there is nothing to encrypt a backup to.',
                    default => 'This site could not be asked for a backup.',
                };

                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $this->timeline->platform(
                event: BackupEvent::REQUESTED,
                site: $site,
                job: $job,
                detail: 'Requested from the sites screen.',
            );

            $queued++;
        }

        // Asked for and not found: archived, removed, or belonging to somebody else. Reported rather
        // than ignored - a form that silently drops identifiers is one nobody can debug.
        $missing = count($requested) - $sites->count();

        if ($missing > 0) {
            $skipped['This site is no longer in your fleet.'] = $missing;
        }

        $response = $queued === 0
            ? back()->withErrors(['sites' => 'Nothing was requested. '.$this->skippedSentence($skipped)])
            : back()->with('status', sprintf(
                'Backup requested for %d %s. Each will run when that site next checks in.',
                $queued,
                $queued === 1 ? 'site' : 'sites',
            ));

        if ($queued > 0 && $skipped !== []) {
            $response->with('warning', $this->skippedSentence($skipped));
        }

        return $response;
    }

    /**
     * Why some sites were left out, in their own words.
     *
     * The blockers are reused verbatim rather than translated into short labels. A second vocabulary
     * for the same conditions is a second thing to keep in step, and the sentence somebody reads here
     * should be the sentence they will read on the site itself when they go and look.
     *
     * `$noun` is what was skipped. It defaults to `site` so that the fleet-wide backup button reads
     * exactly as it always has, and `destroyMany()` passes `backup` rather than growing a second
     * copy of this method - the argument against a second vocabulary applies just as well to a
     * second sentence.
     *
     * @param  array<string, int>  $skipped
     */
    private function skippedSentence(array $skipped, string $noun = 'site'): string
    {
        $total = array_sum($skipped);

        $clauses = [];

        foreach ($skipped as $reason => $count) {
            $clauses[] = sprintf('%d %s: %s', $count, Str::plural($noun, $count), $reason);
        }

        return sprintf(
            '%d %s %s skipped. %s',
            $total,
            Str::plural($noun, $total),
            $total === 1 ? 'was' : 'were',
            implode(' ', $clauses),
        );
    }

    /**
     * Stop waiting for a backup that was asked for.
     *
     * Reported from use: a backup sitting at "Collected by site" with no way to call it off. Until
     * the expiry sweep runs - seven hours later - the screen keeps saying it is coming, the
     * idempotency key stays outstanding so a fresh one cannot be requested, and whatever eventually
     * arrives is stored and billed.
     *
     * What this does **not** do is stop the site. The platform never calls out; a connector that has
     * already collected the job is dumping a database on a machine we cannot reach, and pretending
     * otherwise would be the kind of button that lies. What it does is end the platform's half: the
     * job is finished as cancelled, so the declaration that follows is refused by the declare
     * endpoint, which already requires a claimed job. The copy on the screen says this and no more.
     *
     * Administrators, and deliberately outside the recent-authentication group. Stopping something
     * is the one action here that is not an escalation - it grants nothing, it destroys no stored
     * backup, and its whole value is being available at the moment somebody realises they did not
     * want this. A gate here would be a gate on the brake pedal.
     */
    public function cancel(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate(['job' => ['required', 'string', 'max:64']]);

        $job = RemoteJob::query()
            ->where('external_id', $validated['job'])
            ->where('type', Jobs::BACKUP_CREATE)
            ->whereHas('site', fn ($query) => $query->where('organisation_id', $organisation->id))
            ->first();

        abort_if($job === null, 404);

        try {
            $this->jobs->cancel($job, $request->user(), 'Cancelled from the backups screen');
        } catch (JobRejectedException) {
            // Already finished. Two people pressing the same button, or a site reporting in while
            // somebody was deciding - neither is an error worth a red banner.
            return back()->with('status', 'That backup had already finished.');
        }

        /*
         | Settle the artifact, if the site got as far as declaring one.
         |
         | Without this the row stays `pending`, which the screen renders as "Uploading" - so
         | cancelling would clear the in-flight notice and leave a different row a few inches away
         | still saying the backup was on its way. Nothing was stored, so nothing is lost.
         |
         | Notification suppressed: a channel told "backup failed" seconds after somebody
         | deliberately stopped it is a channel that learns to cry wolf.
        */
        $artifact = BackupArtifact::query()
            ->where('remote_job_id', $job->id)
            ->whereIn('state', [BackupArtifact::STATE_PENDING, BackupArtifact::STATE_UPLOADED])
            ->first();

        if ($artifact !== null) {
            $this->backups->fail($artifact, 'Cancelled before it finished', notify: false);
        }

        return back()->with('status', implode(' ', [
            'Backup cancelled.',
            'We have stopped waiting for it and will refuse it if it arrives —',
            'the site may still finish its own copy, which we cannot stop from here.',
        ]));
    }

    /**
     * Delete an artifact before its retention date.
     *
     * Two different things, on one button, because to somebody looking at the list they are the
     * same gesture. A stored artifact is deleted and leaves a tombstone; a declaration that never
     * stored anything is discarded outright. {@see BackupService::discard()} for why the second is
     * not simply the first with a different reason string.
     */
    public function destroy(Request $request, BackupArtifact $artifact, Organisation $organisation): RedirectResponse
    {
        abort_if($artifact->organisation_id !== $organisation->id, 404);
        abort_unless(app(Membership::class)->isOwner(), 403);

        if ($artifact->neverStored()) {
            $this->backups->discard($artifact, $request->user());

            return back()->with('status', 'Removed. Nothing was stored for that backup; the audit log still records it.');
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->backups->delete($artifact, $validated['reason'], $request->user());

        return back()->with('status', 'Backup deleted. Its encryption key was destroyed with it.');
    }

    /**
     * Several artifacts at once, from the checkboxes on the backups screen.
     *
     * The bulk sibling of {@see destroy()}, and it makes the same two-way split for the same reason:
     * a stored artifact is deleted and leaves a tombstone, a declaration that stored nothing is
     * discarded outright. A selection may hold both, so the summary reports both counts rather than
     * adding them together - "four deleted" would be false about the row that had nothing to delete.
     *
     * Owners, matching `destroy()` rather than {@see storeMany()}. Batching an act does not lower the
     * privilege it needs, and the two bulk routes disagreeing about the role is correct: asking for a
     * backup and destroying one were never the same permission.
     *
     * **Synchronous, and bounded at a hundred by validation.** A hundred sequential deletes against
     * an object store is real latency, and moving it to a queue was considered and rejected: the
     * single-row button is synchronous, and somebody who has just confirmed their password to destroy
     * forty encryption keys is owed a page saying they are gone rather than one saying they will be.
     * The bound comes from `index()`, which renders at most a hundred rows - so the ceiling is what
     * the screen can offer rather than a number picked to sound safe.
     *
     * Outside `site.scoped`, like `storeMany()`, and scoped in the query for the same reason: an
     * identifier belonging to another organisation matches nothing rather than 404ing the whole
     * request, because one stale checkbox should not cancel thirty-nine good ones.
     *
     * **No summary audit row.** {@see BackupService::delete()} and {@see BackupService::discard()}
     * already write one entry per artifact, keyed to its external id and carrying the reason, the
     * bytes and the checksum. A summary on top would be a second account of the same events that can
     * disagree with the first. That a deletion was part of a sweep is carried by the reason itself —
     * see {@see self::BULK_DELETE_REASON}.
     */
    public function destroyMany(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless(app(Membership::class)->isOwner(), 403);

        $validated = $request->validate([
            // A hundred, not `storeMany()`'s two hundred: `index()` renders `limit(100)`, so the
            // screen cannot offer more than this and a larger bound would describe nothing.
            'artifacts' => ['required', 'array', 'max:100'],

            // Length rather than a ULID rule, as above: the value is looked up against this
            // organisation's own rows, so a malformed one finds nothing.
            'artifacts.*' => ['required', 'string', 'max:64'],
        ]);

        // Unique first, so a checkbox that somehow posts twice is one deletion rather than one
        // success and one phantom miss in the count below.
        $requested = array_unique($validated['artifacts']);

        $artifacts = BackupArtifact::query()
            ->where('organisation_id', $organisation->id)
            ->whereIn('external_id', $requested)

            // Both service calls audit against the site, and a hundred artifacts would otherwise be
            // a hundred queries for it.
            ->with('site')
            ->get();

        $deleted = 0;
        $removed = 0;

        /** @var array<string, int> $skipped reason => how many artifacts gave it */
        $skipped = [];

        foreach ($artifacts as $artifact) {
            if ($artifact->neverStored()) {
                $this->backups->discard($artifact, $request->user());

                $removed++;

                continue;
            }

            if (! $artifact->isRetrievable()) {
                /*
                 | Neither branch applies: already deleted, already expired, or still uploading.
                 |
                 | `destroy()` has no equivalent guard and does not need one - the view draws no
                 | button on such a row, so the only way to reach it there is a hand-made request.
                 | Here the row is one of a hundred and the screen may have gone stale between being
                 | rendered and the button being pressed, which is an ordinary thing to happen and
                 | should be reported rather than counted as a success.
                 */
                $reason = 'This backup had already gone, so there was nothing left to destroy.';

                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $this->backups->delete($artifact, self::BULK_DELETE_REASON, $request->user());

            $deleted++;
        }

        // Asked for and not found: already gone, or belonging to somebody else. Reported rather than
        // ignored, for the reason storeMany() gives - a form that silently drops identifiers is one
        // nobody can debug.
        $missing = count($requested) - $artifacts->count();

        if ($missing > 0) {
            $skipped['This backup is no longer in your list.'] = $missing;
        }

        $done = [];

        if ($deleted > 0) {
            $done[] = sprintf(
                '%d %s deleted, and %s destroyed with %s.',
                $deleted,
                $deleted === 1 ? 'backup was' : 'backups were',
                $deleted === 1 ? 'its encryption key was' : 'their encryption keys were',
                $deleted === 1 ? 'it' : 'them',
            );
        }

        if ($removed > 0) {
            $done[] = sprintf(
                '%d %s removed for %s that stored nothing; the audit log still records %s.',
                $removed,
                $removed === 1 ? 'row was' : 'rows were',
                $removed === 1 ? 'a backup' : 'backups',
                $removed === 1 ? 'it' : 'them',
            );
        }

        $response = $done === []
            ? back()->withErrors(['artifacts' => 'Nothing was deleted. '.$this->skippedSentence($skipped, 'backup')])
            : back()->with('status', implode(' ', $done));

        if ($done !== [] && $skipped !== []) {
            $response->with('warning', $this->skippedSentence($skipped, 'backup'));
        }

        return $response;
    }

    /**
     * Stop showing one "Did not complete" notice.
     *
     * Administrators rather than owners, matching "Back up now" above: this is the same person
     * dealing with the same failure. Nothing is destroyed - the job, its reason and the audit row
     * for the failure all survive - which is also why it sits outside the recent-authentication
     * group that deleting a backup sits inside.
     */
    public function dismissFailure(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate(['job' => ['required', 'string', 'max:64']]);

        return $this->reportDismissal(
            // Zero rows is a stale screen rather than an error: two people can press this on the
            // same notice, and the second should see the outcome they asked for.
            $this->failed->dismiss($organisation->id, $validated['job']),
            $request,
            $organisation,
            null,
        );
    }

    /**
     * Stop showing every notice currently on the screen, or every notice for one site.
     */
    public function clearFailures(Request $request, Organisation $organisation): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $site = null;

        if ($request->filled('site')) {
            $site = Site::query()
                ->where('organisation_id', $organisation->id)
                ->where('external_id', $request->string('site')->toString())
                ->first();

            abort_if($site === null, 404);
        }

        return $this->reportDismissal(
            $this->failed->dismissAll($organisation->id, $site),
            $request,
            $organisation,
            $site,
        );
    }

    /**
     * The audit row and the sentence, for either of the two above.
     *
     * One entry for however many notices went, rather than one each. Silencing a panel is worth
     * recording - it is how a fleet stops reporting a problem it still has - but forty rows saying
     * so is a log nobody reads.
     */
    private function reportDismissal(int $cleared, Request $request, Organisation $organisation, ?Site $site): RedirectResponse
    {
        if ($cleared === 0) {
            return back()->with('status', 'There was nothing left to clear.');
        }

        $this->audit->record(
            action: 'backup.failures.dismissed',
            organisation: $organisation,
            site: $site,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            after: [
                'notices' => $cleared,
                'scope' => $site instanceof Site ? 'site' : 'organisation',
            ],
        );

        return back()->with('status', $cleared === 1
            ? 'Notice cleared. The failure is still in the activity log.'
            : "{$cleared} notices cleared. The failures are still in the activity log.");
    }
}
