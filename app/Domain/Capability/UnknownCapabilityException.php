<?php

declare(strict_types=1);

namespace App\Domain\Capability;

use InvalidArgumentException;

/**
 * Thrown when something asks for a capability the platform does not define.
 *
 * The registry is a closed set on purpose: a capability that is not in it cannot be granted, so a
 * typo fails loudly instead of creating a permission nobody ever reviews.
 */
final class UnknownCapabilityException extends InvalidArgumentException {}
