<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class PhpEndOfLife implements Rule
{
    public function key(): string
    {
        return 'php_end_of_life';
    }

    public function requiresCapability(): string
    {
        return 'updates:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $php = $snapshot->updates?->payload['php'] ?? null;

        if ($php === null || ! (bool) ($php['end_of_life'] ?? false)) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::HIGH,
            title: 'PHP is past security support',
            detail: sprintf(
                'This site runs PHP %s, which no longer receives security fixes%s. Vulnerabilities '
                .'found in it will not be patched. Move to a supported branch.',
                (string) ($php['current'] ?? 'an unsupported version'),
                isset($php['security_support_until']) ? ' (support ended '.$php['security_support_until'].')' : '',
            ),
            evidence: ['php' => $php['current'] ?? null, 'end_of_life' => true],
        );
    }
}
