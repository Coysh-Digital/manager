<?php

declare(strict_types=1);

namespace App\Domain\Job;

use InvalidArgumentException;

/**
 * Thrown when something asks for a job type the registry does not define.
 *
 * Invariant 9. The registry is closed, so this is what a typo produces rather than a job that runs
 * something nobody reviewed.
 */
final class UnknownJobTypeException extends InvalidArgumentException {}
