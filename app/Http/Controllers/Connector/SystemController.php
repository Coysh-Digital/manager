<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Runtime\RuntimeIngestService;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives a runtime report — disk, PHP limits, response timings. Requires `runtime:read`.
 *
 * A payload that fails the allowlist is refused and audited by field path only. The payload itself
 * is never recorded: a report that failed validation is precisely where a filesystem path would be.
 */
final class SystemController
{
    public function __invoke(
        Request $request,
        RuntimeIngestService $runtime,
        AuditRecorder $audit,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $errors = $runtime->validate($payload);

        if ($errors !== []) {
            $audit->record(
                action: 'runtime.rejected',
                site: $site,
                actorType: AuditEvent::ACTOR_CONNECTOR,
                actorLabel: 'Connector',
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'payload failed the system allowlist',
                after: ['problems' => array_slice($errors, 0, 20)],
            );

            return response()->json([
                'error' => 'payload_rejected',
                'problems' => $errors,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $report = $runtime->store($site, $payload);

        return response()->json([
            'received' => true,
            'report_id' => $report->id,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
