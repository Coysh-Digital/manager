<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * Dev mode left on in production.
 *
 * Checks the environment, as every configuration rule here does. Dev mode on a development site is
 * correct, and reporting it would train people to ignore the screen - which costs more than the
 * finding is worth.
 */
final class DevModeInProduction implements Rule
{
    public function key(): string
    {
        return 'dev_mode_in_production';
    }

    public function requiresCapability(): string
    {
        return 'security:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->isProduction() || $snapshot->flag('dev_mode') !== true) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::HIGH,
            title: 'Development mode is on in production',
            detail: 'Craft is running with devMode enabled, so errors render full stack traces to '
                .'visitors and template caching is disabled. Set devMode to false in the production '
                .'config and clear caches.',
            evidence: ['dev_mode' => true, 'environment' => $snapshot->site->environment],
        );
    }
}
