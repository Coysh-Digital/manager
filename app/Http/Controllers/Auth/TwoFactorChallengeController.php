<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\RecoveryCodeService;
use App\Domain\Auth\TotpService;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The second factor.
 *
 * Reached only with a pending challenge in the session, put there by the password step. Nobody is
 * authenticated until this passes.
 */
final class TwoFactorChallengeController
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $key = 'two-factor:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('manager.auth.max_login_attempts'))) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        // One field takes either a six-digit code or a recovery code. Asking someone whose phone
        // has just died to first find the right form is a bad moment to add a step.
        $accepted = RecoveryCodeService::looksLikeRecoveryCode($validated['code'])
            ? $this->recoveryCodes->consume($user, $validated['code'])
            : $this->totp->verify((string) $user->totp_secret, $validated['code']);

        if (! $accepted) {
            RateLimiter::hit($key, (int) config('manager.auth.login_decay_seconds'));

            $this->audit->record(
                action: 'auth.two_factor.failed',
                actor: $user,
                actorType: AuditEvent::ACTOR_USER,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'invalid second factor',
            );

            throw ValidationException::withMessages([
                'code' => __('That code is not valid.'),
            ]);
        }

        RateLimiter::clear($key);

        $remember = (bool) ($request->session()->get('auth.challenge.remember') ?? false);

        return LoginController::completeLogin($request, $user, $remember);
    }

    /**
     * The user who passed the password step, if the challenge is still open.
     *
     * The challenge expires on its own so an abandoned half-login cannot be picked up later from
     * the same browser.
     */
    private function pendingUser(Request $request): ?User
    {
        /** @var array{user_id: int, at: int}|null $challenge */
        $challenge = $request->session()->get('auth.challenge');

        if ($challenge === null) {
            return null;
        }

        if (now()->timestamp - $challenge['at'] > 300) {
            $request->session()->forget('auth.challenge');

            return null;
        }

        return User::query()->find($challenge['user_id']);
    }
}
