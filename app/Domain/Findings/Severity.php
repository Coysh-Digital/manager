<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * How urgent a finding is.
 *
 * Four levels, not five, and each has a stated meaning so that assigning one is a decision rather
 * than a feeling. A scale where everything drifts to "high" tells nobody anything.
 */
final class Severity
{
    /** Exploitable now, or a known vulnerability with a patch available. Interrupt somebody. */
    public const CRITICAL = 'critical';

    /** Meaningfully weakens the site's security posture. Should be dealt with this week. */
    public const HIGH = 'high';

    /** Worth fixing, but not on its own an exposure. Next time somebody is in there. */
    public const MEDIUM = 'medium';

    /** Housekeeping. Reported so it does not accumulate unseen. */
    public const LOW = 'low';

    /**
     * Worst first.
     *
     * @return list<string>
     */
    public static function ordered(): array
    {
        return [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];
    }

    public static function rank(string $severity): int
    {
        $position = array_search($severity, self::ordered(), true);

        return $position === false ? count(self::ordered()) : $position;
    }

    /**
     * Whichever of two severities is worse.
     */
    public static function worst(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return self::rank($a) <= self::rank($b) ? $a : $b;
    }

    /**
     * The tone a badge should use.
     */
    public static function tone(string $severity): string
    {
        return match ($severity) {
            self::CRITICAL, self::HIGH => 'bad',
            self::MEDIUM => 'warn',
            default => 'info',
        };
    }
}
