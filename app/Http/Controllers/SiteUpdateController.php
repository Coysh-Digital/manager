<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Updates\PluginInventory;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\Membership;
use App\Models\Site;
use Illuminate\Contracts\View\View;

/**
 * Everything installed on one site, and what it could be on instead.
 *
 * The fleet-wide Updates screen answers "which sites need attention". This answers "what, exactly,
 * on this one" — which until now the interface could not, despite holding the data: the site page
 * showed a plugin count and the fleet page showed the first three handles.
 */
final class SiteUpdateController
{
    use ResolvesSiteContext;

    public function __construct(private readonly PluginInventory $plugins) {}

    public function show(Site $site): View
    {
        $inventory = $this->latestInventoryReport($site);
        $updates = $this->latestUpdateReport($site);

        return view('sites.updates', [
            ...$this->siteContext($site),
            'inventoryReport' => $inventory,
            'updateReport' => $updates,
            'plugins' => $this->plugins->assemble($inventory, $updates),

            // Requesting a check is behind recent authentication, so the button is only offered to
            // somebody who could actually use it. Showing a control that returns a redirect to a
            // password prompt is a worse experience than not showing it.
            'canRequest' => app(Membership::class)->canAdminister(),
        ]);
    }
}
