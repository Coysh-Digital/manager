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
            $this->recordFailure($request, $credentials['email']);

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

        $this->clearFailures($request, $credentials['email']);

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
        $max = (int) config('manager.auth.max_login_attempts');

        foreach ($this->throttleKeys($request, $email) as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit === 0 ? $max : $limit)) {
                throw ValidationException::withMessages([
                    'email' => __('Too many attempts. Try again in :seconds seconds.', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
        }
    }

    /**
     * Every bucket a failed attempt counts against, and what each one is for.
     *
     * The composite key was here first, and its reasoning was sound as far as it went: on the
     * address alone anyone could lock a known user out by failing on purpose, and on the source
     * alone a shared office address would lock out colleagues. Both together fails in the safer
     * direction for the account being guessed.
     *
     * What it does not do is bound either axis on its own, and both single-axis attacks are the
     * ordinary ones:
     *
     *  - **Spraying** - one common password against many addresses from one source. Every address
     *    is a fresh composite bucket, so the source was never limited at all.
     *  - **Credential stuffing** - one address from many sources. Every source is a fresh composite
     *    bucket, so the account was never limited either.
     *
     * So all three exist, and the composite keeps its original job of being the tightest of them.
     * The two wider buckets are deliberately looser: they have to sit above the traffic a busy
     * office or a person with several accounts produces legitimately, and their purpose is to bound
     * the attack rather than to catch a typo.
     *
     * The account bucket is still a lockout somebody can inflict on a known address. That is the
     * trade the composite was written to avoid and it cannot be avoided entirely - what can be done
     * is to set it high enough that reaching it takes deliberate effort, which is why it is a
     * multiple rather than the same number.
     *
     * @return array<string, int> key => limit, where 0 means "use the configured attempt limit"
     */
    private function throttleKeys(Request $request, string $email): array
    {
        $max = (int) config('manager.auth.max_login_attempts');
        $address = Str::lower($email);
        $source = (string) $request->ip();

        return [
            // Tightest, and unchanged: this address from this source.
            'login:'.$address.'|'.$source => 0,

            // This address from anywhere - bounds credential stuffing.
            'login-account:'.$address => $max * 4,

            // This source against anything - bounds spraying.
            'login-source:'.$source => $max * 10,
        ];
    }

    /**
     * Record a failure against every bucket.
     */
    private function recordFailure(Request $request, string $email): void
    {
        $decay = (int) config('manager.auth.login_decay_seconds');

        foreach (array_keys($this->throttleKeys($request, $email)) as $key) {
            RateLimiter::hit($key, $decay);
        }
    }

    /**
     * Clear only the buckets a successful sign-in should clear.
     *
     * The composite and the account, never the source. Somebody spraying an installation will
     * eventually guess a password that works, and clearing the source bucket on that success would
     * hand them a reset of the limit that exists to stop them.
     */
    private function clearFailures(Request $request, string $email): void
    {
        RateLimiter::clear('login:'.Str::lower($email).'|'.(string) $request->ip());
        RateLimiter::clear('login-account:'.Str::lower($email));
    }
}
