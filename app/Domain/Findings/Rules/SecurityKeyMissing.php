<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A Craft install with no security key configured.
 *
 * Rare, and critical when it happens, which is the shape of finding worth having. Craft derives
 * every encrypted value, every CSRF token, every "remember me" cookie and every password reset link
 * from `securityKey`. Without one, all of them are built on an empty string: session tokens become
 * forgeable, and anything already encrypted with a key that later appears becomes unreadable.
 *
 * It is usually a deploy that never got its environment file rather than somebody's decision — which
 * is exactly why nobody notices. The site runs. Most of it works. The failure is not visible from any
 * screen until somebody exploits it or until a value that was encrypted stops decrypting.
 *
 * Only `false` fires. A connector too old to report it sends nothing, and a rule that read that
 * silence as an empty key would open a critical finding against every site in a fleet running the
 * previous plugin release.
 */
final class SecurityKeyMissing implements Rule
{
    public function key(): string
    {
        return 'security_key_missing';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): string
    {
        return 'runtime:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentRuntime()) {
            return null;
        }

        if ($snapshot->runtimeValue('craft.security_key_set') !== false) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::CRITICAL,
            title: 'This site has no security key set',
            detail: 'Craft derives every encrypted value, every CSRF token, every "remember me" '
                .'cookie and every password reset link from its security key. Without one they are '
                .'all built on an empty string, which makes session tokens forgeable. The site keeps '
                .'working, which is why this is not visible from any screen. Set CRAFT_SECURITY_KEY '
                .'in the environment - and be aware that anything already encrypted under a '
                .'different key will stop decrypting once one is set, so this is worth doing '
                .'deliberately rather than quickly.',
            evidence: ['security_key_set' => false],
        );
    }
}
