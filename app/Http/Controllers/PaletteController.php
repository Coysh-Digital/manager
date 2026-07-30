<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

/**
 * What the command palette can jump to.
 *
 * One request, cached in the page for the session rather than a search-as-you-type endpoint. A fleet
 * is tens or hundreds of sites, not millions: the whole list is a few kilobytes, and sending it once
 * makes filtering instant and works on a train. A keystroke-per-request design would be slower, more
 * code on both sides, and would put a log of what somebody typed into the access log.
 *
 * Scoped to the current organisation, like everything else. The palette cannot offer a destination
 * the person could not already reach.
 */
final class PaletteController
{
    public function __invoke(Organisation $organisation): JsonResponse
    {
        $sites = Site::query()
            ->where('organisation_id', $organisation->id)
            ->active()
            ->orderBy('name')
            ->get(['external_id', 'name', 'expected_domain', 'environment', 'status']);

        return response()->json([
            'sites' => $sites->map(fn (Site $site): array => [
                'name' => $site->name,
                'domain' => $site->expected_domain,
                'environment' => $site->environment,
                'status' => $site->status,
                'url' => route('sites.show', $site),

                // The tabs, so "acme health" goes straight there rather than to the site and then a
                // click. This is the whole reason a palette beats a search box.
                'tabs' => [
                    'overview' => route('sites.show', $site),
                    'health' => route('sites.health', $site),
                    'updates' => route('sites.updates', $site),
                    'security' => route('sites.security', $site),
                    'backups' => route('sites.backups', $site),
                    'settings' => route('sites.settings', $site),
                    'audit' => route('sites.audit', $site),
                ],
            ])->all(),

            'screens' => [
                ['name' => 'Sites', 'url' => route('sites.index')],
                ['name' => 'Updates', 'url' => route('updates.index')],
                ['name' => 'Findings', 'url' => route('findings.index')],
                ['name' => 'Backups', 'url' => route('backups.index')],
                ['name' => 'Activity log', 'url' => route('activity.index')],
                ['name' => 'Settings', 'url' => route('settings.show')],
                ['name' => 'People', 'url' => route('settings.show').'#people'],
                ['name' => 'Account and security', 'url' => route('account.show')],
            ],
        ]);
    }
}
