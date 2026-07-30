<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogQuery;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Everything that has been done to one site, in order.
 *
 * The same append-only, hash-chained log the fleet-wide Activity screen shows, filtered to this
 * site. Ordered by sequence rather than by time: sequence is the chain's own order, and two events
 * written in the same second would otherwise sort arbitrarily.
 */
final class SiteAuditController
{
    use ResolvesSiteContext;

    public function __construct(private readonly AuditLogQuery $log) {}

    public function show(Request $request, Site $site): View
    {
        $outcome = (string) $request->query('outcome', '');

        return view('sites.audit', [
            ...$this->siteContext($site),
            'events' => $this->log->forSite($site, $outcome === '' ? null : $outcome),
            'outcome' => $outcome,
            'loginReport' => $this->latestLoginReport($site),
        ]);
    }
}
