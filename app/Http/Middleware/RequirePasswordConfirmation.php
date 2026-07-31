<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ResumableInput;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;

/**
 * The recent-authentication gate, with the typed form kept.
 *
 * Identical to Laravel's `RequirePassword` in what it lets through and what it refuses. The only
 * addition is one call to {@see ResumableInput::capture()} before the redirect, because the
 * framework cannot preserve a POST across the confirm-password screen: `Redirector::guest()` writes
 * `previous()` into `url.intended` for anything that is not a GET, and nothing flashes the body. The
 * argument for restoring rather than replaying is in ResumableInput's docblock.
 *
 * Two things here are load-bearing.
 *
 * **The constructor reads the configured timeout itself.** `AuthServiceProvider::registerRequirePassword()`
 * binds `RequirePassword::class` *by exact class name*, passing `config('auth.password_timeout')`. A
 * subclass does not resolve through that binding — it is autowired instead, arriving with
 * `$passwordTimeout = null`, and the parent constructor then falls back to a hard-coded 10800
 * seconds. Swapping the alias without this constructor would silently turn
 * `MANAGER_RECENT_AUTH_MINUTES=15` into three hours: a weakened security control, with no test
 * failing and nothing on any screen to see. `it('honours the configured recent-authentication
 * window')` exists to make that impossible to reintroduce.
 *
 * **The JSON branch is delegated to the parent untouched.** An API caller gets 423 and no redirect,
 * and no input is captured — there is no form and no session worth the name. Duplicating that
 * branch here would be a second place for it to drift.
 */
final class RequirePasswordConfirmation extends RequirePassword
{
    public function __construct(ResponseFactory $responseFactory, UrlGenerator $urlGenerator)
    {
        parent::__construct($responseFactory, $urlGenerator, (int) config('auth.password_timeout'));
    }

    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     * @return mixed
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if (! $this->shouldConfirmPassword($request, $passwordTimeoutSeconds) || $request->expectsJson()) {
            return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
        }

        ResumableInput::capture($request);

        return $this->responseFactory->redirectGuest(
            $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
        );
    }
}
