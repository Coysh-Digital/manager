<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;

/**
 * An artifact the platform will not accept, or will not hand back.
 *
 * Carries a message written to be shown: it says what was wrong without saying anything about where
 * artifacts are stored, what other artifacts exist, or how the checksum was computed.
 */
final class BackupRejectedException extends RuntimeException {}
