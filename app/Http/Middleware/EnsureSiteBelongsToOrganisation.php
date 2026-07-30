<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organisation;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a site belonging to another organisation.
 *
 * Route binding resolves a site on its external identifier alone, so this is the whole of what keeps
 * one tenant from reading another's site by pasting in a ULID. It was a private method on
 * SiteController, called by hand at the top of every action; with a site now having six screens and
 * a dozen actions across four controllers, "called by hand" was one forgotten line away from being a
 * cross-tenant read.
 *
 * A 404 rather than a 403: telling somebody that a site exists but is not theirs is telling them
 * something about another organisation.
 */
final class EnsureSiteBelongsToOrganisation
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');

        // Every route this is attached to has a {site} parameter. If one ever does not, that is a
        // routing mistake rather than a request to wave through.
        abort_if(! $site instanceof Site, 500, 'This route has no site to scope.');

        abort_if($site->organisation_id !== app(Organisation::class)->id, 404);

        return $next($request);
    }
}
