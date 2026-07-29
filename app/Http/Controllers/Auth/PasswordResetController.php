<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Password reset.
 *
 * Built on Laravel's password broker: single-use, expiring, hashed tokens are exactly the problem
 * it already solves well, and hand-rolling that would be gratuitous risk.
 *
 * Resetting a password does not bypass the second factor. Somebody who reaches a mailbox still has
 * to satisfy the authenticator, which is the whole reason for having one.
 */
final class PasswordResetController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink($validated);

        // Deliberately the same response whether or not the address is known. Anything else turns
        // this into a way to find out who has an account.
        return back()->with('status', 'If that address has an account, a reset link is on its way.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->uncompromised()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // Every other session ends. If the reset happened because the account was compromised,
            // leaving the attacker's session alive would undo the point of resetting.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $this->audit->record(
                action: 'user.password.reset',
                actor: $user,
                targetType: 'user',
                targetId: $user->external_id,
            );

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('status', 'Your password has been changed. Sign in with it.');
    }
}
