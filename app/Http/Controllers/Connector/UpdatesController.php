<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Updates\UpdatesIngestService;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives an update report. Requires `updates:read`.
 *
 * A payload that fails the allowlist is refused and audited by field path only. The payload itself is
 * never recorded — a report that failed validation is exactly where forbidden content would be.
 */
final class UpdatesController
{
    public function __invoke(
        Request $request,
        UpdatesIngestService $updates,
        AuditRecorder $audit,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $errors = $updates->validate($payload);

        if ($errors !== []) {
            $audit->record(
                action: 'updates.rejected',
                site: $site,
                actorType: AuditEvent::ACTOR_CONNECTOR,
                actorLabel: 'Connector',
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'payload failed the updates allowlist',
                after: ['problems' => array_slice($errors, 0, 20)],
            );

            return response()->json([
                'error' => 'payload_rejected',
                'problems' => $errors,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $report = $updates->store($site, $payload);

        return response()->json([
            'received' => true,
            'report_id' => $report->id,
            'updates' => $report->totalUpdates(),
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
