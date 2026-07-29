<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes which organisation the current request is acting within.
 *
 * Resolved from live membership on every request, never from the session. Membership revocation
 * therefore takes effect on the next request rather than whenever a session happens to expire,
 * which is what "immediate removal of access" has to mean to be worth claiming.
 *
 * A user with no live membership gets a 403 rather than an empty dashboard: there is nothing they
 * are entitled to see, and an empty screen invites a support ticket where a refusal does not.
 */
final class ResolveOrganisation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        /** @var Membership|null $membership */
        $membership = $user->memberships()
            ->whereNull('revoked_at')
            ->with('organisation')
            ->orderBy('id')
            ->first();

        abort_if($membership === null, 403, 'Your access to this installation has been revoked.');

        /** @var Organisation $organisation */
        $organisation = $membership->organisation;

        // Bound rather than passed around, so nothing has to remember to scope a query by hand.
        app()->instance(Organisation::class, $organisation);
        app()->instance(Membership::class, $membership);

        $request->attributes->set('manager.organisation', $organisation);
        $request->attributes->set('manager.membership', $membership);

        view()->share('currentOrganisation', $organisation);
        view()->share('currentMembership', $membership);

        return $next($request);
    }
}
