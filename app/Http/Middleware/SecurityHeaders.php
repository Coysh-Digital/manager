<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers a browser needs to be told, on every response this application sends.
 *
 * Three of these were already in `deploy/docker/nginx.conf`, whose comment beside them read "the
 * application sets its own headers too". It did not. That was true of nothing, and it mattered most
 * where it was least visible: the Cloud console deploys through Ploi and never uses that file, so
 * console.managerforcraft.com — the paid, hosted, multi-tenant edition — served none of them.
 *
 * Set here rather than in a vhost because this is the only place that covers every way the
 * application is served. The nginx file stays as it is: it also covers static assets, which never
 * reach PHP, and defence in depth is the point of having both.
 *
 * Deliberately not a Content-Security-Policy. A useful CSP for this application is not a one-line
 * addition - there is inline Blade and a passkey flow to account for - and a wrong directive on a
 * live console fails as a blank screen rather than as a warning. It is worth doing properly and
 * separately; recorded as an outstanding gap rather than quietly treated as done here.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // DENY rather than SAMEORIGIN. Nothing in this application frames itself, so the stricter
        // value costs nothing and removes the question of which origin counts as "same" behind a
        // proxy.
        $response->headers->set('X-Frame-Options', 'DENY', false);

        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        // Origin only when leaving the site, full path within it. A backup download URL or an
        // enrolment link carries an identifier in its path, and neither should reach a third party
        // through a Referer header.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);

        /*
         | HSTS, and only when the canonical URL is already HTTPS.
         |
         | Keyed on APP_URL for the same reason the session cookie's secure flag is: it is the one
         | statement of how this installation is actually reached, it is mandatory, and it does not
         | depend on a proxy setting a header correctly.
         |
         | Sending it on an installation served over HTTP would be a promise the operator has not
         | made and cannot keep. Sending it *once* over HTTPS commits every browser that saw it for
         | the lifetime of the max-age, which is why the duration is configurable and why preload is
         | not offered here - that one is a submission to a browser vendor's list and is not
         | something an application should arrange on an operator's behalf.
        */
        $seconds = (int) config('manager.security.hsts_seconds');

        if ($seconds > 0 && str_starts_with((string) config('app.url'), 'https://')) {
            $response->headers->set(
                'Strict-Transport-Security',
                "max-age={$seconds}; includeSubDomains",
                false,
            );
        }

        return $response;
    }
}
