<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A site that answers on port 80 and does not send anybody to HTTPS.
 *
 * Sits beside {@see HttpsNotEnforced} rather than replacing it, and the distinction between them is
 * the reason this whole check exists:
 *
 *   HttpsNotEnforced      what the site believes. Craft's own `baseUrl` does not start with
 *                         `https://`, reported by the connector.
 *   HttpsRedirectMissing  what a visitor gets. Somebody typed the domain without a scheme, their
 *                         browser tried port 80, and the site answered in the clear.
 *
 * A site can fail either without failing the other. A correct `baseUrl` and a web server with no
 * redirect looks perfect from inside Craft and serves the site over plain HTTP all day; a site
 * behind a load balancer that redirects everything can have a `baseUrl` nobody updated and be
 * completely fine in practice. Only one of the two is about what happens to people.
 *
 * Null is not a finding. A host that does not listen on port 80 at all has nothing to redirect and
 * is in the best state available - reporting it as "does not redirect" would flag the absence of a
 * problem.
 */
final class HttpsRedirectMissing implements Rule
{
    public function key(): string
    {
        return 'https_redirect_missing';
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

        if ($snapshot->probe?->redirects_to_https !== false) {
            return null;
        }

        return new RuleMatch(
            // High in production, and it is one of the few rules where the environment changes the
            // rank rather than whether it fires at all: a staging site served over plain HTTP is
            // still a control panel whose session cookie crosses the network in the clear, which is
            // worth saying quietly rather than not saying.
            severity: $snapshot->isProduction() ? Severity::HIGH : Severity::LOW,
            title: 'This site answers over plain HTTP without redirecting',
            detail: sprintf(
                'A request to http://%s was answered rather than redirected to HTTPS. Anybody who '
                .'types the domain without a scheme - which is everybody - gets the site over an '
                .'unencrypted connection, and so does anything following an old link. Any session '
                .'cookie set on that page travels in the clear, and on the same network as the '
                .'visitor it can be read and reused. This is set in the web server or the CDN rather '
                .'than in Craft, and Manager checks it from outside because the site cannot see '
                .'what happens on a port its application never receives.',
                $snapshot->site->expected_domain,
            ),
            evidence: [
                'redirects_to_https' => false,
                'environment' => $snapshot->site->environment,
            ],
        );
    }
}
