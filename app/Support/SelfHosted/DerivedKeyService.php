<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\KeyService;
use RuntimeException;
use SensitiveParameter;

/**
 * Key wrapping for the self-hosted edition.
 *
 * Derives a wrapping key from the application secret and the supplied context, so a key wrapped for
 * one artifact cannot be unwrapped as another. There is no external key service to depend on, which
 * is rather the point of the edition.
 *
 * The trade-off is stated plainly in the documentation rather than glossed: whoever holds APP_KEY can
 * unwrap these. A self-hosted installation therefore cannot claim end-to-end encryption in the sense
 * Cloud can, and the specification is explicit that we should not pretend otherwise.
 */
final class DerivedKeyService implements KeyService
{
    private const PREFIX = 'sh1:';

    public function wrap(#[SensitiveParameter] string $plaintextKey, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $wrapped = sodium_crypto_secretbox($plaintextKey, $nonce, $this->wrappingKey($context));

        return self::PREFIX.base64_encode($nonce.$wrapped);
    }

    public function unwrap(string $wrappedKey, string $context): string
    {
        if (! str_starts_with($wrappedKey, self::PREFIX)) {
            throw new RuntimeException('Unrecognised key wrapping format.');
        }

        $raw = base64_decode(substr($wrappedKey, strlen(self::PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Malformed wrapped key.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->wrappingKey($context));

        // A wrong context is indistinguishable from a corrupt key from here, which is the correct
        // amount to reveal.
        if ($plaintext === false) {
            throw new RuntimeException('Could not unwrap this key.');
        }

        return $plaintext;
    }

    public function currentKeyIdentifier(): string
    {
        // Derived from the application key rather than being it, so the identifier can sit beside an
        // artifact without recording anything sensitive.
        return self::PREFIX.substr(hash('sha256', $this->applicationKey()), 0, 12);
    }

    private function wrappingKey(string $context): string
    {
        return hash_hkdf(
            'sha256',
            $this->applicationKey(),
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            'manager:key-wrap:'.$context,
        );
    }

    private function applicationKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set, so keys cannot be wrapped.');
        }

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
