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
 * Receives a runtime report - disk, PHP limits, response timings. Requires `runtime:read`.
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

                // On the refusal as well as on the success, so a connector that guessed too high
                // learns what to send instead from the reply that told it no.
                'accepted' => RuntimeIngestService::SCHEMAS,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $report = $runtime->store($site, $payload);

        /*
         | What this platform understands, on every reply.
         |
         | The connector sends the oldest version until something tells it otherwise, because the
         | two sides are upgraded by different people on different days: whoever runs the platform
         | upgrades it, and each site upgrades its own plugin. A connector that assumed the newer
         | version would have its reports refused by any platform that had not caught up, and a
         | runtime report is fire-and-forget - the only symptom is a Health screen that stops
         | moving, which nobody notices for weeks.
         |
         | One reporting cycle behind, deliberately. A connector learns this from a reply, so it
         | sends the old version once more and upgrades on the next run.
        */
        return response()->json([
            'received' => true,
            'report_id' => $report->id,
            'accepted' => RuntimeIngestService::SCHEMAS,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
