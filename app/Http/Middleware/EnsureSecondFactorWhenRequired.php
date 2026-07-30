<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces an organisation's second-factor requirement.
 *
 * Redirects to the account page rather than refusing. Locking somebody out of a control plane to
 * improve its security is a trade made once and regretted at 2am — and it would mean turning the
 * requirement on could strand every member at once, including the person who turned it on.
 *
 * So: they can reach their account page to enrol, and sign out. Nothing else.
 */
final class EnsureSecondFactorWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! app()->bound(Organisation::class)) {
            return $next($request);
        }

        if (! app(Organisation::class)->mfa_required || $user->hasSecondFactor()) {
            return $next($request);
        }

        // The escape hatches. Without these the enforcement would have nowhere to send anybody.
        $permitted = [
            'account.show',
            'account.totp.start',
            'account.totp.confirm',
            'account.recovery-codes',
            'passkeys.options',
            'passkeys.store',
            'password.confirm',
            'password.confirm.store',
            'logout',
        ];

        if (in_array($request->route()?->getName(), $permitted, true)) {
            return $next($request);
        }

        return redirect()
            ->route('account.show')
            ->with('warning', 'This organisation requires two-factor authentication. Set it up to continue.');
    }
}
