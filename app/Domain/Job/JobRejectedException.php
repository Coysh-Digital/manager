<?php

declare(strict_types=1);

namespace App\Domain\Job;

use RuntimeException;

/**
 * Thrown when a job cannot be enqueued or accepted.
 *
 * The reason is carried separately so it can be audited and logged without being returned verbatim
 * to a caller.
 */
final class JobRejectedException extends RuntimeException
{
    public const UNKNOWN_TYPE = 'unknown_type';

    public const CAPABILITY_NOT_GRANTED = 'capability_not_granted';

    public const INVALID_PARAMETERS = 'invalid_parameters';

    public const INVALID_RESULT = 'invalid_result';

    public const SITE_NOT_CONNECTED = 'site_not_connected';

    /**
     * A backup was asked for by an organisation holding no recovery key.
     *
     * Refused at the point of asking rather than cancelled at claim time, which is where it used to
     * happen - minutes later, on a screen nobody was watching.
     */
    public const NO_RECOVERY_KEY = 'no_recovery_key';

    public const NOT_CLAIMED_BY_THIS_CONNECTOR = 'not_claimed_by_this_connector';

    public const ALREADY_FINISHED = 'already_finished';

    public const EXPIRED = 'expired';

    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $problems = [],
    ) {
        parent::__construct("Job rejected: {$reason}");
    }
}
