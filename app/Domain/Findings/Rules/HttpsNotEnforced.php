<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class HttpsNotEnforced implements Rule
{
    public function key(): string
    {
        return 'https_not_enforced';
    }

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
        if ($snapshot->flag('https_enforced') !== false) {
            return null;
        }

        return new RuleMatch(
            // Lower outside production: an unencrypted staging site is untidy rather than dangerous.
            severity: $snapshot->isProduction() ? Severity::HIGH : Severity::LOW,
            title: 'HTTPS is not enforced',
            detail: "The site's primary URL is not HTTPS, so control-panel sessions and form "
                .'submissions can cross the network in the clear. Terminate TLS and set the site URL '
                .'to https.',
            evidence: ['https_enforced' => false],
        );
    }
}
