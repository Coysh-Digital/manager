<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

final class FailedQueueJobs implements Rule
{
    public function key(): string
    {
        return 'failed_queue_jobs';
    }

    public function category(): string
    {
        return RuleCategory::OPERATIONAL;
    }

    public function requiresCapability(): string
    {
        return 'system:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        $failed = (int) $snapshot->inventoryValue('queue.failed', 0);

        if ($failed === 0) {
            return null;
        }

        return new RuleMatch(
            // Low: a failed job is usually one email, not an exposure. Reported so it does not
            // accumulate unseen for months, which is how a broken integration goes unnoticed.
            severity: Severity::LOW,
            title: $failed === 1 ? 'A queue job has failed' : "{$failed} queue jobs have failed",
            detail: 'Something the site queued did not complete. Manager reports the count only - the '
                .'job payloads are not read, because they would carry site content. Check the queue in '
                .'the control panel.',
            evidence: ['failed' => $failed],
        );
    }
}
