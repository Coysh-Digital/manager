<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class UpdatesAllowedInProduction implements Rule
{
    public function key(): string
    {
        return 'updates_allowed_in_production';
    }

    /**
     * Security rather than maintenance, despite the name. This is not "an update is available" —
     * it is Craft being willing to change its own code in production, which is an exposure whether
     * or not anything is out of date.
     */
    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): string
    {
        return 'security:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->isProduction() || $snapshot->flag('allow_updates') !== true) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::LOW,
            title: 'Updates can be installed from the control panel',
            detail: 'allowUpdates is on, so Craft and plugin updates can be applied in production '
                .'without going through a deployment. That leaves production ahead of the repository '
                .'and makes the next deploy a surprise.',
            evidence: ['allow_updates' => true],
        );
    }
}
