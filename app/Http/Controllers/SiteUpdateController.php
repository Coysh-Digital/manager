<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Updates\ChangelogFetcher;
use App\Domain\Updates\ChangelogLink;
use App\Domain\Updates\PluginChangelog;
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
        private readonly PluginChangelog $pluginChangelog,
    ) {}

    public function show(Site $site): View
    {
        $inventory = $this->latestInventoryReport($site);
        $updates = $this->latestUpdateReport($site);
        $plugins = $this->plugins->assemble($inventory, $updates);

        return view('sites.updates', [
            ...$this->siteContext($site),
            'inventoryReport' => $inventory,
            'updateReport' => $updates,
            'plugins' => $plugins,

            // Which plugin rows can offer a panel. One query for the lot rather than one per row,
            // and it asks only whether notes exist — the text itself is loaded when somebody opens
            // one, the same way Craft's are.
            'pluginNotes' => $this->pluginChangelog->handlesWithNotes($plugins),

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
            'source' => 'craft',
        ]);
    }

    /**
     * One plugin's release notes, for the versions between where this site is and where it could be.
     *
     * Nothing is fetched here either, and for a stronger reason than the Craft panel: these notes
     * were forwarded by connectors and are stored against a plugin and a version, so serving them
     * touches no network and reads no table that knows what a site is. The versions come from this
     * site's own report and decide which stored notes to show.
     *
     * A handle that is not one produces the fallback panel rather than a 404 — it is a plugin whose
     * report was odd, not a route somebody has no business calling.
     */
    public function pluginChangelog(Site $site, string $handle): View
    {
        $updates = $this->latestUpdateReport($site);
        $plugin = collect($this->plugins->assemble($this->latestInventoryReport($site), $updates))
            ->firstWhere('handle', $handle);

        return view('sites.partials.changelog', [
            'notes' => $plugin === null
                ? null
                : $this->pluginChangelog->between($handle, $plugin['current'], $plugin['latest']),
            'link' => ChangelogLink::plugin($handle),
            'source' => 'plugin',
        ]);
    }
}
