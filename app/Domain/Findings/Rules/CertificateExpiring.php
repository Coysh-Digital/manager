<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;
use Illuminate\Support\Carbon;

/**
 * A TLS certificate that is about to stop working, or has.
 *
 * Like {@see SiteNotReporting}, this needs no capability, because it comes from the platform's own
 * observation rather than from anything the site said - and unlike every other rule here, the
 * observation is one the platform went and made itself. The connector cannot see this: TLS terminates
 * at the edge, so PHP on the origin sees whatever a proxy put in `$_SERVER`, which on a CDN-fronted
 * site is not the certificate a visitor validates.
 *
 * The thresholds are chosen around what an operator can do about it. Thirty days is roughly when
 * somebody should notice that automated renewal has not happened; seven days is when it needs doing
 * today; expired is an outage that is already happening.
 *
 * A site whose certificate could not be read at all is deliberately *not* a finding here. That is
 * usually DNS, a firewall, or a site that has moved, and reporting it as a certificate problem sends
 * somebody to look at the wrong thing. It shows on the site's own screen as what it is.
 */
final class CertificateExpiring implements Rule
{
    private const WARN_DAYS = 30;

    private const URGENT_DAYS = 7;

    public function key(): string
    {
        return 'certificate_expiring';
    }

    public function category(): string
    {
        return RuleCategory::SECURITY;
    }

    public function requiresCapability(): ?string
    {
        return null;
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $site = $snapshot->site;

        if ($site->certificate_expires_at === null) {
            // Never checked, or the check could not reach the site. Neither is a statement about the
            // certificate, and guessing would be worse than saying nothing.
            return null;
        }

        // now()->diffInDays($expiry), not the other way round. Carbon's diff returns "target minus
        // receiver", so the receiver has to be now for a future expiry to come out positive. Reversed,
        // every healthy certificate reads as long expired and every expired one as fine.
        $days = (int) now()->startOfDay()->diffInDays($site->certificate_expires_at->startOfDay(), false);

        if ($days > self::WARN_DAYS) {
            return null;
        }

        if ($days < 0) {
            return new RuleMatch(
                severity: Severity::HIGH,
                title: 'This site\'s TLS certificate has expired',
                detail: sprintf(
                    'It expired %s. Visitors are seeing a browser warning, and anything calling this '
                    .'site over HTTPS - including its own connector - may already be failing.',
                    $site->certificate_expires_at->diffForHumans(),
                ),
                evidence: $this->evidence($site->certificate_expires_at, $site->certificate_issuer, $days),
            );
        }

        return new RuleMatch(
            severity: $days <= self::URGENT_DAYS ? Severity::HIGH : Severity::MEDIUM,
            title: 'This site\'s TLS certificate expires soon',
            detail: sprintf(
                'It expires in %d day%s, on %s. If renewal is automatic, it has not happened yet and '
                .'is worth checking rather than waiting on.',
                $days,
                $days === 1 ? '' : 's',
                $site->certificate_expires_at->toFormattedDateString(),
            ),
            evidence: $this->evidence($site->certificate_expires_at, $site->certificate_issuer, $days),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(Carbon $expiresAt, ?string $issuer, int $days): array
    {
        // An expiry, an issuer and a count. Nothing about the site's traffic, its visitors or its
        // configuration - a finding is a conclusion, and the evidence for it should be the smallest
        // thing that supports the conclusion.
        return array_filter([
            'expires_at' => $expiresAt->toIso8601String(),
            'issuer' => $issuer,
            'days_remaining' => $days,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
