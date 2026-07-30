<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use RuntimeException;

/**
 * Registering and removing passkeys.
 *
 * A passkey here is a **second factor**, not a replacement for the password. That is a deliberate
 * choice for a control plane: a single passkey on an unlocked, already-signed-in laptop would be one
 * factor, and this system can read every installation it manages.
 *
 * It is offered alongside TOTP rather than instead of it, and counts as satisfying the same
 * requirement — a passkey is phishing-resistant and bound to this origin, which makes it at least as
 * strong as a code read off a screen.
 *
 * Registration sits behind recent authentication. Adding a second factor is exactly the kind of thing
 * somebody who found an unlocked machine would like to do.
 */
final class PasskeyController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Options for creating a new credential.
     */
    public function options(AttestationRequest $request): Responsable
    {
        // Deliberately not userless(): the credential is tied to this account, because it is a second
        // factor for it rather than a standalone identity.
        return $request->toCreate();
    }

    /**
     * Store a newly created credential.
     */
    public function store(AttestedRequest $request): JsonResponse
    {
        $user = $this->currentUser($request->user());

        $label = trim((string) $request->input('name', '')) ?: 'Passkey';

        $request->save(['alias' => mb_substr($label, 0, 60)]);

        $this->audit->record(
            action: 'user.passkey.registered',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            // The label, never the credential. A public key is not secret, but recording it invites
            // treating it as an identifier when the audit line already has one.
            after: ['label' => $label, 'passkeys' => $user->enabledPasskeyCount()],
        );

        return response()->json(['registered' => true]);
    }

    /**
     * Remove a passkey.
     */
    public function destroy(Request $request, string $id): RedirectResponse
    {
        $user = $this->currentUser($request->user());

        // Scoped to this user's own credentials, so an identifier from elsewhere removes nothing.
        $credential = $user->webAuthnCredentials()->whereKey($id)->first();

        if ($credential === null) {
            return back()->with('warning', 'That passkey no longer exists.');
        }

        // Refuse to remove the last second factor while the organisation requires one. Leaving an
        // account unable to satisfy a requirement it is subject to would lock the person out on
        // their next sign-in, which is a worse outcome than refusing here.
        $organisation = app(Organisation::class);

        $wouldLeaveNoFactor = $user->enabledPasskeyCount() <= 1 && ! $user->hasConfirmedTotp();

        if ($organisation->mfa_required && $wouldLeaveNoFactor) {
            return back()->with(
                'warning',
                'This organisation requires two-factor authentication. Add another passkey, or set up '
                .'an authenticator app, before removing this one.',
            );
        }

        $label = $credential->alias ?: 'Passkey';

        $credential->delete();

        $this->audit->record(
            action: 'user.passkey.removed',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            after: ['label' => $label, 'passkeys' => $user->enabledPasskeyCount()],
        );

        return back()->with('status', "Removed {$label}.");
    }

    /**
     * Narrow the guard's user to our own model.
     *
     * The WebAuthn request classes type their user as the package's interface, which knows nothing
     * about external identifiers or passkey counts. A real check rather than a type assertion, so a
     * misconfigured guard fails loudly instead of at the first property access.
     */
    private function currentUser(mixed $user): User
    {
        if (! $user instanceof User) {
            throw new RuntimeException('Expected an authenticated Manager user.');
        }

        return $user;
    }
}
