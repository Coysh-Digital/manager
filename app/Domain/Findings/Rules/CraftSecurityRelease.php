<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A published Craft security release the site has not taken.
 *
 * The only critical rule in the set. Everything else here weakens a posture; this one means a fix
 * exists for a known problem and the site does not have it.
 *
 * The detail names versions and never what the release fixes. That description is not transmitted,
 * for the same reason it is not stored: an advisory body attached to a named unpatched site is a
 * liability.
 */
final class CraftSecurityRelease implements Rule
{
    public function key(): string
    {
        return 'craft_security_release';
    }

    public function requiresCapability(): string
    {
        return 'updates:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $report = $snapshot->updates;

        if ($report === null || ! $report->craft_security_release) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::CRITICAL,
            title: 'Craft has an outstanding security release',
            detail: sprintf(
                'This site runs Craft %s and %s is available, with at least one release in between '
                .'flagged as critical by Craft. Apply it. Consult the Craft changelog for what it '
                .'fixes - Manager does not retrieve release notes.',
                $report->craft_current ?? 'an unknown version',
                $report->craft_latest ?? 'a newer version',
            ),
            evidence: [
                'current' => $report->craft_current,
                'latest' => $report->craft_latest,
                'security_release_available' => true,
            ],
        );
    }
}
