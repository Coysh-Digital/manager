<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * Somebody is working through the control panel's front door.
 *
 * The threshold is the whole design of this rule, and it is set where it is on purpose.
 *
 * Every Craft site on the public internet gets failed sign-ins. Bots find `/admin`, try `admin` with
 * a handful of passwords, and move on. A rule that fired on that would fire on every site in every
 * fleet forever, and a findings list where one entry is always present is a findings list people
 * stop reading - which costs them the entries that matter.
 *
 * So: fifty attempts in a day, or any attempt against an administrator account. The second condition
 * is the useful one. Bots spray; somebody who knows which of your accounts is an administrator has
 * done homework, and that is worth interrupting a person for at a much lower count.
 *
 * The number is a floor, not a total - Craft resets an account's counter on a successful sign-in, so
 * the one attacker who got in contributes nothing to it. The detail says so, because a rule that
 * quietly implies "under fifty means safe" would be worse than no rule.
 */
final class RepeatedFailedLogins implements Rule
{
    /**
     * Attempts in the reporting window before this is worth a person's attention.
     */
    private const THRESHOLD = 50;

    public function key(): string
    {
        return 'repeated_failed_logins';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): string
    {
        return 'logins:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentLogins()) {
            return null;
        }

        $report = $snapshot->logins;

        if ($report === null) {
            return null;
        }

        $targetsAdmin = $report->admin_accounts_affected > 0;
        $overThreshold = $report->failed_attempts >= self::THRESHOLD;

        if (! $targetsAdmin && ! $overThreshold) {
            return null;
        }

        return new RuleMatch(
            // High only when an administrator is being targeted. Volume alone is background noise on
            // the public internet; knowing which account to aim at is not.
            severity: $targetsAdmin ? Severity::HIGH : Severity::MEDIUM,
            title: $targetsAdmin
                ? 'An administrator account is being targeted'
                : 'Repeated failed sign-ins to the control panel',
            detail: sprintf(
                '%d failed sign-%s recorded across %d %s in the last %d hours%s. %s '
                .'Note this is a floor rather than a total: Craft clears an account\'s counter when '
                .'somebody signs in successfully, so an attempt that eventually worked leaves nothing '
                .'behind here.',
                $report->failed_attempts,
                $report->failed_attempts === 1 ? 'in was' : 'ins were',
                $report->accounts_with_failures,
                $report->accounts_with_failures === 1 ? 'account' : 'accounts',
                $report->window_hours,
                $targetsAdmin
                    ? sprintf(', including %d administrator %s',
                        $report->admin_accounts_affected,
                        $report->admin_accounts_affected === 1 ? 'account' : 'accounts')
                    : '',
                $targetsAdmin
                    ? 'Somebody aiming at an administrator has worked out which account to aim at, '
                      .'which is different from a bot spraying /admin. Consider requiring two-factor '
                      .'authentication on the site and restricting control-panel access by address.'
                    : 'Bots find every public control panel eventually, so some of this is background. '
                      .'Worth a look if the volume is new.',
            ),
            evidence: [
                'failed_attempts' => $report->failed_attempts,
                'accounts_with_failures' => $report->accounts_with_failures,
                'admin_accounts_affected' => $report->admin_accounts_affected,
                'window_hours' => $report->window_hours,
                'threshold' => self::THRESHOLD,
            ],
        );
    }
}
