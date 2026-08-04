<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Models\InventoryReport;
use App\Models\UpdateReport;

/**
 * Every plugin on a site, with what it is on and what it could be on.
 *
 * Two reports answer half the question each, and neither answers it alone:
 *
 *  - The **inventory** report lists what is *installed* - handle, version, enabled - for every
 *    plugin on the site, whether or not anybody publishes updates for it.
 *  - The **update** report lists what Craft's update service *knows about*, which is the plugins in
 *    the Plugin Store. A private plugin, a client's bespoke module, anything installed from a VCS
 *    repository: present in the first list, absent from the second.
 *
 * Joining them the other way round - starting from the update report - is the mistake this class
 * exists to prevent. It produces a plugin list that silently omits exactly the plugins nobody is
 * watching, which is the wrong half to lose. A plugin with no update data reads as "Not checked",
 * never as "Up to date": those are different statements and only one of them is true.
 */
final class PluginInventory
{
    public const SECURITY = 'security';

    public const ABANDONED = 'abandoned';

    public const UPDATE = 'update';

    public const CURRENT = 'current';

    public const NOT_CHECKED = 'not_checked';

    public const DISABLED = 'disabled';

    /**
     * @return list<array{handle: string, name: string, current: string, latest: ?string, enabled: bool, abandoned: bool, releases_behind: ?int, state: string}>
     */
    public function assemble(?InventoryReport $inventory, ?UpdateReport $updates): array
    {
        $installed = is_array($inventory?->value('plugins')) ? $inventory->value('plugins') : [];
        $known = $updates?->pluginsByHandle() ?? [];

        $rows = [];

        foreach ($installed as $plugin) {
            $handle = (string) ($plugin['handle'] ?? '');

            if ($handle === '') {
                continue;
            }

            $rows[$handle] = $this->row(
                handle: $handle,
                current: (string) ($plugin['version'] ?? 'unknown'),
                enabled: (bool) ($plugin['enabled'] ?? true),
                update: $known[$handle] ?? null,
            );
        }

        // A plugin the update report knows about but the inventory does not. Ordinarily impossible,
        // but the two reports arrive independently and on different schedules - an hourly inventory
        // and a daily update check - so a plugin uninstalled this morning can still be in yesterday's
        // update report. Including it is better than dropping it: the row is honest about having no
        // installed version, and disagreement between two reports is worth seeing.
        foreach ($known as $handle => $update) {
            if (isset($rows[$handle])) {
                continue;
            }

            $rows[$handle] = $this->row(
                handle: (string) $handle,
                current: (string) ($update['current'] ?? 'unknown'),
                enabled: true,
                update: $update,
            );
        }

        return $this->sorted(array_values($rows));
    }

    /**
     * @param  array<string, mixed>|null  $update
     * @return array{handle: string, name: string, current: string, latest: ?string, enabled: bool, abandoned: bool, releases_behind: ?int, state: string}
     */
    private function row(string $handle, string $current, bool $enabled, ?array $update): array
    {
        $abandoned = (bool) ($update['abandoned'] ?? false);
        $available = (bool) ($update['update_available'] ?? false);
        $security = (bool) ($update['security_release_available'] ?? false);

        return [
            'handle' => $handle,
            // The inventory schema carries no plugin name - handles only, which is all advisory
            // matching needs. The update report has one when the Plugin Store knows the plugin.
            'name' => (string) ($update['name'] ?? $handle),
            'current' => $current,
            'latest' => isset($update['latest']) ? (string) $update['latest'] : null,
            'enabled' => $enabled,
            'abandoned' => $abandoned,
            'releases_behind' => isset($update['releases_behind']) ? (int) $update['releases_behind'] : null,
            'state' => match (true) {
                $security => self::SECURITY,
                $abandoned => self::ABANDONED,
                $available => self::UPDATE,

                // Disabled is worth its own state, but only once it is established that nothing is
                // wrong with it. A disabled plugin with a security release is still a security
                // release: the code is on disk and one switch away from running.
                ! $enabled => self::DISABLED,

                $update !== null => self::CURRENT,

                // No update data for this plugin. Either nothing has checked yet, or the plugin is
                // private and nothing ever will. Not the same as being up to date, and not shown as
                // though it were.
                default => self::NOT_CHECKED,
            },
        ];
    }

    /**
     * Worst first, then alphabetical.
     *
     * Security releases lead because they are the only rows with a clock on them. Abandoned plugins
     * come next: no fix is ever coming for one, which makes it the more permanent problem even
     * without a current advisory.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{handle: string, name: string, current: string, latest: ?string, enabled: bool, abandoned: bool, releases_behind: ?int, state: string}>
     */
    private function sorted(array $rows): array
    {
        $order = [
            self::SECURITY => 0,
            self::ABANDONED => 1,
            self::UPDATE => 2,
            self::NOT_CHECKED => 3,
            self::CURRENT => 4,
            self::DISABLED => 5,
        ];

        usort($rows, static function (array $a, array $b) use ($order): int {
            return [$order[$a['state']] ?? 9, mb_strtolower($a['name'])]
                <=> [$order[$b['state']] ?? 9, mb_strtolower($b['name'])];
        });

        /** @var list<array{handle: string, name: string, current: string, latest: ?string, enabled: bool, abandoned: bool, releases_behind: ?int, state: string}> $rows */
        return $rows;
    }

    /**
     * How a state should read, and in what tone.
     *
     * @return array{label: string, tone: string}
     */
    public static function badge(string $state): array
    {
        return match ($state) {
            self::SECURITY => ['label' => 'Security release', 'tone' => 'warn'],
            self::ABANDONED => ['label' => 'Abandoned', 'tone' => 'warn'],
            self::UPDATE => ['label' => 'Update available', 'tone' => 'info'],
            self::CURRENT => ['label' => 'Up to date', 'tone' => 'ok'],
            self::DISABLED => ['label' => 'Disabled', 'tone' => 'grey'],
            default => ['label' => 'Not checked', 'tone' => 'grey'],
        };
    }
}
