<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The recent-authentication gate.
 *
 * Reached when someone attempts a sensitive action without having proved their password lately.
 * The point is not to authenticate — they already are — but to establish that the person at the
 * keyboard is still the account holder, and not somebody who found an unlocked machine.
 */
final class ConfirmPasswordController
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);

        // Rate limited like any other password check. An attacker at an unlocked machine gets no
        // more attempts here than they would at the sign-in screen.
        $key = 'password-confirm:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('manager.auth.max_login_attempts'))) {
            throw ValidationException::withMessages([
                'password' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        if (! Hash::check($validated['password'], $request->user()->password)) {
            RateLimiter::hit($key, (int) config('manager.auth.login_decay_seconds'));

            throw ValidationException::withMessages(['password' => __('That password is not correct.')]);
        }

        RateLimiter::clear($key);

        $request->session()->put('auth.password_confirmed_at', now()->timestamp);
        $request->user()->forceFill(['last_authenticated_at' => now()])->save();

        return redirect()->intended(route('sites.index'));
    }
}
