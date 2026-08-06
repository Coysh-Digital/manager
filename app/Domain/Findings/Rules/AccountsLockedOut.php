<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * Somebody cannot sign in to their own site.
 *
 * Separate from {@see RepeatedFailedLogins} because it is a different problem with a different
 * urgency. Failed attempts are usually an attack nobody needs to act on today; a locked account is
 * a colleague unable to work right now, and it is as likely to be a forgotten password as an
 * intrusion. Merging them would mean one finding whose severity depended on which half fired.
 */
final class AccountsLockedOut implements Rule
{
    public function key(): string
    {
        return 'accounts_locked_out';
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

        if ($report === null || $report->accounts_locked === 0) {
            return null;
        }

        return new RuleMatch(
            // Medium rather than high. Nobody's security is worse for a lockout - that is the
            // lockout working - but somebody is stuck, and a control panel that noticed and said
            // nothing is not much of a control panel.
            severity: Severity::MEDIUM,
            title: 'Accounts are locked out of the control panel',
            detail: sprintf(
                '%d %s currently locked out after repeated failed sign-ins%s. This is as often a '
                .'forgotten password as an attack: check with whoever is affected before assuming '
                .'either. Craft unlocks accounts on its own cooldown, or an administrator can unlock '
                .'them from the site.',
                $report->accounts_locked,
                $report->accounts_locked === 1 ? 'account is' : 'accounts are',
                $report->admin_accounts_affected > 0
                    ? sprintf(', %d of them an administrator', $report->admin_accounts_affected)
                    : '',
            ),
            evidence: [
                'accounts_locked' => $report->accounts_locked,
                'admin_accounts_affected' => $report->admin_accounts_affected,
                'window_hours' => $report->window_hours,
            ],
        );
    }
}
