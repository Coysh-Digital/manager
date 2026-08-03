<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The backups screen.
 *
 * Shows artifacts, what they cost in storage, and when each will be deleted.
 *
 *  - **Download.** Ciphertext only, and it goes through {@see BackupDownloadController} — on Cloud as
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
    ) {}

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
            ->where('organisation_id', $organisation->id)
            ->active()
            ->whereHas('capabilityGrants', fn ($query) => $query
                ->where('capability', 'backups:create')
                ->where('state', 'granted'))
            ->orderBy('name')
            ->get();

        // The same readiness the site screen uses, per row. A fleet where none of these buttons can
        // do anything is worth seeing as a fleet rather than one site at a time — the usual cause is
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

            'storedBytes' => $artifacts
                ->where('state', BackupArtifact::STATE_STORED)
                ->sum('ciphertext_bytes'),

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
     * Queues the job; the connector claims it on its next run. Nothing here reaches into a site — the
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
         | backups of the same database — and the second is not free: it is another full dump on a
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
     * Delete an artifact before its retention date.
     */
    public function destroy(Request $request, BackupArtifact $artifact, Organisation $organisation): RedirectResponse
    {
        abort_if($artifact->organisation_id !== $organisation->id, 404);
        abort_unless(app(Membership::class)->isOwner(), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $this->backups->delete($artifact, $validated['reason'], $request->user());

        return back()->with('status', 'Backup deleted. Its encryption key was destroyed with it.');
    }
}
