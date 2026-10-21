<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\ResetsAcknowledgement;
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
 * The thresholds are chosen around what an operator can do about it. The warning window is when
 * somebody should notice that automated renewal has not happened; seven days is when it needs doing
 * today; expired is an outage that is already happening.
 *
 * The window is the organisation's to set, thirty days unless it says otherwise, because the right
 * number depends on the issuer: a certificate that renews itself thirty days out is inside a
 * thirty-day window for a day or two of every cycle, and a short window is what makes a missed
 * renewal stand out from a renewal that is simply not due yet. The urgent tier is seven days or the
 * whole window, whichever is smaller, so that a short window cannot rank a certificate as urgent
 * before it is even a warning.
 *
 * An acknowledgement belongs to one certificate. If the expiry moves while the finding stays true,
 * the certificate has been replaced and the acknowledgement was about the one before - see
 * {@see ResetsAcknowledgement}.
 *
 * A site whose certificate could not be read at all is deliberately *not* a finding here. That is
 * usually DNS, a firewall, or a site that has moved, and reporting it as a certificate problem sends
 * somebody to look at the wrong thing. It shows on the site's own screen as what it is.
 */
final class CertificateExpiring implements ResetsAcknowledgement, Rule
{
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

        $warnDays = $snapshot->certificateWarnDays();

        if ($days > $warnDays) {
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
            severity: $days <= min(self::URGENT_DAYS, $warnDays) ? Severity::HIGH : Severity::MEDIUM,
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

    public function subjectChanged(array $previousEvidence, array $evidence): bool
    {
        $before = $previousEvidence['expires_at'] ?? null;
        $after = $evidence['expires_at'] ?? null;

        if (! is_string($before) || ! is_string($after)) {
            return false;
        }

        // Compared as instants rather than as strings, so a reformatted timestamp for the same
        // expiry is not mistaken for a replacement certificate.
        return Carbon::parse($before)->ne(Carbon::parse($after));
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
