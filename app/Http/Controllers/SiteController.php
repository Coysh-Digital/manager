<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Connector;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The fleet.
 *
 * Filters live in the query string rather than the session, so a filtered view can be linked to,
 * bookmarked and reloaded — which is what people actually do with a screen they leave open.
 */
final class SiteController
{
    public function index(Request $request, Organisation $organisation): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'environment' => (string) $request->query('environment', ''),
        ];

        $sites = $this->query($organisation, $filters)->get();

        // Grouped by what needs doing, not alphabetically. The whole point of the screen is to
        // answer "what needs me today", and sorting by name buries that.
        $groups = [
            'Needs attention' => $sites->filter(fn (Site $site): bool => $this->needsAttention($site))->values(),
            'Steady' => $sites->filter(fn (Site $site): bool => $this->isSteady($site))->values(),
            'Not monitored' => $sites->filter(fn (Site $site): bool => $this->isUnmonitored($site))->values(),
        ];

        return view('sites.index', [
            'groups' => $groups,
            'filters' => $filters,
            'totalSites' => $organisation->sites()->active()->count(),
            'shown' => $sites->count(),
            'summary' => $this->summary($organisation),
        ]);
    }

    public function show(Site $site): View
    {
        $this->authoriseSite($site);

        $site->load(['activeConnector', 'capabilityGrants']);

        return view('sites.show', [
            'site' => $site,
            'connector' => $site->activeConnector()->first(),
            'pendingConnector' => $site->connectors()
                ->where('state', Connector::STATE_PENDING_CONFIRMATION)
                ->latest('id')
                ->first(),
            'latestReport' => $site->inventoryReports()->latest('received_at')->first(),
            'recentActivity' => AuditEvent::query()
                ->where('site_id', $site->id)
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * @param  array{q: string, status: string, environment: string}  $filters
     * @return Builder<Site>
     */
    private function query(Organisation $organisation, array $filters): Builder
    {
        return Site::query()
            // Every query starts scoped. Nothing relies on a caller remembering to add it.
            ->where('organisation_id', $organisation->id)
            ->active()
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('expected_domain', 'ilike', $term);
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['environment'] !== '', fn (Builder $query) => $query->where('environment', $filters['environment']))
            ->orderBy('name');
    }

    /**
     * @return array{total: int, needingAttention: int, connected: int, neverConnected: int}
     */
    private function summary(Organisation $organisation): array
    {
        $sites = $organisation->sites()->active()->get();

        return [
            'total' => $sites->count(),
            'needingAttention' => $sites->filter(fn (Site $site): bool => $this->needsAttention($site))->count(),
            'connected' => $sites->where('status', Site::STATUS_CONNECTED)->count(),
            'neverConnected' => $sites->where('status', Site::STATUS_NEVER_CONNECTED)->count(),
        ];
    }

    /**
     * A site that has reported, but has stopped, or has never managed to.
     *
     * "Last seen a while ago" is the signal that matters most in Phase 1: with only inventory
     * reporting implemented, a site falling silent is the one thing that genuinely needs a person.
     */
    private function needsAttention(Site $site): bool
    {
        if ($site->status === Site::STATUS_NOT_CONNECTED) {
            return true;
        }

        return $site->status === Site::STATUS_CONNECTED
            && $site->last_seen_at !== null
            && $site->last_seen_at->lt(now()->subHour());
    }

    private function isSteady(Site $site): bool
    {
        return $site->status === Site::STATUS_CONNECTED && ! $this->needsAttention($site);
    }

    private function isUnmonitored(Site $site): bool
    {
        return in_array($site->status, [Site::STATUS_NEVER_CONNECTED, Site::STATUS_PAUSED], true)
            && ! $this->needsAttention($site);
    }

    /**
     * Refuse a site belonging to another organisation.
     *
     * Route binding resolves on the external identifier alone, so this is what keeps one tenant
     * from reading another's site by pasting in a ULID.
     */
    private function authoriseSite(Site $site): void
    {
        abort_if($site->organisation_id !== app(Organisation::class)->id, 404);
    }
}
