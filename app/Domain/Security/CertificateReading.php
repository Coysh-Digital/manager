<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Support\Carbon;

/**
 * What a TLS handshake told us, or why it told us nothing.
 *
 * The two are kept distinct rather than collapsed into a nullable expiry, because "we could not reach
 * this site" and "this certificate expires on Tuesday" are different facts and only one of them is
 * about the certificate. A screen that showed an unreachable site as having no expiry would look
 * exactly like a site with a problem it does not have.
 */
final class CertificateReading
{
    public function __construct(
        public readonly ?Carbon $expiresAt,
        public readonly ?string $issuer,
        public readonly ?string $subject,
        public readonly ?string $error,
    ) {}

    public static function failed(string $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function succeeded(): bool
    {
        return $this->expiresAt !== null && $this->error === null;
    }

    /**
     * Days until expiry, negative when already expired.
     *
     * Null when there is nothing to count from, which a caller must handle rather than treating as
     * zero - a site we could not reach is not a site whose certificate expires today.
     */
    public function daysRemaining(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        // Carbon returns a float here, and a partial day would render as "expires in 6.97 days" on a
        // screen. Truncated towards zero rather than rounded, so a certificate with a few hours left
        // reads as 0 rather than as 1 - the direction that makes somebody act sooner.
        return (int) now()->startOfDay()->diffInDays($this->expiresAt->startOfDay(), false);
    }
}
