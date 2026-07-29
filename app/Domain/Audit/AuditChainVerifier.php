<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use Illuminate\Support\Collection;

/**
 * Walks an organisation's audit chain and reports where it stops adding up.
 *
 * The chain does not prevent tampering — the database trigger and table privileges do that. What
 * it provides is detection: anyone who does get around both still cannot alter history without
 * leaving a break that this finds.
 */
final class AuditChainVerifier
{
    /**
     * Verify one organisation's chain, or the platform chain when given null.
     */
    public function verify(?int $organisationId): AuditChainResult
    {
        $problems = [];
        $expectedPrevious = AuditEvent::GENESIS_HASH;
        $expectedSeq = 1;
        $checked = 0;

        AuditEvent::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('seq')
            ->chunk(500, function (Collection $events) use (&$problems, &$expectedPrevious, &$expectedSeq, &$checked): void {
                /** @var AuditEvent $event */
                foreach ($events as $event) {
                    $checked++;

                    if ($event->seq !== $expectedSeq) {
                        // A gap means an event was removed, or one was never written. Either way
                        // the history is no longer complete.
                        $problems[] = "Expected sequence {$expectedSeq} but found {$event->seq} (event #{$event->id}).";
                        $expectedSeq = $event->seq;
                    }

                    if ($event->prev_hash !== $expectedPrevious) {
                        $problems[] = "Event #{$event->id} does not follow its predecessor.";
                    }

                    // array_merge, not "+". The union operator keeps the left-hand value for any
                    // duplicate key, which would leave before/after as the raw JSON strings from
                    // the database rather than the decoded arrays that were hashed on the way in —
                    // and every event carrying a payload would be reported as altered.
                    $recomputed = AuditRecorder::hashFor(array_merge($event->getAttributes(), [
                        'before' => $event->before,
                        'after' => $event->after,
                        'created_at' => $event->created_at,
                    ]));

                    if (! hash_equals($event->hash, $recomputed)) {
                        $problems[] = "Event #{$event->id} has been altered since it was written.";
                    }

                    $expectedPrevious = $event->hash;
                    $expectedSeq++;
                }
            });

        return new AuditChainResult($organisationId, $checked, $problems);
    }
}
