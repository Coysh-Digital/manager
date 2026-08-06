<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * What kind of problem a rule reports.
 *
 * Three categories, and the split is about who acts and how quickly rather than about how the rule is
 * implemented. Security is somebody's exposure; maintenance is work that has to happen eventually;
 * operational is the machine complaining about itself.
 *
 * A finding stores no category. It stores the rule key, and the rule declares the category - so the
 * categorisation can be corrected without a migration, and a rule cannot end up in two places. That
 * matters more than it sounds: `key()` is a stable identifier that acknowledgements are keyed on and
 * is never renamed, so a category column would be a second copy of something already derivable, kept
 * in step by hand.
 *
 * A class of constants rather than an enum, matching {@see Severity} - the values are compared
 * against strings that arrive from query strings and go into `whereIn`, and an enum would be
 * unwrapped at every one of those boundaries.
 */
final class RuleCategory
{
    /** An exposure. Somebody outside could act on this, or already has. */
    public const SECURITY = 'security';

    /** Work that has to happen, but is not on its own an exposure. */
    public const MAINTENANCE = 'maintenance';

    /** The installation reporting on itself: disk, queues, response times, silence. */
    public const OPERATIONAL = 'operational';

    /**
     * Rule keys per category, built once per process.
     *
     * The rule list is fixed at boot - it is a hard-coded array in {@see FindingsEvaluator::rules()} —
     * so this cannot go stale within a request, and the sidebar composer asks for it on every page.
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $keys = null;

    /**
     * Every category, in the order screens should present them.
     *
     * @return list<string>
     */
    public static function ordered(): array
    {
        return [self::SECURITY, self::MAINTENANCE, self::OPERATIONAL];
    }

    public static function isKnown(string $category): bool
    {
        return in_array($category, self::ordered(), true);
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::SECURITY => 'Security',
            self::MAINTENANCE => 'Maintenance',
            self::OPERATIONAL => 'Operational',
            default => $category,
        };
    }

    /**
     * The rule keys in one category.
     *
     * Used to query findings - `whereIn('rule', RuleCategory::keysFor(RuleCategory::SECURITY))` — so
     * that a screen filters on what the rules say about themselves rather than on a list maintained
     * beside them.
     *
     * Security asks for this list; Findings asks for `whereNotIn` the same list, rather than for the
     * union of the other two categories. The difference only shows up for a rule key that is in no
     * category at all - a rule deleted from the evaluator while its findings are still open, which
     * reconciliation cannot resolve because it no longer runs. Under `whereNotIn` those rows stay
     * visible on Findings; under a positive list of the other categories they would be in the
     * database, still open, and on no screen.
     *
     * @return list<string>
     */
    public static function keysFor(string $category): array
    {
        return self::keys()[$category] ?? [];
    }

    /**
     * Every rule key, in no particular order.
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        return array_values(array_merge(...array_values(self::keys())));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function keys(): array
    {
        if (self::$keys !== null) {
            return self::$keys;
        }

        $keys = [];

        foreach (app(FindingsEvaluator::class)->rules() as $rule) {
            $keys[$rule->category()][] = $rule->key();
        }

        return self::$keys = $keys;
    }
}
