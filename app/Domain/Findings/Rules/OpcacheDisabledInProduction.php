<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * PHP is recompiling every file on every request.
 *
 * Low severity, and it stays low deliberately. Nothing is unsafe, nothing is going to break, and a
 * site with opcache off has been working fine for however long it has been off. It is simply paying
 * several times over for work it could do once — which is worth telling somebody about the next time
 * they are in the server, and is not worth an amber badge on a fleet screen.
 *
 * Production only. A development container without opcache is a development container behaving
 * exactly as intended, and a rule that fired on it would be noise on every local site in the fleet.
 */
final class OpcacheDisabledInProduction implements Rule
{
    public function key(): string
    {
        return 'opcache_disabled_in_production';
    }

    public function requiresCapability(): string
    {
        return 'runtime:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->isProduction() || ! $snapshot->hasRecentRuntime()) {
            return null;
        }

        $enabled = $snapshot->runtimeValue('php.opcache_enabled');

        // Null means the connector could not read it — an older connector, or a PHP build without
        // the extension compiled in at all. Not reported as disabled: "we could not tell" and "it is
        // off" are different answers.
        if ($enabled === null || $enabled === true) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::LOW,
            title: 'PHP opcache is off in production',
            detail: 'Every request recompiles every PHP file this site touches, which is several '
                .'times the work it needs to do and shows up as slower pages under load. Nothing is '
                .'unsafe and nothing will break — this is worth fixing the next time somebody is in '
                .'the server configuration, not tonight.',
            evidence: ['opcache_enabled' => false, 'environment' => $snapshot->site->environment],
        );
    }
}
