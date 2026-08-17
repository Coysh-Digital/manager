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
 *
 * The same distinction runs through the three judgements below, which is why each of them is a
 * *nullable* boolean rather than a boolean. `false` means this check looked and the answer is no.
 * `null` means it could not tell - an older reading taken before these existed, a host that never
 * answered, or a server with no certificate authorities installed to judge trust against. Collapsing
 * null into false would put "we cannot check trust here" and "this certificate is not trusted" in the
 * same red row, and only one of those is about the site.
 */
final class CertificateReading
{
    public function __construct(
        public readonly ?Carbon $expiresAt,
        public readonly ?string $issuer,
        public readonly ?string $subject,
        public readonly ?string $error,

        /**
         * Whether the certificate is actually for the hostname it was served on.
         *
         * A certificate can be perfectly valid, freshly issued and trusted by everybody, and still be
         * for a different name - which a browser reports as an interstitial and this check used to
         * report as "expires in 88 days".
         */
        public readonly ?bool $hostnameMatches = null,

        /**
         * Whether a verifying client would accept the chain the site presented.
         *
         * False covers the missing-intermediate case that is the classic "works in Chrome, fails in
         * curl and on Android" outage: the leaf is fine, the root is fine, and the server did not
         * send the certificate joining them.
         */
        public readonly ?bool $trusted = null,

        /** Whether the certificate signed itself. */
        public readonly ?bool $selfSigned = null,

        /** How many certificates the server sent, leaf included. */
        public readonly ?int $chainLength = null,
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
     * Whether anything about this certificate would stop a browser accepting it, expiry aside.
     *
     * Expiry is left out deliberately: it has its own rule, its own thresholds and its own screen
     * copy, and folding it in here would produce two findings saying the same thing on the day a
     * certificate lapses.
     */
    public function hasTrustProblem(): bool
    {
        return $this->hostnameMatches === false
            || $this->trusted === false
            || $this->selfSigned === true;
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
