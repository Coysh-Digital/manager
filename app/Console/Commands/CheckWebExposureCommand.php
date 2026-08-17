<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\ProbeReading;
use App\Domain\Security\SiteProbe;
use App\Models\ProbeReport;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Looks at each site the way a visitor does.
 *
 * Once a day, and the cadence is chosen the same way {@see CheckCertificatesCommand}'s is: nothing
 * this finds changes between checks in a way anybody can act on faster. A security header appears or
 * disappears on a deploy, and a file left in a webroot stays there. Checking hourly would multiply
 * the requests made to somebody else's server by twenty-four to learn the same thing.
 *
 * It runs after the certificate sweep rather than beside it, so a fleet is not making two sets of
 * outbound connections to the same hosts at the same minute.
 *
 * Archived sites are skipped. A site somebody has finished with should not keep generating findings,
 * and it certainly should not keep generating requests to a domain that may now belong to somebody
 * else.
 */
final class CheckWebExposureCommand extends Command
{
    protected $signature = 'manager:web:check
                            {--site= : Check one site by its external id}';

    protected $description = 'Look at what each site serves to somebody outside it';

    public function handle(SiteProbe $probe): int
    {
        $query = Site::query()->active();

        if (is_string($site = $this->option('site')) && $site !== '') {
            $query->where('external_id', $site);
        }

        $checked = 0;
        $problems = 0;

        foreach ($query->cursor() as $site) {
            $reading = $probe->probe($site);

            ProbeReport::query()->create([
                'site_id' => $site->id,
                'payload' => $reading->toPayload(),
                'status' => $reading->status,
                'redirects_to_https' => $reading->redirectsToHttps,
                'answers_everything' => $reading->answersEverything,

                // Null rather than zero when the answer could not be trusted. Zero would read as
                // "nothing is exposed", which is a claim the control request says cannot be made.
                'exposed_count' => $reading->answersEverything === false ? count($reading->exposed) : null,
                'error' => $reading->error,
                'probed_at' => Carbon::now(),
            ]);

            $checked++;

            if (! $reading->succeeded()) {
                $this->line("  {$site->expected_domain}: {$reading->error}");

                continue;
            }

            $notes = $this->notes($reading);

            if ($notes !== []) {
                $problems++;
                $this->line("  {$site->expected_domain}: ".implode('; ', $notes));
            }
        }

        $this->info("Checked {$checked} site(s), {$problems} worth looking at.");

        // Exits zero even with problems, exactly as the certificate sweep does. This command records
        // what it saw; deciding that a missing header is a failure belongs to the findings rules and
        // to whoever reads them, not to the exit code of a sweep.
        return self::SUCCESS;
    }

    /**
     * The headline facts, for somebody scanning a sweep's output.
     *
     * @return list<string>
     */
    private function notes(ProbeReading $reading): array
    {
        $notes = [];

        if ($reading->exposed !== []) {
            $notes[] = count($reading->exposed).' file(s) reachable';
        }

        if ($reading->redirectsToHttps === false) {
            $notes[] = 'plain HTTP is not redirected';
        }

        $missing = 0;

        foreach (['strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy'] as $header) {
            if ($reading->header($header) === null) {
                $missing++;
            }
        }

        if ($missing > 0) {
            $notes[] = "{$missing} security header(s) missing";
        }

        return $notes;
    }
}
