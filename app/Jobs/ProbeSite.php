<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Security\ProbeRecorder;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Looks at one site the way a visitor does, because somebody pressed Refresh.
 *
 * Queued rather than done inline for the same reason {@see NudgeSite} is: this makes up to ten
 * requests to somebody else's server, and the request that pressed the button must not wait on a
 * site that accepts a connection and then stalls. The screen says the check is running; the panel
 * has the answer on the next load.
 *
 * **Not retried, on purpose**, and for the opposite reason to {@see NudgeSite}. That one is not
 * retried because its work is an optimisation that will happen anyway. This one is not retried
 * because a probe that failed has already recorded *why* it failed, and that is the answer rather
 * than the absence of one - a site that did not respond is exactly what the panel needs to say.
 * Retrying would overwrite a true row with an identical one, having made another thirty requests to
 * a host that did not answer the first thirty.
 */
final class ProbeSite implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Ten requests, each with its own connect and read timeout, plus room for a host that is slow
     * rather than absent.
     */
    public int $timeout = 120;

    /**
     * Belt and braces over the floor in {@see ProbeRecorder::recordIfStale()}, and not a substitute
     * for it. This only holds while a job is queued or running, so it stops two presses ten seconds
     * apart and does nothing about two presses ten minutes apart - which is the case that actually
     * matters, because somebody who pressed Refresh, saw no change and pressed again does so after
     * the first job finished rather than during it.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(ProbeRecorder $recorder): void
    {
        // Re-read at run time rather than carrying the model, and through `active()`: a site
        // archived between the press and the worker must not be probed. Somebody who has finished
        // with a site should stop generating requests to a domain that may now belong to somebody
        // else, which is the same reason the daily sweep skips them.
        $site = Site::query()->active()->find($this->siteId);

        if ($site === null) {
            return;
        }

        $recorder->recordIfStale($site);
    }
}
