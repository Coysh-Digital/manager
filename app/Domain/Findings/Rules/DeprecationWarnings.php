<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * Deprecated code still running, weighed against whether it is about to matter.
 *
 * A deprecation count on its own is close to meaningless as a finding. Every Craft site of any age
 * has some, none of them break anything today, and a rule firing on "you have three" would be amber
 * across a fleet forever while describing nothing anybody should act on this week.
 *
 * What makes it actionable is *timing*, and Manager already holds the other half. Deprecated code is
 * a bill that comes due at the next major upgrade and at no other moment - so the interesting site is
 * not the one with the most warnings, it is the one with warnings **and a breaking Craft release
 * waiting**. That site is about to have a bad afternoon, and it is the one worth naming now while
 * there is time to do something about it.
 *
 * So two ways in, and they say different things:
 *
 *  - **Medium** - there are warnings and the available Craft update is a breaking one. This is work
 *    with a deadline attached.
 *  - **Low** - there are a great many warnings and no upgrade pending. Maintenance debt worth
 *    knowing about, not worth interrupting anybody for.
 *
 * The threshold for the second is set where it is because it has to be somewhere and a low one is
 * worse than none: a rule that fires on a handful of warnings is a rule people mute, and muting it
 * costs them the first case too.
 */
final class DeprecationWarnings implements Rule
{
    /**
     * Warnings enough to be worth mentioning with no upgrade in sight.
     */
    private const BULK_THRESHOLD = 25;

    public function key(): string
    {
        return 'deprecation_warnings';
    }

    public function category(): string
    {
        return RuleCategory::MAINTENANCE;
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

        $count = $snapshot->runtimeValue('craft.deprecation_count');

        if (! is_int($count) || $count === 0) {
            return null;
        }

        // The other half of the question, and the reason this rule is worth having at all. Null when
        // the site has not been granted updates, or has not reported them - in which case this falls
        // back to the bulk threshold rather than guessing.
        $breaking = $snapshot->updates?->value('craft.latest_is_breaking') === true;

        if (! $breaking && $count < self::BULK_THRESHOLD) {
            return null;
        }

        if ($breaking) {
            return new RuleMatch(
                severity: Severity::MEDIUM,
                title: 'This site has deprecated code and a breaking Craft update waiting',
                detail: sprintf(
                    'Craft has recorded %d deprecation warning%s here, and the update available is a '
                    .'major one. Deprecated code is a bill that comes due at exactly that upgrade and '
                    .'at no other moment, so this is the window in which clearing it is cheap. The '
                    .'warnings themselves are in the site\'s own control panel, under Utilities - '
                    .'Manager holds the count and not the messages, because each one names a template '
                    .'and a line of somebody\'s code.',
                    $count,
                    $count === 1 ? '' : 's',
                ),
                evidence: ['deprecation_count' => $count, 'breaking_update_available' => true],
            );
        }

        return new RuleMatch(
            severity: Severity::LOW,
            title: $count.' deprecation warnings on this site',
            detail: 'Nothing is broken and nothing needs doing today - deprecated code keeps working '
                .'until the major release that removes it. Worth clearing while there is no deadline, '
                .'because the alternative is clearing it during an upgrade. The warnings are in the '
                .'site\'s own control panel under Utilities; Manager holds the count rather than the '
                .'messages, which name templates and lines of your code.',
            evidence: ['deprecation_count' => $count, 'breaking_update_available' => false],
        );
    }
}
