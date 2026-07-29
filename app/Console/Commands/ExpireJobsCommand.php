<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Job\JobService;
use Illuminate\Console\Command;

/**
 * Marks claimed jobs that outran their maximum runtime.
 *
 * Expiry is written rather than inferred on read, so "claimed" always means a connector is genuinely
 * expected to report back, and the audit log records the moment the platform gave up waiting.
 */
final class ExpireJobsCommand extends Command
{
    protected $signature = 'manager:jobs:expire';

    protected $description = 'Expire remote jobs that outran their maximum runtime';

    public function handle(JobService $jobs): int
    {
        $expired = $jobs->expireOverdue();

        $expired === 0
            ? $this->components->info('No jobs have outrun their runtime.')
            : $this->components->warn("{$expired} ".($expired === 1 ? 'job' : 'jobs').' expired without a result.');

        return self::SUCCESS;
    }
}
