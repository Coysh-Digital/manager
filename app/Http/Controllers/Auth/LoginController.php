<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password login, and the handover to the second factor.
 *
 * A user with a confirmed second factor is not logged in here. Their identifier is parked in the
 * session and they are sent to the challenge; the session is only upgraded to an authenticated one
 * once the second factor is satisfied. Logging them in first and "requiring" the challenge
 * afterwards would leave a window in which a valid session existed on one factor alone.
 */
final class LoginController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $this->assertNotThrottled($request, $credentials['email']);

        $user = User::query()->where('email', $credentials['email'])->first();

        // Hashed even when the user does not exist, so the response time does not answer "is this
        // an account here?" for anyone working through a list of addresses.
        $passwordMatches = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$'.str_repeat('x', 53));

        if ($user === null || ! $passwordMatches) {
            RateLimiter::hit($this->throttleKey($request, $credentials['email']), (int) config('manager.auth.login_decay_seconds'));

            $this->audit->record(
                action: 'auth.login.failed',
                actorType: AuditEvent::ACTOR_SYSTEM,
                // The address is recorded because an operator investigating a burst of failures
                // needs it. The password never is, not even hashed.
                actorLabel: $credentials['email'],
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'invalid credentials',
            );

            throw ValidationException::withMessages([
                'email' => __('Those credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        if ($user->hasSecondFactor()) {
            // Not logged in yet. Only the intent to log in is remembered.
            $request->session()->put('auth.challenge', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'at' => now()->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    /**
     * Finish a login once every required factor is satisfied.
     */
    public static function completeLogin(Request $request, User $user, bool $remember, string $factor = 'password'): RedirectResponse
    {
        self::issueSession($request, $user, $remember, $factor);

        return redirect()->intended(route('sites.index'));
    }

    /**
     * Issue the session, once and in one place.
     *
     * Separate from completeLogin only because the passkey path answers a fetch() with JSON rather
     * than a redirect. Everything that decides whether the login is *safe* happens here, so the two
     * paths cannot drift apart on it - session regeneration above all, since a passkey path that
     * forgot to regenerate would leave a session fixed before login perfectly usable after it.
     */
    public static function issueSession(Request $request, User $user, bool $remember, string $factor): void
    {
        Auth::login($user, $remember);

        // A fresh identifier on privilege change, so a session fixed before login is worthless.
        $request->session()->regenerate();

        self::finaliseSession($request, $user, $factor);
    }

    /**
     * The bookkeeping every successful login shares, whichever factor closed it.
     */
    private static function finaliseSession(Request $request, User $user, string $factor): void
    {
        $request->session()->forget('auth.challenge');

        // Both the recent-authentication gate and the account's own record of when it last proved
        // who it was.
        $request->session()->put('auth.password_confirmed_at', now()->timestamp);
        $user->forceFill(['last_authenticated_at' => now()])->save();

        app(AuditRecorder::class)->record(
            action: 'auth.login.succeeded',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            // Which factor closed the login. Worth recording: "signed in with a passkey" and "signed
            // in with a recovery code" call for very different responses when reviewing an incident.
            after: ['factor' => $factor],
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            $this->audit->record(
                action: 'auth.logout',
                actor: $user,
                targetType: 'user',
                targetId: $user->external_id,
            );
        }

        return redirect()->route('login');
    }

    private function assertNotThrottled(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);
        $max = (int) config('manager.auth.max_login_attempts');

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ]),
        ]);
    }

    /**
     * Keyed on address and source together.
     *
     * On the address alone, anyone could lock a known user out by failing on purpose. On the
     * address only within one source, a distributed attempt would slip past. Both is the compromise
     * that fails in the safer direction for the account whose password is being guessed.
     */
    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.Str::lower($email).'|'.$request->ip();
    }
}
