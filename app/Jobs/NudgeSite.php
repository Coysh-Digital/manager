<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Connector\NudgeDispatcher;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Asks one site to check in now.
 *
 * Queued rather than sent inline, so the request that pressed **Request backup** never waits on
 * somebody else's server. A site that accepts a connection and stalls would otherwise make this
 * platform's own control panel slow, which is a worse outcome than the latency being removed.
 *
 * **Not retried, on purpose.** Every other queued job here retries because its work needs to happen.
 * This one is an optimisation: if the site cannot be reached now, it will claim on its own schedule
 * within minutes, and the work happens anyway. Retrying would spend attempts on making something
 * marginally faster while the honest fallback is already running.
 */
final class NudgeSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Seconds during which a site is nudged at most once.
     *
     * Queueing twelve jobs for one site, or requesting a backup across two hundred, should produce one
     * knock per site and not one per job. The window is short enough that a genuinely separate request
     * a minute later still gets its own.
     */
    private const DEBOUNCE_SECONDS = 15;

    public function __construct(public readonly int $siteId) {}

    public function handle(NudgeDispatcher $dispatcher): void
    {
        // `add()` writes only when the key is absent, so exactly one of any number of concurrent jobs
        // wins it - the same primitive the connector throttles on at the other end.
        if (! Cache::add($this->cacheKey(), true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        $site = Site::find($this->siteId);

        if ($site === null) {
            return;
        }

        $dispatcher->dispatch($site);
    }

    private function cacheKey(): string
    {
        return "manager:nudge:{$this->siteId}";
    }
}
