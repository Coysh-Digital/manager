<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * Migrations that were deployed but never run.
 *
 * Usually means a deployment stopped half-way, which is worth knowing before somebody spends an hour
 * debugging why a new field does not appear.
 */
final class PendingMigrations implements Rule
{
    public function key(): string
    {
        return 'pending_migrations';
    }

    public function category(): string
    {
        return RuleCategory::MAINTENANCE;
    }

    public function requiresCapability(): string
    {
        return 'system:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $pending = (int) $snapshot->inventoryValue('migrations.pending', 0);

        if ($pending === 0) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::MEDIUM,
            title: $pending === 1 ? 'A migration is pending' : "{$pending} migrations are pending",
            detail: 'Code has been deployed whose migrations have not run, so the database and the '
                .'application disagree about the schema. Run craft up.',
            evidence: ['pending' => $pending],
        );
    }
}
