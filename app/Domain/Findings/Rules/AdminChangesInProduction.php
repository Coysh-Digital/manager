<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class AdminChangesInProduction implements Rule
{
    public function key(): string
    {
        return 'admin_changes_in_production';
    }

    public function requiresCapability(): string
    {
        return 'security:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->isProduction() || $snapshot->flag('allow_admin_changes') !== true) {
            return null;
        }

        return new RuleMatch(
            // Medium rather than high: it widens what a compromised admin session can do, but it is
            // not itself an exposure.
            severity: Severity::MEDIUM,
            title: 'Schema changes are permitted in production',
            detail: 'allowAdminChanges is on, so anyone with control-panel access can alter fields, '
                .'sections and settings directly in production, bypassing project config and code '
                .'review. Turn it off and deploy schema changes instead.',
            evidence: ['allow_admin_changes' => true],
        );
    }
}
