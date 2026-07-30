<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\BackupService;
use App\Domain\Capability\CapabilityService;
use App\Domain\Job\JobService;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The backups screen.
 *
 * Shows artifacts, what they cost in storage, and when each will be deleted. There is deliberately no
 * download button and no restore button:
 *
 *  - **Download.** Decrypting a multi-gigabyte artifact through a web request means holding a worker
 *    against a timeout it will probably lose, and leaving a half-written file that cannot be told apart
 *    from a complete one. The screen shows the checksum and the command instead.
 *  - **Restore.** Not built at all. The specification is explicit that it waits until its threat model,
 *    confirmation flow and failure recovery are designed and tested, and a button that only worked
 *    sometimes would be worse than the absence of one.
 */
final class BackupController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly JobService $jobs,
    ) {}

    public function index(Organisation $organisation): View
    {
        $artifacts = BackupArtifact::query()
            ->with('site')
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

        return view('backups.index', [
            'organisation' => $organisation,
            'membership' => app(Membership::class),
            'artifacts' => $artifacts,

            // Sites that could be backed up, so somebody who has granted the permission but never seen
            // an artifact can tell which of the two things is missing.
            'permittedSites' => $permitted,

            'storedBytes' => $artifacts
                ->where('state', BackupArtifact::STATE_STORED)
                ->sum('ciphertext_bytes'),

            'storage' => $this->backups->describeStorage(),
            'acknowledgement' => CapabilityService::acknowledgementFor('backups:create'),
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

        // Checked here as well as in the job service. A site whose permission was revoked between the
        // screen rendering and the button being pressed must not get a job queued for it.
        if (! $site->hasCapability('backups:create')) {
            return back()->withErrors([
                'site' => 'That site does not have permission to create backups.',
            ]);
        }

        $job = $this->jobs->enqueue($site, Jobs::BACKUP_CREATE);

        return back()->with(
            'status',
            "Backup requested for {$site->name}. It will run when the site next checks in "
            ."(job {$job->external_id})."
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
