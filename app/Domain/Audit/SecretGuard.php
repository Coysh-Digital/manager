<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use coyshdigital\managerprotocol\Protocol;

/**
 * Refuses to let secrets into the audit log.
 *
 * Invariant 13 says secrets must never appear in logs, exceptions, analytics or diagnostic
 * exports. Relying on every future call site to remember that is not a control, so the recorder
 * runs everything it is given through here first and **throws** rather than redacting silently.
 *
 * Throwing is deliberate. A quiet redaction would let a call site keep passing a password
 * indefinitely with nobody noticing; a failed write shows up immediately, in development, where it
 * is cheap to fix.
 */
final class SecretGuard
{
    /**
     * Key fragments that indicate a secret.
     *
     * Deliberately precise rather than broad. Matching on "key" alone would reject `public_key`
     * and `key_rotated_at`, both of which are safe and genuinely useful in an audit trail, and a
     * guard that cries wolf gets switched off.
     */
    private const FORBIDDEN_KEY_PATTERN = '~(password|passwd|secret|credential|authorization|cookie|token|private_key|secret_key|api_key|access_key|signing_key|bearer|totp|session_id)~i';

    /**
     * @param  array<array-key, mixed>|null  $data
     *
     * @throws SecretLeakException
     */
    public function assertSafe(?array $data, string $context): void
    {
        if ($data === null) {
            return;
        }

        $this->walk($data, $context, $context);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws SecretLeakException
     */
    private function walk(array $data, string $context, string $path): void
    {
        foreach ($data as $key => $value) {
            $childPath = $path.'.'.$key;

            if (is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1) {
                // The offending value is never included in the message: this exception will be
                // logged, and logging the secret is the thing being prevented.
                throw new SecretLeakException(
                    "Refusing to record audit data: '{$childPath}' looks like a secret."
                );
            }

            if (is_array($value)) {
                $this->walk($value, $context, $childPath);

                continue;
            }

            if (is_string($value) && $this->looksLikeSecretValue($value)) {
                throw new SecretLeakException(
                    "Refusing to record audit data: the value at '{$childPath}' looks like a credential."
                );
            }
        }
    }

    /**
     * Catch secrets that arrive under an innocuous key.
     *
     * Key-name matching only helps when the caller named the field honestly. These are the shapes
     * this system actually mints, so they are worth recognising wherever they turn up.
     */
    private function looksLikeSecretValue(string $value): bool
    {
        if (str_starts_with($value, Protocol::ENROLMENT_CODE_PREFIX)) {
            return true;
        }

        // Laravel's encrypted payloads and PHP password hashes.
        return str_starts_with($value, 'eyJpdiI6')
            || preg_match('~^\$2[aby]?\$\d{2}\$~', $value) === 1
            || preg_match('~^\$argon2(id|i)\$~', $value) === 1;
    }
}
