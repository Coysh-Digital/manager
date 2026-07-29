<?php

declare(strict_types=1);

namespace App\Domain\Pairing;

use RuntimeException;

/**
 * A pairing attempt that will not proceed.
 *
 * The reason is recorded in the audit log and returned to the operator through the correlation
 * identifier, but the connector is told only that pairing failed. Distinguishing "no such code"
 * from "already used" from "expired" would let someone probing the endpoint learn which codes had
 * ever existed.
 */
final class PairingRejected extends RuntimeException
{
    public const UNKNOWN_CODE = 'unknown_code';

    public const EXPIRED = 'expired';

    public const ALREADY_CONSUMED = 'already_consumed';

    public const MALFORMED = 'malformed';

    public const REPLACEMENT_NOT_AUTHORISED = 'replacement_not_authorised';

    public const SITE_ARCHIVED = 'site_archived';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Pairing rejected: {$reason}");
    }
}
