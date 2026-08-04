<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Health\SiteUptime;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * How reliably a site has been checking in, and how it is coping.
 *
 * The window lives in the query string rather than the session, so a view somebody wants to keep
 * open on a wall can be linked to and reloaded - the same reasoning as the fleet filters.
 */
final class SiteHealthController
{
    use ResolvesSiteContext;

    public function __construct(private readonly SiteUptime $uptime) {}

    public function show(Request $request, Site $site): View
    {
        $window = (string) $request->query('window', '7d');

        if (! array_key_exists($window, SiteUptime::WINDOWS)) {
            $window = '7d';
        }

        return view('sites.health', [
            ...$this->siteContext($site),
            'window' => $window,
            'windows' => array_keys(SiteUptime::WINDOWS),
            'uptime' => $this->uptime->for($site, SiteUptime::WINDOWS[$window]),
            'latestReport' => $this->latestInventoryReport($site),
            'updateReport' => $this->latestUpdateReport($site),
            'runtimeReport' => $this->latestRuntimeReport($site),
        ]);
    }
}
