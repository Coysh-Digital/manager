<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Support\ResumableInput;
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
        /*
         | Hold on to whatever the gate captured for one more request.
         |
         | Flash data lives for exactly one request, and this screen is the one it lands on — so
         | without this it is gone by the time the password is submitted, and every restored form
         | this application claims to give back was in fact being dropped. The tests missed it by
         | going straight from the refused POST to the confirming POST, which is a sequence no
         | browser performs.
        */
        ResumableInput::keep();

        return view('auth.confirm-password', [
            // What was interrupted, so the screen can say it. Read without consuming: the screen
            // that needs this next is the one after.
            'interrupted' => ResumableInput::pending()['label'] ?? null,
        ]);
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

        /*
         | Hand back whatever the gate interrupted.
         |
         | The captured return URL is preferred over `url.intended`, which for a POST holds the
         | Referer rather than the action — and a Referrer-Policy is entitled to remove that
         | header entirely, at which point `intended()` falls through to the fleet screen no matter
         | where the person actually was.
         |
         | `withInput()` populates `_old_input`, so every `old()` call already written into the
         | forms picks the values up with no change to the fields themselves. Nothing is replayed:
         | see ResumableInput for why not.
        */
        $resumed = ResumableInput::resume();

        if ($resumed === null) {
            return redirect()->intended(route('sites.index'));
        }

        // Deliberately not `intended()`. For a POST, `url.intended` holds whatever the Referer said
        // — the fleet screen, or nothing at all if a Referrer-Policy stripped it — and it would
        // therefore win over the address resolved from the route that was actually interrupted.
        $request->session()->forget('url.intended');

        return redirect()
            ->to($resumed['url'])
            ->withInput($resumed['input'])
            ->with('manager.resumed_form', $resumed['route'])

            // Said out loud on arrival. A form that quietly refilled itself still leaves somebody
            // wondering whether the thing they pressed happened, and the answer — it did not, press
            // it again — is the one fact this flow owes them.
            ->with('status', 'Confirmed. What you had typed is still here, and nothing was done yet — press the button again to '.$resumed['label'].'.');
    }
}
