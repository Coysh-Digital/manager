<?php

declare(strict_types=1);

namespace App\Domain\Connector;

use RuntimeException;

/**
 * Thrown when replay protection cannot be consulted.
 *
 * Callers must treat this as a rejection, never as a pass. Without a working nonce check,
 * accepting a request means accepting replays of it.
 */
final class NonceStoreUnavailableException extends RuntimeException {}
