<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Console\Commands\CheckWebExposureCommand;
use App\Http\Controllers\SiteController;
use App\Jobs\NudgeSite;
use App\Jobs\ProbeSite;
use App\Models\ProbeReport;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Looking at one site from outside it, and writing down what was seen.
 *
 * The step between {@see SiteProbe}, which knows how to make the requests, and the two things that
 * ask for them: the daily sweep in {@see CheckWebExposureCommand}, and the
 * Refresh button by way of {@see ProbeSite}. It exists because that row used to be written
 * inline in the sweep, and a second caller would have meant a second copy of the mapping from a
 * reading to a row - including the `exposed_count` rule below, which is the part it would be easiest
 * to get subtly wrong in a copy.
 *
 * The two entry points differ in one respect and it is deliberate. A sweep on a clock has already
 * decided how often it wants to look; a button has not, and is pressed by people. So the sweep gets
 * {@see self::record()} and the button gets {@see self::recordIfStale()}.
 */
final class ProbeRecorder
{
    /**
     * How recently a site must have been looked at for another look to be skipped.
     *
     * The baseline is daily, so anything under a day is already a large increase in the number of
     * requests this platform makes to somebody else's server. Ten minutes is short enough that
     * somebody who has just corrected a header and pressed Refresh gets a fresh answer while they
     * are still looking at the screen, and long enough that pressing the button four times in a
     * minute - which is what people actually do, and which `does not pile up refreshes` already
     * proves about this button - produces one probe rather than forty requests.
     */
    public const FRESH_FOR_MINUTES = 10;

    public function __construct(private readonly SiteProbe $probe) {}

    /**
     * Look now, whatever was recorded before.
     *
     * The sweep's entry point. It runs once a day on a schedule that has already made the decision
     * this class's other method exists to make.
     */
    public function record(Site $site): ProbeReport
    {
        $reading = $this->probe->probe($site);

        return ProbeReport::query()->create([
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
    }

    /**
     * Look unless somebody has looked recently. Null when it was skipped.
     *
     * The button's entry point, and the thing that keeps `sites.refresh` honest. That route answers
     * in place, and `AsyncActionSurfaceTest` permits that only for routes where performing the
     * action twice is harmless - so the guarantee has to live here, at the last point before an
     * outbound request, rather than in a controller that two concurrent presses both get through.
     *
     * Two checks rather than one, because they fail differently. The stored `probed_at` is durable
     * and survives a cache flush, which is what makes this a real floor rather than a convention.
     * The cache claim is atomic, which closes the window in which two workers both read "no recent
     * report" before either has written one - the same primitive, for the same reason, as the
     * debounce in {@see NudgeSite}.
     */
    public function recordIfStale(Site $site): ?ProbeReport
    {
        if ($this->lookedAtRecently($site)) {
            return null;
        }

        if (! Cache::add("manager:probe:{$site->id}", true, self::FRESH_FOR_MINUTES * 60)) {
            return null;
        }

        return $this->record($site);
    }

    /**
     * Whether a look at this site would be a repeat.
     *
     * Public because the controller asks the same question for a different purpose - it decides what
     * the sentence on the screen says, where this decides whether a request is made. See the note on
     * {@see SiteController::queueProbe()}.
     */
    public function lookedAtRecently(Site $site): bool
    {
        return $site->probeReports()
            ->where('probed_at', '>', Carbon::now()->subMinutes(self::FRESH_FOR_MINUTES))
            ->exists();
    }
}
