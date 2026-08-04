<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use coyshdigital\managerprotocol\ProtocolException;
use coyshdigital\managerprotocol\Sealing;
use RuntimeException;

/**
 * The platform's X25519 encryption identity.
 *
 * Separate from the Ed25519 signing pair, and worth saying why rather than leaving it to be inferred:
 * using one keypair for both signing and encryption is a well-known way to weaken both, so there are
 * two, generated separately and rotated separately.
 *
 * A connector holds only the public half and seals each artifact's key to it, which means a site
 * compromised in September cannot recover the keys to artifacts it uploaded in June. It also means the
 * platform can open every artifact it holds. That is a real property of this design and the
 * documentation says so plainly rather than calling it end-to-end encryption.
 *
 * Losing the secret key makes every stored artifact permanently unreadable. There is no recovery path
 * and there should not be one - a backup of the backup key held next to the backups would defeat the
 * point of encrypting them.
 */
final class BackupKeypair
{
    public function publicKey(): string
    {
        return $this->require('public_key');
    }

    public function secretKey(): string
    {
        return $this->require('secret_key');
    }

    public function isConfigured(): bool
    {
        $public = config('manager.backups.public_key');
        $secret = config('manager.backups.secret_key');

        return is_string($public)
            && is_string($secret)
            && $public !== ''
            && $secret !== ''
            && Sealing::isValidBoxPublicKey($public);
    }

    /**
     * Open a key a connector sealed to this platform.
     *
     * @throws ProtocolException
     */
    public function unseal(string $sealedBase64): string
    {
        return Sealing::unseal($sealedBase64, $this->publicKey(), $this->secretKey());
    }

    private function require(string $key): string
    {
        $value = config("manager.backups.{$key}");

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(
                "The backup encryption {$key} is not configured. Run `php artisan manager:backups:keygen`."
            );
        }

        return $value;
    }
}
