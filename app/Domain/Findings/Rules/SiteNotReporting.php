<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A site that has stopped checking in.
 *
 * The one rule that needs no capability, because it is derived from the platform's own records rather
 * than from anything the site said. It is also the rule that matters most: every other finding here
 * depends on reports, so a site that has gone quiet has silently stopped being monitored, and would
 * otherwise sit on the screen looking fine.
 */
final class SiteNotReporting implements Rule
{
    /**
     * Long enough not to fire on a missed cron run or a brief outage, short enough that a genuinely
     * broken connector is noticed the same working day.
     */
    private const SILENT_HOURS = 6;

    public function key(): string
    {
        return 'site_not_reporting';
    }

    public function requiresCapability(): ?string
    {
        return null;
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $site = $snapshot->site;

        // A site that has never connected is not "not reporting" — it is not set up. Saying so
        // properly is the Sites screen's job, and duplicating it here would be noise.
        if ($site->last_seen_at === null) {
            return null;
        }

        if ($site->last_seen_at->diffInHours(now()) < self::SILENT_HOURS) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::HIGH,
            title: 'This site has stopped reporting',
            detail: sprintf(
                'Last contact was %s. Every other check depends on reports, so this site is '
                .'effectively unmonitored. Check that cron is running the connector, and that the '
                .'site can reach the platform.',
                $site->last_seen_at->diffForHumans(),
            ),
            evidence: ['last_seen_at' => $site->last_seen_at->toIso8601String()],
        );
    }
}
