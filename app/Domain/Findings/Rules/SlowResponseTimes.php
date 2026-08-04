<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * The site is slow to build its own pages.
 *
 * Reads the 95th percentile rather than the mean, because a mean is the wrong statistic here. A site
 * where nineteen requests take 40ms and the twentieth takes eight seconds has a mean of 440ms, which
 * looks acceptable, and one visitor in twenty waiting eight seconds, which is not.
 *
 * Two honesty constraints on this rule, both of which shape it:
 *
 *  - **This is render time, not what a visitor experiences.** No DNS, no TLS, no queueing in front
 *    of PHP-FPM, no network. A site can pass this rule and still feel slow, so the detail says what
 *    was measured rather than claiming the site is fast or slow in general.
 *  - **The sample is small and local.** Two hundred requests from whatever the site happened to
 *    serve. A crawler hitting an expensive search page all afternoon will move it. So the threshold
 *    is set high enough that only a genuinely slow site trips it, and the rule stays quiet rather
 *    than confidently wrong.
 */
final class SlowResponseTimes implements Rule
{
    /**
     * 95th-percentile render time, in milliseconds, before this is worth saying.
     *
     * Two seconds of PHP, before any network. Deliberately far above "could be quicker" - this is
     * meant to catch a site that has a problem, not to grade sites against each other.
     */
    private const THRESHOLD_MS = 2000.0;

    public function key(): string
    {
        return 'slow_response_times';
    }

    public function requiresCapability(): string
    {
        return 'runtime:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentRuntime()) {
            return null;
        }

        $p95 = $snapshot->runtimeValue('response.p95_ms');
        $samples = (int) $snapshot->runtimeValue('response.samples', 0);

        // The connector will not send a summary below twenty samples, but a rule reading a figure it
        // is about to raise a finding on should check rather than trust.
        if ($p95 === null || $samples < 20 || (float) $p95 < self::THRESHOLD_MS) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::MEDIUM,
            title: 'Pages are slow to build',
            detail: sprintf(
                'One request in twenty took over %s seconds of PHP time, measured across %d requests '
                .'the site was serving anyway (median %s ms). This is server render time only - it '
                .'excludes DNS, TLS, queueing and the network, so what a visitor actually waits is '
                .'longer. Common causes are an uncached template, an N+1 query in an element loop, or '
                .'image transforms being generated on demand.',
                number_format((float) $p95 / 1000, 1),
                $samples,
                number_format((float) $snapshot->runtimeValue('response.p50_ms', 0), 0),
            ),
            evidence: [
                'p95_ms' => (float) $p95,
                'p50_ms' => $snapshot->runtimeValue('response.p50_ms'),
                'samples' => $samples,
                'threshold_ms' => self::THRESHOLD_MS,
            ],
        );
    }
}
