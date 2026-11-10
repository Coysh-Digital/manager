<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Advisories\AdvisorySync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetches the published security advisories that sites are checked against.
 *
 * Hourly, because the value of an advisory feed is how soon after publication it reaches a site, and
 * the cost is one small request to a public API. Findings are re-evaluated on their own hourly pass,
 * so an advisory that arrives here is raised against a vulnerable site within the hour after that.
 *
 * A failure exits non-zero and leaves what is stored untouched - see {@see AdvisorySync} for why the
 * alternative is not an option. The scheduler logs the failure; nothing is retried, because the next
 * run is an hour away and the table is no worse for having missed one.
 */
final class SyncAdvisoriesCommand extends Command
{
    protected $signature = 'manager:advisories:sync';

    protected $description = 'Fetch published security advisories for Craft, so sites can be checked against them';

    public function handle(AdvisorySync $sync): int
    {
        if (! config('manager.advisories.enabled')) {
            $this->line('Advisory sync is switched off (MANAGER_ADVISORIES). Nothing fetched.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync();
        } catch (Throwable $e) {
            $this->error('Could not sync advisories: '.$e->getMessage().' What is stored is unchanged.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d %s stored, %d removed%s.',
            $result['stored'],
            $result['stored'] === 1 ? 'advisory' : 'advisories',
            $result['removed'],
            $result['skipped'] > 0 ? ", {$result['skipped']} skipped as unreadable" : '',
        ));

        return self::SUCCESS;
    }
}
