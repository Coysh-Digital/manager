<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A Content-Security-Policy that was written, deployed, and enforces nothing.
 *
 * **This fires on report-only, and not on absent**, which is the whole design of the rule and is
 * worth defending because the obvious version is the other way round.
 *
 * A site with no CSP is an ordinary site. A useful policy is genuinely hard to write, it breaks
 * things when it is wrong, and a large share of well-run sites do not have one - so "you have no
 * CSP" would fire across most of a fleet to tell somebody something they already know and have
 * already decided about. The screen shows it; the findings list does not.
 *
 * A site with a **report-only** policy is a different situation entirely, and a much more specific
 * one. Somebody wrote a policy. They deployed it. They meant to come back and enforce it, and then
 * a sprint ended. From that day the site has looked protected to anybody glancing at its headers —
 * including to whoever wrote the policy - while a browser has been ignoring every violation it
 * describes. The gap between what the configuration says and what the browser does is the finding,
 * and nobody notices it from inside because nothing is broken.
 *
 * Low, because nothing got worse - the site is exactly as safe as it was before the policy was
 * written. What changed is that somebody now believes otherwise.
 *
 * A site running both an enforced policy and a report-only one is doing the correct thing: that is
 * what testing a stricter policy looks like, and it says nothing.
 */
final class ContentSecurityPolicyNotEnforced implements Rule
{
    public function key(): string
    {
        return 'csp_not_enforced';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): ?string
    {
        return null;
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentProbe()) {
            return null;
        }

        $reportOnly = $snapshot->servedHeader('content-security-policy-report-only');

        if ($reportOnly === null) {
            return null;
        }

        // Both present is a site testing a stricter policy behind the one it enforces, which is the
        // correct way to tighten a CSP and not something to interrupt.
        if ($snapshot->servedHeader('content-security-policy') !== null) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::LOW,
            title: 'This site\'s Content-Security-Policy is not being enforced',
            detail: 'The policy is served as Content-Security-Policy-Report-Only, so browsers '
                .'report violations and block nothing. That is the right way to start, and it is '
                .'usually the state a site is left in when somebody deploys a policy meaning to '
                .'enforce it later. Until the header is renamed to Content-Security-Policy the site '
                .'is exactly as exposed as it was before the policy was written - the difference is '
                .'that its headers now suggest otherwise.',
            evidence: ['report_only' => $reportOnly],
        );
    }
}
