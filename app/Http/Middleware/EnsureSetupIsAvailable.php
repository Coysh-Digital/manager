<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the first-run setup route once an owner exists.
 *
 * The specification asks for setup to be disabled after completion. It returns 404 rather than a
 * redirect: the route stops existing, so there is nothing to probe for and nothing that could be
 * re-entered by anyone who finds the URL in a deployment guide.
 *
 * Keyed on "does any account exist" rather than on a flag in configuration, because a flag can be
 * reset by whoever can edit the environment file, and the account cannot be un-created.
 */
final class EnsureSetupIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(User::query()->exists(), 404);

        return $next($request);
    }
}
