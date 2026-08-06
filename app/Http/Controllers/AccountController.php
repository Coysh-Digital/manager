<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Auth\RecoveryCodeService;
use App\Domain\Auth\TotpService;
use App\Models\AuditEvent;
use App\Models\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * The account itself, across two of the Settings tabs: its details and time zone, and its own
 * security - second factor, recovery codes, passkeys and live sessions.
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

    /**
     * Who this account is: name, address, and the clock it reads times in.
     *
     * Split from {@see self::security()} rather than one screen carrying both, so listing every
     * time zone identifier and rendering an enrolment QR code stop happening for each other's
     * benefit. The writes are untouched and still named account.*.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('settings.account', [
            'user' => $user,
            'organisation' => app(Organisation::class),

            // Named on the "use the default" option rather than left implicit. A default is only a
            // useful default when the reader can see what it resolves to.
            'defaultTimezone' => (string) config('app.timezone', 'UTC'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    /**
     * How this account gets in, and from where.
     */
    public function security(Request $request): View
    {
        $user = $request->user();

        return view('settings.security', [
            'user' => $user,
            'organisation' => app(Organisation::class),

            'recoveryCodesRemaining' => $user->unusedRecoveryCodeCount(),
            'sessions' => $this->sessions($request),

            // Listed from the source of truth rather than counted, so the screen can name each device
            // and say when it was added.
            'passkeys' => $user->passkeys()->orderByDesc('created_at')->get(),

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
            return back()->with('warning', 'Start enrolment again - that setup expired.');
        }

        if (! $this->totp->verify($secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check your device clock and try again.')]);
        }

        $user = $request->user();

        // Written only now. A secret generated but never proved must not satisfy a requirement for
        // a second factor - otherwise abandoning enrolment halfway leaves an account looking
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

        return back()->with('status', 'Two-factor authentication is on. Save your recovery codes now - they will not be shown again.');
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

    /**
     * Change your own password.
     *
     * Until now the only way was the forgotten-password flow or a shell command, which meant a
     * routine change either went via an email round trip or via somebody with server access.
     *
     * Three things it insists on, each for its own reason:
     *
     *  - **The current password**, on top of the recent-authentication gate the route already
     *    carries. The gate proves somebody authenticated recently; this proves they know the secret
     *    they are replacing, which is what stops a borrowed unlocked laptop becoming a stolen
     *    account.
     *  - **The same rules as a reset** - twelve characters, checked against known breaches. Two
     *    different strength policies for the same secret is one policy and one loophole.
     *  - **Every other session ends.** If the change is happening because something felt wrong,
     *    leaving the other sessions signed in undoes the point of changing it.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->uncompromised()],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            // Recorded as a failure. Somebody guessing at a password from inside a live session is
            // exactly the sort of thing an audit log exists to show.
            $this->audit->record(
                action: 'user.password.changed',
                actor: $user,
                targetType: 'user',
                targetId: $user->external_id,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'Current password did not match',
            );

            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        DB::transaction(function () use ($user, $validated, $request): void {
            $user->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        });

        $this->audit->record(
            action: 'user.password.changed',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
        );

        return back()->with('status', 'Password changed. Every other session has been signed out.');
    }

    /**
     * Change the name this account is shown under.
     *
     * The name appears beside every audit event the account produces and on every backup it requests,
     * so a stale one makes the audit log harder to read for exactly the person trying to read it —
     * which is why "ask an administrator to change it in the database" was not a good enough answer.
     *
     * The email address is deliberately not editable here, and that is a decision rather than an
     * omission. It identifies the account for sign-in, password resets, invitations and every audit
     * row already written, so changing it is an account-recovery flow - proving the new address,
     * handling the window where neither is confirmed, deciding what happens to a pending invitation —
     * and none of that exists. A field that quietly moved sign-in to an unverified address would be
     * worse than no field.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
        ]);

        $name = trim($validated['name']);

        // Same early return as the organisation settings use. An audit log full of "changed the name
        // to the name it already was" is a log nobody reads.
        if ($name === $user->name) {
            return back()->with('status', 'Nothing to change.');
        }

        $before = ['name' => $user->name];

        $user->forceFill(['name' => $name])->save();

        $this->audit->record(
            action: 'user.profile.updated',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            before: $before,
            after: ['name' => $name],
        );

        return back()->with('status', 'Your name has been updated.');
    }

    /**
     * The zone this account reads dated times in.
     *
     * Its own action rather than a third field on updateProfile, which validates the name and
     * nothing else - the hosted edition documents relying on precisely that, and widening it to
     * carry a display preference would put a security assumption behind a convenience.
     *
     * Blank is a real answer and stores null, meaning "use this installation's own clock". A
     * preference nobody has expressed must not be written down as though they had: storing the
     * current default would pin the account to whatever it happened to be that day.
     */
    public function updateTimezone(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $timezone = ($validated['timezone'] ?? '') === '' ? null : $validated['timezone'];

        if ($timezone === $user->timezone) {
            return back()->with('status', 'Nothing to change.');
        }

        $before = ['timezone' => $user->timezone];

        $user->forceFill(['timezone' => $timezone])->save();

        $this->audit->record(
            action: 'user.timezone.updated',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            before: $before,
            after: ['timezone' => $timezone],
        );

        return back()->with('status', $timezone === null
            ? 'Times will be shown in this installation\'s own time zone.'
            : "Times will be shown in {$timezone}.");
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $codes = $this->recoveryCodes->regenerate($request->user());

        $request->session()->flash('recovery.fresh', $codes);

        return back()->with('status', 'New recovery codes generated. The previous set no longer works.');
    }

    /**
     * End another session.
     *
     * Deleting the session row is not enough on its own, and for a long time it was all this did.
     * "Stay signed in on this device" issues a recaller cookie which is checked against
     * `users.remember_token` and is entirely independent of the session table - so a device signed
     * out here simply re-authenticated on its next request, silently, and skipped the second factor
     * on the way through. The button reported success and the device stayed signed in.
     *
     * So the token is rotated as well. There is one per user rather than one per device, which means
     * this ends every remembered device rather than only the one named - and that is why the message
     * below says so. Overreaching and saying nothing would be worse than overreaching: somebody
     * signing a device out is answering a security question, and the answer has to be true.
     *
     * The same rotation already happens on a password reset, for the same reason.
     */
    public function revokeSession(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();

        $deleted = DB::table('sessions')
            ->where('id', $id)
            // Scoped to the current user, so an identifier from elsewhere signs out nobody.
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            return back()->with('status', 'That session had already ended.');
        }

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        // Re-issue this device's cookie against the new token when the current request is itself
        // riding on one. Without this, signing out another device signs out the device doing it —
        // not on the next visit, but on the very next request, which reads as the page breaking.
        if (Auth::viaRemember()) {
            Auth::login($user, true);
        }

        $this->audit->record(
            action: 'user.session.revoked',
            actor: $user,
            targetType: 'session',
            // A truncated identifier: enough to correlate, not enough to reuse.
            targetId: substr($id, 0, 8),
        );

        return back()->with(
            'status',
            'That session has been signed out. Any device set to stay signed in will have to sign in again.',
        );
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
