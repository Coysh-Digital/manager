<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\CertificateInspector;
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
            ])->save();

            $checked++;

            if (! $reading->succeeded()) {
                $problems++;
                $this->line("  {$site->expected_domain}: {$reading->error}");

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
}
