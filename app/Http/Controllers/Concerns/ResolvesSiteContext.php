<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Findings\Severity;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\InventoryReport;
use App\Models\LoginReport;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\UpdateReport;
use Illuminate\Support\Collection;

/**
 * What every screen about one site needs before it can render anything.
 *
 * The header, the tab bar and the flash banners are identical on all six tabs, and each of them
 * needs the connector and the counts that ride on the tabs. Assembled once here rather than six
 * times, so a tab cannot quietly disagree with its neighbours about whether a site is connected.
 *
 * Tenant scoping is deliberately absent: it belongs to the `site.scoped` middleware, which every
 * route carrying a {site} parameter has. A check a controller could forget is not a check.
 */
trait ResolvesSiteContext
{
    /**
     * @return array<string, mixed>
     */
    protected function siteContext(Site $site): array
    {
        $site->loadMissing(['activeConnector', 'capabilityGrants']);

        return [
            'site' => $site,
            'connector' => $site->activeConnector,
            'pendingConnector' => $site->connectors()
                ->where('state', Connector::STATE_PENDING_CONFIRMATION)
                ->latest('id')
                ->first(),

            // The two numbers on the tab bar. Both count things needing a person, so a zero renders
            // as no badge at all rather than as a badge announcing that nothing is wrong.
            'updateCount' => $this->latestUpdateReport($site)?->totalUpdates() ?? 0,
            'findingCount' => $site->outstandingFindings()->count(),
        ];
    }

    /**
     * Findings still true for this site, worst first.
     *
     * Sorted in PHP rather than SQL: severity is a word, not a number, and ordering alphabetically
     * would put "critical" below "low".
     *
     * @return Collection<int, Finding>
     */
    protected function outstandingFindings(Site $site): Collection
    {
        return $site->outstandingFindings()
            ->get()
            ->sortBy([
                fn (Finding $finding): int => Severity::rank($finding->severity),
                fn (Finding $finding): int => $finding->first_seen_at->getTimestamp(),
            ])
            ->values();
    }

    protected function latestInventoryReport(Site $site): ?InventoryReport
    {
        return $site->inventoryReports()->latest('received_at')->first();
    }

    protected function latestUpdateReport(Site $site): ?UpdateReport
    {
        return $site->updateReports()->latest('received_at')->first();
    }

    protected function latestRuntimeReport(Site $site): ?RuntimeReport
    {
        return $site->runtimeReports()->latest('received_at')->first();
    }

    protected function latestLoginReport(Site $site): ?LoginReport
    {
        return $site->loginReports()->latest('received_at')->first();
    }
}
