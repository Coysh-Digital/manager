<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A plugin its author has marked abandoned.
 *
 * Worth its own finding rather than folding into "out of date". An abandoned plugin will never
 * receive a security fix, which makes it a permanent problem rather than a pending task — and one
 * that needs a replacement decision rather than an update.
 */
final class AbandonedPlugin implements Rule
{
    public function key(): string
    {
        return 'abandoned_plugin';
    }

    public function requiresCapability(): string
    {
        return 'updates:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $report = $snapshot->updates;

        if ($report === null || $report->abandoned_plugins === 0) {
            return null;
        }

        $handles = [];

        foreach ($report->payload['plugins'] ?? [] as $plugin) {
            if ((bool) ($plugin['abandoned'] ?? false)) {
                $handles[] = (string) $plugin['handle'];
            }
        }

        return new RuleMatch(
            severity: Severity::MEDIUM,
            title: count($handles) === 1
                ? 'An installed plugin has been abandoned'
                : count($handles).' installed plugins have been abandoned',
            detail: 'Abandoned: '.implode(', ', $handles).'. These will not receive further fixes, '
                .'including security fixes. Plan a replacement rather than waiting for an update.',
            evidence: ['plugins' => $handles],
        );
    }
}
