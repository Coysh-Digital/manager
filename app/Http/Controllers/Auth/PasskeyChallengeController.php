<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Satisfying the second-factor challenge with a passkey.
 *
 * Reached only with a pending challenge in the session, put there by the password step - the same
 * gate the TOTP challenge uses. A passkey is an alternative to typing a code, not a way around the
 * password.
 *
 * Three things keep that true, and they are deliberately not the same mechanism:
 *
 *  - The guard cannot resolve an assertion at all. config/auth.php uses the plain Eloquent provider,
 *    so there is no code path anywhere that turns a credential into a session on its own.
 *  - The assertion options are scoped to the pending user's credentials, so no other account's
 *    passkey is even offered.
 *  - Verification is told which user it must belong to, and the session is issued for *that* user
 *    rather than for whoever the credential resolves to. Without it, a passkey belonging to a
 *    different account could close a challenge opened for this one - the password step would have
 *    been for one person and the second factor for another.
 */
final class PasskeyChallengeController
{
    /**
     * Where the request options are parked between the two halves of the ceremony.
     *
     * The package's request class reads this key when the assertion comes back.
     */
    private const OPTIONS_KEY = 'passkey.verification_options';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Options for asserting an existing credential.
     */
    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return response()->json(['error' => 'no_pending_challenge'], 409);
        }

        // Scoped to this user. An unscoped ceremony would let the browser offer any credential it
        // holds for this origin, including one belonging to a different account.
        $options = $generate($user);

        $request->session()->put(self::OPTIONS_KEY, WebAuthn::toJson($options));

        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Complete the challenge.
     */
    public function store(Request $request, VerifyPasskey $verify): JsonResponse
    {
        $pending = $this->pendingUser($request);

        if ($pending === null) {
            return response()->json(['error' => 'no_pending_challenge'], 409);
        }

        // Resolved here rather than type-hinted on the method signature, and that ordering is the
        // point: a form request in the signature is validated by the framework *before* the method
        // body runs, so a call with no challenge behind it would be answered with a complaint about
        // its payload instead of a refusal. The gate comes first, then the credential is parsed.
        $credentials = app(PasskeyVerificationRequest::class);

        try {
            // The pending user is passed, not inferred. That is what refuses an assertion from a
            // credential belonging to somebody else, and it is checked inside the same transaction
            // that rewrites the signature counter.
            $verify($credentials->credential(), $credentials->verificationOptions(), $pending);
        } catch (InvalidPasskeyException) {
            $this->audit->record(
                action: 'auth.passkey.failed',
                actor: $pending,
                actorType: AuditEvent::ACTOR_USER,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'passkey assertion rejected',
            );

            return response()->json(['error' => 'assertion_failed'], 422);
        }

        // Remembered from the password step rather than read from this request. The choice belongs to
        // the form somebody actually ticked, and a long-lived cookie is not something the second-factor
        // call should be able to ask for on its own.
        $remember = (bool) ($request->session()->get('auth.challenge.remember') ?? false);

        // For the pending user, explicitly. Shared with the password and TOTP paths so that session
        // regeneration and the recent-authentication timestamp cannot differ between them.
        LoginController::issueSession($request, $pending, $remember, 'passkey');

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
