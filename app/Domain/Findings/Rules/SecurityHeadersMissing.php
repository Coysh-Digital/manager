<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A site serving none of the hardening headers a browser will act on.
 *
 * One rule rather than one per header, deliberately. A site missing these is almost never missing
 * exactly one of them - the cause is a server that was never configured for it rather than an
 * oversight per header - so three findings would be three rows describing one afternoon's work. The
 * detail names which are absent, which is the part somebody acts on.
 *
 * No capability. Like the certificate rules, this comes from the platform's own observation, and
 * asking a site's permission to read the headers it serves to every visitor would be asking
 * permission to look at a public web page.
 *
 * ## Which headers are in the finding, and which are only on the screen
 *
 * Three fire. Everything else the probe records is shown on the site's Security tab and stays out of
 * the findings list, because a rule that fires on most of a fleet is a rule that trains people to
 * stop reading the list.
 *
 *  - **Strict-Transport-Security.** The site already answered over HTTPS - the probe would not have
 *    got a response otherwise - so this is a site that supports TLS and does not insist on it.
 *  - **X-Content-Type-Options.** One header, one value, no configuration to think about, and it
 *    stops a browser deciding for itself that an uploaded file is JavaScript.
 *  - **X-Frame-Options**, *or* a CSP that sets `frame-ancestors`. The second half matters: a modern
 *    site using `frame-ancestors` and no `X-Frame-Options` is correctly configured, and a rule that
 *    did not know that would flag the sites that got it most right.
 *
 * `Referrer-Policy` is not in the finding. Every current browser defaults to
 * `strict-origin-when-cross-origin` on its own, so its absence is no longer the leak it was, and it
 * would fire on a large share of any fleet to say very little. `Permissions-Policy` is not in it
 * either, for the same reason with less ambiguity: almost nobody sets it, and almost nobody needs
 * to. Both are on the screen, where somebody can act on them without being told to.
 */
final class SecurityHeadersMissing implements Rule
{
    public function key(): string
    {
        return 'security_headers_missing';
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

        $missing = [];

        if ($snapshot->servedHeader('strict-transport-security') === null) {
            $missing['Strict-Transport-Security'] = 'a visitor arriving over plain HTTP is not '
                .'forced onto HTTPS by their own browser';
        }

        if ($snapshot->servedHeader('x-content-type-options') === null) {
            $missing['X-Content-Type-Options'] = 'a browser may decide for itself that an uploaded '
                .'file is a script';
        }

        if (! $this->framingIsControlled($snapshot)) {
            $missing['X-Frame-Options'] = 'the site can be loaded in a frame on somebody else\'s '
                .'page, which is how a click gets stolen';
        }

        if ($missing === []) {
            return null;
        }

        return new RuleMatch(
            // Medium only when there is nothing at all. One header missing from an otherwise
            // hardened site is worth telling somebody about the next time they are in the server
            // configuration; three missing is a server nobody has ever configured for this, and the
            // two want different places in a list.
            severity: count($missing) === 3 ? Severity::MEDIUM : Severity::LOW,
            title: count($missing) === 1
                ? 'This site is not sending '.array_key_first($missing)
                : 'This site is missing '.count($missing).' security headers',
            detail: $this->detail($missing),
            evidence: ['missing' => array_keys($missing)],
        );
    }

    /**
     * Whether anything stops this site being framed.
     *
     * `X-Frame-Options` or a CSP naming `frame-ancestors`, and either is enough. The CSP directive
     * supersedes the header and browsers prefer it where both are present, so a site that has moved
     * to CSP alone is ahead rather than behind - flagging it would be telling the best-configured
     * sites in a fleet to go backwards.
     */
    private function framingIsControlled(Snapshot $snapshot): bool
    {
        if ($snapshot->servedHeader('x-frame-options') !== null) {
            return true;
        }

        foreach (['content-security-policy', 'content-security-policy-report-only'] as $header) {
            $policy = $snapshot->servedHeader($header);

            // Report-only counts for nothing on its own - it enforces nothing by definition - but a
            // site with frame-ancestors in a report-only policy is a site mid-rollout, and this rule
            // is not the place to hurry them. ContentSecurityPolicyNotEnforced says that instead.
            if ($header === 'content-security-policy'
                && is_string($policy)
                && str_contains(strtolower($policy), 'frame-ancestors')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $missing
     */
    private function detail(array $missing): string
    {
        $lines = [];

        foreach ($missing as $header => $consequence) {
            $lines[] = $header.' - '.$consequence.'.';
        }

        return implode(' ', $lines)
            .' These are set by whatever serves the site rather than by Craft, so the fix is in the '
            .'web server, the CDN, or wherever else the response passes through on its way out. '
            .'Manager checks them from outside for that reason: a header the origin sets and the '
            .'edge removes looks correct from the site itself.';
    }
}
