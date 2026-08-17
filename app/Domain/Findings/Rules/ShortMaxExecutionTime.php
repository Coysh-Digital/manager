<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A site whose long jobs are running under a web request's clock.
 *
 * Craft runs its queue over HTTP unless somebody has arranged otherwise, which means the connector's
 * own work - taking a database dump, encrypting it, uploading it in parts - happens inside an
 * ordinary web request and inherits `max_execution_time` from it. On the PHP default of thirty
 * seconds a backup of anything but a small database is killed part way through, and the symptom is a
 * job that fails at a different point each night with no error anybody can read.
 *
 * ## Why the SAPI decides whether this fires
 *
 * The runtime report is built by the connector's scheduler, which runs in the queue - so the SAPI in
 * the report *is* the SAPI the queue ran under. A site whose queue runs from cron reports `cli`,
 * where the limit is conventionally zero and irrelevant, and this rule stays silent. A site
 * reporting `fpm-fcgi` or `apache2handler` is telling us its queue runs over HTTP, and the limit is
 * then the real ceiling on every long job it does.
 *
 * That makes this rule unusually well evidenced for something that looks like a configuration
 * preference. It is not "your `max_execution_time` is low"; it is "the process that takes your
 * backups reported in from a web request, and web requests here are cut off after N seconds".
 *
 * ## Why the threshold is sixty rather than something more cautious
 *
 * Ninety and a hundred and twenty are extremely common values, and a rule firing on them would be
 * amber on a large share of a fleet on day one - which is how a finding stops being read. Sixty is
 * low enough that everything below it is either the PHP default nobody changed or a deliberate
 * tightening somebody did not think about backups when making.
 *
 * Zero is unlimited, and a real setting rather than a missing one.
 */
final class ShortMaxExecutionTime implements Rule
{
    /**
     * Seconds below which a queue running over HTTP is a real risk to a backup.
     */
    private const THRESHOLD_SECONDS = 60;

    /**
     * SAPIs where the limit does not apply in practice.
     *
     * Matched as a prefix, because the CLI server reports `cli-server` and phpdbg reports `phpdbg` —
     * neither of which is a web request being served to visitors.
     *
     * @var list<string>
     */
    private const UNAFFECTED_SAPIS = ['cli', 'phpdbg', 'embed'];

    public function key(): string
    {
        return 'short_max_execution_time';
    }

    public function category(): string
    {
        return RuleCategory::OPERATIONAL;
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

        $sapi = $snapshot->runtimeValue('php.sapi');
        $seconds = $snapshot->runtimeValue('php.max_execution_time');

        // Both are needed. Without the SAPI this cannot tell a site whose queue runs from cron - where
        // the limit is irrelevant - from one where it is the ceiling on every backup, and reporting
        // the first would be noise on exactly the sites that got it right.
        if (! is_string($sapi) || ! is_int($seconds)) {
            return null;
        }

        if ($this->unaffected($sapi)) {
            return null;
        }

        // Zero is unlimited. A real setting, and the one this rule is asking people to move towards.
        if ($seconds <= 0 || $seconds >= self::THRESHOLD_SECONDS) {
            return null;
        }

        return new RuleMatch(
            severity: Severity::MEDIUM,
            title: 'Long jobs on this site are cut off after '.$seconds.' seconds',
            detail: sprintf(
                'This site reported in from a %s request rather than from the command line, which '
                .'means its queue runs over HTTP - so taking a backup, running an update or applying '
                .'project config all happen inside a web request and are killed at %d seconds. A '
                .'database large enough to take longer than that will fail at a different point each '
                .'night, having already been dumped and encrypted, with nothing in the log that '
                .'reads as a timeout. Raise max_execution_time, or run the queue from cron with '
                .'`php craft queue/listen`, which takes it out of the web request entirely.',
                $sapi,
                $seconds,
            ),
            evidence: [
                'max_execution_time' => $seconds,
                'sapi' => $sapi,
                'threshold_seconds' => self::THRESHOLD_SECONDS,
            ],
        );
    }

    private function unaffected(string $sapi): bool
    {
        foreach (self::UNAFFECTED_SAPIS as $prefix) {
            if (str_starts_with(strtolower($sapi), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
