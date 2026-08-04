<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Logins\LoginsIngestService;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives counts of failed control-panel sign-ins. Requires `logins:read`.
 *
 * The rejection path matters more here than on the other endpoints. `logins.v1` permits four
 * integers and a timestamp; a payload carrying a username is refused rather than stripped, and the
 * refusal records the offending field paths and nothing else - recording the payload of a report
 * that failed this particular allowlist would store exactly the thing the allowlist exists to keep
 * out.
 */
final class LoginsController
{
    public function __invoke(
        Request $request,
        LoginsIngestService $logins,
        AuditRecorder $audit,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $errors = $logins->validate($payload);

        if ($errors !== []) {
            $audit->record(
                action: 'logins.rejected',
                site: $site,
                actorType: AuditEvent::ACTOR_CONNECTOR,
                actorLabel: 'Connector',
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'payload failed the logins allowlist',
                after: ['problems' => array_slice($errors, 0, 20)],
            );

            return response()->json([
                'error' => 'payload_rejected',
                'problems' => $errors,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $report = $logins->store($site, $payload);

        return response()->json([
            'received' => true,
            'report_id' => $report->id,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
