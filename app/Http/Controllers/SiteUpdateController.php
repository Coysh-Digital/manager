<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Updates\ChangelogFetcher;
use App\Domain\Updates\ChangelogLink;
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

    public function __construct(
        private readonly PluginInventory $plugins,
        private readonly ChangelogFetcher $changelogs,
    ) {}

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

            // Whether to offer the panel at all. Nothing is fetched by rendering this screen — the
            // request happens when somebody opens the notes, which is the same moment the link
            // would have taken them to GitHub.
            'canReadChangelog' => $this->changelogs->enabled() && $updates?->craft_update_available,
        ]);
    }

    /**
     * Craft's release notes for the versions between where a site is and where it could be.
     *
     * Returns a fragment for the panel on the screen above. Nothing about this site is sent
     * anywhere: the versions decide which sections to keep, and the sections are cut from a file
     * already cached for the whole installation.
     */
    public function changelog(Site $site): View
    {
        $updates = $this->latestUpdateReport($site);

        return view('sites.partials.changelog', [
            'notes' => $this->changelogs->between(
                'craft',
                $updates?->craft_current,
                $updates?->craft_latest,
            ),
            'link' => ChangelogLink::craft(),
        ]);
    }
}
