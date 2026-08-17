<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Security\ProbeRecorder;
use App\Models\ProbeReport;
use App\Models\Site;
use Illuminate\Console\Command;

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

    public function handle(ProbeRecorder $recorder): int
    {
        $query = Site::query()->active();

        if (is_string($site = $this->option('site')) && $site !== '') {
            $query->where('external_id', $site);
        }

        $checked = 0;
        $problems = 0;

        foreach ($query->cursor() as $site) {
            // `record()` rather than `recordIfStale()`. A sweep on a clock has already decided how
            // often it wants to look, and an operator running this by hand is asking for an answer
            // now - skipping because the Refresh button was pressed nine minutes ago would make this
            // command silently do nothing.
            $report = $recorder->record($site);

            $checked++;

            if (! $report->succeeded()) {
                $this->line("  {$site->expected_domain}: {$report->error}");

                continue;
            }

            $notes = $this->notes($report);

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
     * Read off the stored report rather than the reading it came from, so this and the screen answer
     * from the same place. That is not only tidiness: `exposedPaths()` returns nothing unless
     * `exposureIsConclusive()`, and a reading whose control request failed has a populated `exposed`
     * list the screen deliberately refuses to draw any conclusion from. Reading the raw list here
     * printed "3 file(s) reachable" about sites the interface was, correctly, saying nothing about.
     *
     * @return list<string>
     */
    private function notes(ProbeReport $report): array
    {
        $notes = [];

        if ($report->exposedPaths() !== []) {
            $notes[] = count($report->exposedPaths()).' file(s) reachable';
        }

        if ($report->redirects_to_https === false) {
            $notes[] = 'plain HTTP is not redirected';
        }

        $missing = 0;

        foreach (['strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy'] as $header) {
            if ($report->header($header) === null) {
                $missing++;
            }
        }

        if ($missing > 0) {
            $notes[] = "{$missing} security header(s) missing";
        }

        return $notes;
    }
}
