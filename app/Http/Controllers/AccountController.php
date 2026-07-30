<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\RecoveryCodeService;
use App\Domain\Auth\TotpService;
use App\Models\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The account's own security: second factor, recovery codes, and live sessions.
 *
 * Everything that changes state here sits behind a recent-authentication check, so a session left
 * open on an unlocked machine cannot be used to disable a second factor or read out fresh recovery
 * codes.
 */
final class AccountController
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.show', [
            'user' => $user,
            'organisation' => app(Organisation::class),
            'recoveryCodesRemaining' => $user->unusedRecoveryCodeCount(),
            'sessions' => $this->sessions($request),

            // Listed from the source of truth rather than counted, so the screen can name each device
            // and say when it was added.
            'passkeys' => $user->webAuthnCredentials()->orderByDesc('created_at')->get(),

            // Held in the session between starting enrolment and confirming it. It is not written
            // to the user until a valid code proves the authenticator actually has it.
            'pendingSecret' => $request->session()->get('totp.pending'),
            'pendingQrCode' => $request->session()->has('totp.pending')
                ? $this->totp->qrCodeSvg($user, (string) $request->session()->get('totp.pending'))
                : null,
            'pendingManualEntry' => $request->session()->has('totp.pending')
                ? $this->totp->formatForManualEntry((string) $request->session()->get('totp.pending'))
                : null,

            // Shown once, immediately after generating. Never retrievable afterwards.
            'freshRecoveryCodes' => $request->session()->get('recovery.fresh'),
        ]);
    }

    /**
     * Begin enrolling a second factor.
     */
    public function startTotp(Request $request): RedirectResponse
    {
        $request->session()->put('totp.pending', $this->totp->generateSecret());

        return back();
    }

    /**
     * Confirm a second factor with a code from the authenticator.
     */
    public function confirmTotp(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);

        $secret = (string) $request->session()->get('totp.pending');

        if ($secret === '') {
            return back()->with('warning', 'Start enrolment again — that setup expired.');
        }

        if (! $this->totp->verify($secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check your device clock and try again.')]);
        }

        $user = $request->user();

        // Written only now. A secret generated but never proved must not satisfy a requirement for
        // a second factor — otherwise abandoning enrolment halfway leaves an account looking
        // protected when it is not.
        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('totp.pending');

        $codes = $this->recoveryCodes->regenerate($user);
        $request->session()->flash('recovery.fresh', $codes);

        $this->audit->record(
            action: 'user.two_factor.enabled',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
        );

        return back()->with('status', 'Two-factor authentication is on. Save your recovery codes now — they will not be shown again.');
    }

    /**
     * Turn off the second factor.
     */
    public function disableTotp(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (app(Organisation::class)->mfa_required) {
            return back()->with('warning', 'This organisation requires two-factor authentication, so it cannot be turned off.');
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

            // Recovery codes exist to recover a second factor. With no second factor they are just
            // a second password, so they go too.
            $user->recoveryCodes()->delete();
        });

        $this->audit->record(
            action: 'user.two_factor.disabled',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
        );

        return back()->with('warning', 'Two-factor authentication is off, and your recovery codes have been deleted.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $codes = $this->recoveryCodes->regenerate($request->user());

        $request->session()->flash('recovery.fresh', $codes);

        return back()->with('status', 'New recovery codes generated. The previous set no longer works.');
    }

    /**
     * End another session.
     */
    public function revokeSession(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();

        $deleted = DB::table('sessions')
            ->where('id', $id)
            // Scoped to the current user, so an identifier from elsewhere signs out nobody.
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted > 0) {
            $this->audit->record(
                action: 'user.session.revoked',
                actor: $user,
                targetType: 'session',
                // A truncated identifier: enough to correlate, not enough to reuse.
                targetId: substr($id, 0, 8),
            );
        }

        return back()->with('status', 'That session has been signed out.');
    }

    /**
     * Every live session for this user.
     *
     * Read straight from the session table, which is why the database driver is configured: a
     * file or cache driver cannot answer "where am I signed in".
     *
     * @return Collection<int, \stdClass>
     */
    private function sessions(Request $request): Collection
    {
        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (\stdClass $session) use ($request): \stdClass {
                $session->is_current = $session->id === $request->session()->getId();
                $session->last_active = Carbon::createFromTimestamp($session->last_activity);

                return $session;
            });
    }
}
