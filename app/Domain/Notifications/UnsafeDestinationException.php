<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use RuntimeException;

/**
 * Thrown when a webhook destination cannot safely be requested.
 *
 * The message is written for whoever typed the URL, and deliberately does not include resolution
 * results: somebody probing internal address ranges should not get the answers back as a reward for
 * trying.
 */
final class UnsafeDestinationException extends RuntimeException {}
