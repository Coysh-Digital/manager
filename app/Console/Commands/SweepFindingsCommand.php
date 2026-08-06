<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Findings\FindingsEvaluator;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-evaluates every site's findings on a clock rather than on a report.
 *
 * **This is what makes "a site stopped reporting" a thing anybody hears about.**
 *
 * Until this existed, {@see FindingsEvaluator::evaluate()} ran from exactly four places: ingesting an
 * inventory report, ingesting an updates report, opening the Findings screen, and opening a site's
 * Security tab. Three of the four require the site to be talking to us. So the one rule whose entire
 * subject is a site that has *stopped* talking to us - `site_not_reporting`, and with it the
 * `site.silent` notification - could only fire if somebody happened to open a screen. A destination
 * could be subscribed to "a site stops reporting" for a year, correctly configured, and never receive
 * anything, because the event was raised by the code path a silent site never reaches.
 *
 * Everything else that depends on the passage of time rather than on a report was in the same
 * position: a certificate crossing its thirty-day threshold, and every finding that should
 * self-resolve once the condition clears.
 *
 * Hourly, over every active site. A fleet is tens or hundreds of sites and each evaluation is a
 * handful of reads against rows already stored - nothing here calls out to anything, in keeping with
 * the rest of the platform.
 *
 * One site's failure does not stop the sweep. A sweep that gave up on the first bad row would leave
 * the rest of the fleet unevaluated for the same reason it was written: silently.
 */
final class SweepFindingsCommand extends Command
{
    protected $signature = 'manager:findings:sweep
                            {--dry-run : Report what would be evaluated without writing anything}';

    protected $description = 'Re-evaluate findings for every site, so time-based rules fire without a report';

    public function handle(FindingsEvaluator $evaluator): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $swept = 0;
        $opened = 0;
        $resolved = 0;
        $failed = 0;

        foreach (Site::query()->active()->cursor() as $site) {
            if ($dryRun) {
                $this->line("Would evaluate {$site->name}.");
                $swept++;

                continue;
            }

            try {
                $result = $evaluator->evaluate($site);
            } catch (Throwable $e) {
                // Reported and carried past. The alternative is one malformed report stopping the
                // fleet's only time-based check, which is the failure this command exists to end.
                $this->error("Could not evaluate {$site->name}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $swept++;
            $opened += $result['opened'];
            $resolved += $result['resolved'];
        }

        $this->info(sprintf(
            '%d %s evaluated. %d opened, %d resolved%s.',
            $swept,
            $swept === 1 ? 'site' : 'sites',
            $opened,
            $resolved,
            $failed > 0 ? sprintf(', %d could not be evaluated', $failed) : '',
        ));

        // Zero even when a site failed. This runs on a schedule, and a non-zero exit would be read
        // by whatever watches it as "the sweep did not run", when in fact it ran and said so.
        return self::SUCCESS;
    }
}
