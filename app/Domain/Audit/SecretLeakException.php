<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use RuntimeException;

/**
 * Thrown when something tries to write a secret into the audit log.
 *
 * The message names the offending path and never the value, because this exception gets logged and
 * logging the secret is exactly what is being prevented.
 */
final class SecretLeakException extends RuntimeException {}
