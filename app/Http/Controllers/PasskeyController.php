<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Http\Requests\RegisterPasskeyRequest;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Support\WebAuthn;
use RuntimeException;

/**
 * Registering and removing passkeys.
 *
 * A passkey here is a **second factor**, not a replacement for the password. That is a deliberate
 * choice for a control plane: a single passkey on an unlocked, already-signed-in laptop would be one
 * factor, and this system can read every installation it manages.
 *
 * It is offered alongside TOTP rather than instead of it, and counts as satisfying the same
 * requirement - a passkey is phishing-resistant and bound to this origin, which makes it at least as
 * strong as a code read off a screen.
 *
 * Registration sits behind recent authentication. Adding a second factor is exactly the kind of thing
 * somebody who found an unlocked machine would like to do.
 *
 * The ceremony itself is the package's, driven action by action rather than through its routes. What
 * is kept here is everything that is specific to this platform: the audit trail, and the refusal to
 * remove a factor the organisation still requires.
 */
final class PasskeyController
{
    /**
     * Where the creation options are parked between the two halves of the ceremony.
     *
     * The package's request class reads this key when the credential comes back.
     */
    private const OPTIONS_KEY = 'passkey.registration_options';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Options for creating a new credential.
     */
    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $user = $this->currentUser($request->user());

        $options = $generate($user);

        // Held server-side, and pulled exactly once when the credential returns. The challenge that
        // gets verified has to be the one this platform issued: reading it back from the request
        // instead would accept a ceremony somebody captured or composed elsewhere.
        $request->session()->put(self::OPTIONS_KEY, WebAuthn::toJson($options));

        // Null values omitted, because some authenticators reject an explicit null where the
        // specification expects the member to be absent.
        return response()->json(WebAuthn::toBrowserArray($options));
    }

    /**
     * Store a newly created credential.
     */
    public function store(RegisterPasskeyRequest $request, StorePasskey $store): JsonResponse
    {
        $user = $this->currentUser($request->user());

        $label = $request->label();

        // Verifies the attestation against the stored options and this origin before writing
        // anything. A rejected ceremony raises a validation error, so nothing reaches the audit log
        // as a registration that did not happen.
        $store($user, $label, $request->credential(), $request->registrationOptions());

        $this->audit->record(
            action: 'user.passkey.registered',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            // The label, never the credential. A public key is not secret, but recording it invites
            // treating it as an identifier when the audit line already has one.
            after: ['label' => $label, 'passkeys' => $user->passkeyCount()],
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
        $passkey = $user->passkeys()->whereKey($id)->first();

        if ($passkey === null) {
            return back()->with('warning', 'That passkey no longer exists.');
        }

        // Refuse to remove the last second factor while the organisation requires one. Leaving an
        // account unable to satisfy a requirement it is subject to would lock the person out on
        // their next sign-in, which is a worse outcome than refusing here.
        $organisation = app(Organisation::class);

        $wouldLeaveNoFactor = $user->passkeyCount() <= 1 && ! $user->hasConfirmedTotp();

        if ($organisation->mfa_required && $wouldLeaveNoFactor) {
            return back()->with(
                'warning',
                'This organisation requires two-factor authentication. Add another passkey, or set up '
                .'an authenticator app, before removing this one.',
            );
        }

        $label = $passkey->name ?: 'Passkey';

        $passkey->delete();

        $this->audit->record(
            action: 'user.passkey.removed',
            actor: $user,
            targetType: 'user',
            targetId: $user->external_id,
            after: ['label' => $label, 'passkeys' => $user->passkeyCount()],
        );

        return back()->with('status', "Removed {$label}.");
    }

    /**
     * Narrow the guard's user to our own model.
     *
     * The package types its user as an interface that knows nothing about external identifiers or
     * passkey counts. A real check rather than a type assertion, so a misconfigured guard fails
     * loudly instead of at the first property access.
     */
    private function currentUser(mixed $user): User
    {
        if (! $user instanceof User) {
            throw new RuntimeException('Expected an authenticated Manager user.');
        }

        return $user;
    }
}
