<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\BackupService;
use App\Domain\Capability\CapabilityService;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Site;
use Illuminate\Contracts\View\View;

/**
 * One site's backups.
 *
 * The fleet screen answers "what are we holding, and what is it costing"; this answers "is this site
 * protected, and how far back does that go" — which is the question asked while looking at a
 * particular site, and previously meant leaving it for a list of every artifact in the organisation.
 *
 * No download button and no restore button, for the reasons the fleet screen gives: decrypting a
 * multi-gigabyte artifact through a web request loses a race with a timeout, and restore has no
 * tested recovery path yet.
 */
final class SiteBackupController
{
    use ResolvesSiteContext;

    public function __construct(private readonly BackupService $backups) {}

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
            'artifacts' => $artifacts,
            'latest' => $stored->first(),
            'storedCount' => $stored->count(),
            'storedBytes' => (int) $stored->sum('ciphertext_bytes'),

            // Oldest first for the chart: a size trend read right to left is nobody's instinct.
            'trend' => $stored->sortBy('taken_at')->values()->map(fn (BackupArtifact $artifact): array => [
                'label' => $artifact->taken_at->format('j M'),
                'value' => round($artifact->plaintext_bytes / 1048576, 2),
                'size' => $artifact->humanSize(),
                'state' => $artifact->verified_at !== null ? 'verified' : 'stored',
                'text' => $artifact->humanSize(),
            ])->all(),

            'storage' => $this->backups->describeStorage(),
            'acknowledgement' => CapabilityService::acknowledgementFor('backups:create'),
        ]);
    }
}
