<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Wraps and unwraps data-encryption keys.
 *
 * One of the cloud-specific seams the specification asks for. Self-hosted wraps with a key derived
 * from the application's own secret; Cloud wraps with a managed key service, so that a database
 * compromise alone does not yield readable backups.
 *
 * The interface exists in Phase 1 even though only the self-hosted implementation does, because the
 * alternative is discovering at Phase 3 that backup encryption assumed one edition's key handling.
 */
interface KeyService
{
    /**
     * Wrap a freshly generated data-encryption key for storage alongside the artifact it protects.
     */
    public function wrap(string $plaintextKey, string $context): string;

    /**
     * Unwrap a stored key. Fails rather than returning anything usable if the context differs.
     */
    public function unwrap(string $wrappedKey, string $context): string;

    /**
     * Which key wrapped it, so an artifact remains readable across a key rotation.
     */
    public function currentKeyIdentifier(): string;
}
