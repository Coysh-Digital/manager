<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class PluginSecurityRelease implements Rule
{
    public function key(): string
    {
        return 'plugin_security_release';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): string
    {
        return 'updates:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $report = $snapshot->updates;

        if ($report === null || $report->plugin_security_releases === 0) {
            return null;
        }

        $affected = array_values(array_filter(
            $report->pluginsNeedingUpdates(),
            static fn (array $plugin): bool => (bool) ($plugin['security_release_available'] ?? false),
        ));

        $handles = array_map(static fn (array $plugin): string => (string) $plugin['handle'], $affected);

        return new RuleMatch(
            severity: Severity::HIGH,
            title: $report->plugin_security_releases === 1
                ? 'A plugin has an outstanding security release'
                : $report->plugin_security_releases.' plugins have outstanding security releases',
            detail: 'Affected: '.implode(', ', $handles).'. Update them and redeploy.',
            // Handles and counts. Not what the release fixes.
            evidence: ['plugins' => $handles, 'count' => $report->plugin_security_releases],
        );
    }
}
