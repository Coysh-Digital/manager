<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A licence that is not in good standing.
 *
 * Reads state the connector computed locally. Licence keys themselves are never transmitted, so this
 * rule knows that a licence is invalid without knowing which licence it is.
 */
final class InvalidLicence implements Rule
{
    public function key(): string
    {
        return 'licence_not_valid';
    }

    public function category(): string
    {
        return RuleCategory::MAINTENANCE;
    }

    public function requiresCapability(): string
    {
        return 'licences:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $state = (string) $snapshot->inventoryValue('licence.craft', 'unknown');

        // "unknown" is not a finding. It means the connector could not determine the state, and
        // reporting that as a licence problem would be inventing one.
        if (! in_array($state, ['invalid', 'mismatched', 'trial'], true)) {
            return null;
        }

        // A trial in production is a billing problem waiting to become an outage; an invalid licence
        // already is one.
        $isTrial = $state === 'trial';

        if ($isTrial && ! $snapshot->isProduction()) {
            return null;
        }

        return new RuleMatch(
            severity: $isTrial ? Severity::MEDIUM : Severity::HIGH,
            title: match ($state) {
                'invalid' => 'The Craft licence is not valid',
                'mismatched' => 'The Craft licence belongs to another domain',
                default => 'Craft is running on a trial licence in production',
            },
            detail: match ($state) {
                'invalid' => 'Craft reports its licence as invalid. The control panel will start '
                    .'showing licence warnings to anyone who logs in. Resolve it in Craft Console.',
                'mismatched' => 'The installed licence is registered to a different domain, which '
                    .'usually means a production licence is in use on a copy, or a site has moved. '
                    .'Reassign it in Craft Console.',
                default => 'A trial licence in production will eventually start showing warnings in '
                    .'the control panel. Buy or assign a licence.',
            },
            evidence: ['craft_licence' => $state],
        );
    }
}
