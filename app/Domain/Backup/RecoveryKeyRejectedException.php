<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;

/**
 * A recovery key operation this platform refused.
 *
 * The message is shown to the person who attempted it, so nothing that throws one of these may put the
 * submitted key material, a challenge plaintext or an expected answer into the message. Fingerprints
 * are fine and are usually the most useful thing to say — they are public, and "you used the wrong
 * key" is only actionable if it says which key was expected.
 */
final class RecoveryKeyRejectedException extends RuntimeException {}
