<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Provisioner;
use App\Domain\Audit\AuditRecorder;
use App\Http\Middleware\EnsureSetupIsAvailable;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * First run: create the organisation and its owner.
 *
 * Reachable only while no account exists — see {@see EnsureSetupIsAvailable}.
 * There is no token to configure and no default credential to forget to change, which is the point:
 * the specification requires refusing insecure defaults, and the surest way to do that is to have
 * none.
 */
final class SetupController
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Provisioner $provisioner,
    ) {}

    public function show(Request $request): View
    {
        return view('auth.setup', [
            'insecureConnection' => ! $request->isSecure() && ! app()->environment('local'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organisation' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->uncompromised()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $organisation = Organisation::query()->create(['name' => $validated['organisation']]);

            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            // The first account is verified by construction: whoever completed setup demonstrably
            // had access to the installation, which is a stronger claim than a click on an emailed
            // link. Later accounts verify by email in the usual way.
            $user->forceFill(['email_verified_at' => now()])->save();

            Membership::query()->create([
                'organisation_id' => $organisation->id,
                'user_id' => $user->id,
                'role' => Membership::ROLE_OWNER,
            ]);

            // Self-hosted has nothing to provision and the null implementation does nothing. It is
            // called anyway, inside the transaction, so that an organisation is never half-created:
            // an edition that allocates storage or a billing record here needs that work to roll
            // back with everything else if any of it fails.
            $this->provisioner->provision($organisation);

            $this->audit->record(
                action: 'platform.setup.completed',
                organisation: $organisation,
                actor: $user,
                actorType: AuditEvent::ACTOR_SYSTEM,
                after: ['organisation' => $organisation->name, 'owner' => $user->email],
            );

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.password_confirmed_at', now()->timestamp);

        return redirect()
            ->route('account.show')
            ->with('status', 'Welcome. Set up two-factor authentication before you add any sites.');
    }
}
