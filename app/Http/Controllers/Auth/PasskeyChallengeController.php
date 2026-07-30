<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

/**
 * Satisfying the second-factor challenge with a passkey.
 *
 * Reached only with a pending challenge in the session, put there by the password step — the same
 * gate the TOTP challenge uses. A passkey is an alternative to typing a code, not a way around the
 * password.
 *
 * Two things keep that true:
 *
 *  - The assertion options are scoped to the pending user's credentials, so no other account's
 *    passkey is even offered.
 *  - The login is gated by a callback that rejects any user other than the pending one. Without it, a
 *    passkey belonging to a different account could satisfy a challenge opened for this one — the
 *    password step would have been for one user and the second factor for another.
 */
final class PasskeyChallengeController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Options for asserting an existing credential.
     */
    public function options(AssertionRequest $request): Responsable|JsonResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return response()->json(['error' => 'no_pending_challenge'], 409);
        }

        // Scoped to this user. An unscoped ceremony would let the browser offer any credential it
        // holds for this origin, including one belonging to a different account.
        return $request->toVerify($user);
    }

    /**
     * Complete the challenge.
     */
    public function store(AssertedRequest $request): JsonResponse
    {
        $pending = $this->pendingUser($request);

        if ($pending === null) {
            return response()->json(['error' => 'no_pending_challenge'], 409);
        }

        $remember = (bool) ($request->session()->get('auth.challenge.remember') ?? false);

        $user = $request->login(
            remember: $remember,
            callbacks: [
                // The guard that matters. attemptWhen refuses the login outright when this returns
                // false, so a passkey for another account cannot close this challenge.
                //
                // The instanceof is doing real work as well as narrowing the package's interface: a
                // credential resolving to anything other than a Manager user has no business closing
                // a Manager challenge.
                fn (WebAuthnAuthenticatable $candidate): bool => $candidate instanceof User
                    && $candidate->id === $pending->id,
            ],
        );

        if ($user === null) {
            $this->audit->record(
                action: 'auth.passkey.failed',
                actor: $pending,
                actorType: AuditEvent::ACTOR_USER,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'passkey assertion rejected',
            );

            return response()->json(['error' => 'assertion_failed'], 422);
        }

        // The package regenerated the session on success. The rest of the post-login work is ours,
        // and is the same work the TOTP path does — so it lives in one place.
        LoginController::finaliseSession($request, $pending, 'passkey');

        return response()->json(['redirect' => route('sites.index')]);
    }

    /**
     * The user who passed the password step, if the challenge is still open.
     */
    private function pendingUser(Request $request): ?User
    {
        /** @var array{user_id: int, at: int}|null $challenge */
        $challenge = $request->session()->get('auth.challenge');

        if ($challenge === null || now()->timestamp - $challenge['at'] > 300) {
            return null;
        }

        return User::query()->find($challenge['user_id']);
    }
}
