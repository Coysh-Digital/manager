<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\CertificateInspector;
use App\Domain\Security\CertificateReading;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reads the TLS certificate each site presents to its visitors.
 *
 * Once a day is the right cadence and it is worth saying why, because the instinct is to check more
 * often. A certificate does not change between checks in a way anybody can act on faster: the failure
 * this catches is a renewal that did not happen, and that becomes visible weeks before it matters.
 * Checking hourly would multiply the outbound connections by twenty-four to learn the same thing.
 *
 * Archived sites are skipped. A site somebody has finished with should not keep generating findings,
 * and it should certainly not keep generating outbound connections to a domain that may now belong to
 * somebody else.
 */
final class CheckCertificatesCommand extends Command
{
    protected $signature = 'manager:certificates:check
                            {--site= : Check one site by its external id}';

    protected $description = 'Read the TLS certificate each site presents';

    public function handle(CertificateInspector $inspector): int
    {
        $query = Site::query()->active();

        if (is_string($site = $this->option('site')) && $site !== '') {
            $query->where('external_id', $site);
        }

        $checked = 0;
        $problems = 0;

        foreach ($query->cursor() as $site) {
            $reading = $inspector->inspect($site->expected_domain);

            $site->forceFill([
                'certificate_checked_at' => Carbon::now(),
                'certificate_expires_at' => $reading->expiresAt,
                'certificate_issuer' => $reading->issuer,
                'certificate_subject' => $reading->subject,

                // Cleared on success, so a site that recovers stops carrying the reason it used to
                // fail. A stale error beside a fresh expiry is the kind of thing somebody acts on.
                'certificate_error' => $reading->error,

                // Written straight through, nulls included. A reading that could not judge trust —
                // an unreachable host, or a server with no certificate authorities - must clear a
                // previous judgement rather than leave it standing, because a "not trusted" badge
                // from three weeks ago sitting beside today's failed check is a claim about the
                // certificate that nothing has actually checked.
                'certificate_hostname_matches' => $reading->hostnameMatches,
                'certificate_trusted' => $reading->trusted,
                'certificate_self_signed' => $reading->selfSigned,
                'certificate_chain_length' => $reading->chainLength,
            ])->save();

            $checked++;

            if (! $reading->succeeded()) {
                $problems++;
                $this->line("  {$site->expected_domain}: {$reading->error}");

                continue;
            }

            // Reported before expiry, because it outranks it. A certificate for the wrong name is
            // failing visitors today, where one expiring in three weeks is failing nobody yet.
            if ($reading->hasTrustProblem()) {
                $problems++;
                $this->line("  {$site->expected_domain}: ".$this->trustProblem($reading));

                continue;
            }

            $days = $reading->daysRemaining();

            if ($days !== null && $days <= 30) {
                $problems++;
                $this->line("  {$site->expected_domain}: expires in {$days} days");
            }
        }

        $this->info("Checked {$checked} site(s), {$problems} worth looking at.");

        // Exits zero even with problems. This command's job is to record what it found; deciding that
        // an expiring certificate is a failure belongs to the findings rules and to whoever reads
        // them, not to the exit code of a sweep.
        return self::SUCCESS;
    }

    /**
     * The most serious thing wrong with a certificate, in one line.
     *
     * One rather than a list, because this is a sweep's console output and the point of it is that
     * somebody scanning fifty lines can see which sites need attention. The site's own screen shows
     * all three.
     */
    private function trustProblem(CertificateReading $reading): string
    {
        return match (true) {
            $reading->hostnameMatches === false => 'the certificate is for a different domain',
            $reading->selfSigned === true => 'self-signed',
            default => 'the chain is not trusted, which usually means a missing intermediate',
        };
    }
}
