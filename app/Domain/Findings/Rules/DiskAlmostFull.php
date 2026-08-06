<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * The disk is running out.
 *
 * The most boring cause of a total outage there is, and the one nobody looks at until it happens.
 * A full disk on a Craft site does not degrade gracefully: sessions stop writing, the queue stops
 * draining, image transforms fail, logs stop, and the database - if it shares the volume - stops
 * accepting writes. All at once, at whatever hour the last gigabyte went.
 *
 * Two thresholds rather than one, because the useful moment to say something is well before the
 * useful moment to panic. Ninety per cent is "sort this out this week"; ninety-seven is "today".
 */
final class DiskAlmostFull implements Rule
{
    private const WARN_PERCENT = 90.0;

    private const CRITICAL_PERCENT = 97.0;

    public function key(): string
    {
        return 'disk_almost_full';
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
        // Disk usage from last month is not evidence of anything. A stale report is treated as no
        // report rather than as a reading.
        if (! $snapshot->hasRecentRuntime()) {
            return null;
        }

        $used = $snapshot->runtime?->diskUsedPercent();

        // Null where the filesystem could not answer - common on containerised and remote storage.
        // "We could not measure it" is not "it is fine", but it is also not a finding.
        if ($used === null || $used < self::WARN_PERCENT) {
            return null;
        }

        $free = $snapshot->runtime?->disk_free_bytes;

        return new RuleMatch(
            severity: $used >= self::CRITICAL_PERCENT ? Severity::CRITICAL : Severity::HIGH,
            title: $used >= self::CRITICAL_PERCENT
                ? 'The disk is nearly full'
                : 'The disk is filling up',
            detail: sprintf(
                'The volume holding this site is %s%% full%s. A Craft site on a full disk does not '
                .'degrade gently: sessions stop writing, the queue stops draining, transforms fail '
                .'and - if the database shares the volume - writes stop altogether. Clear old logs, '
                .'caches and backups, or add space.',
                $used,
                $free === null ? '' : sprintf(', with %s GB free', number_format($free / 1073741824, 1)),
            ),
            evidence: [
                'disk_used_percent' => $used,
                'disk_free_bytes' => $free,
                'storage_bytes' => $snapshot->runtime?->storage_bytes,
            ],
        );
    }
}
