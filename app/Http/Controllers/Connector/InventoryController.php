<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\InventoryIngestService;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives an operational-metadata report.
 *
 * Requires `inventory:read`, checked by middleware before this runs.
 *
 * A payload that fails the allowlist is refused and audited, but the payload itself is never
 * recorded anywhere — a report that failed validation is exactly where forbidden data would be if
 * a connector were misbehaving, so writing it to the log to help debugging would defeat the point
 * of rejecting it. The field paths are enough to fix a connector; the values are not needed.
 */
final class InventoryController
{
    public function __invoke(
        Request $request,
        InventoryIngestService $inventory,
        AuditRecorder $audit,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $errors = $inventory->validate($payload);

        if ($errors !== []) {
            $audit->record(
                action: 'inventory.rejected',
                site: $site,
                actorType: AuditEvent::ACTOR_CONNECTOR,
                actorLabel: 'Connector',
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'payload failed the inventory allowlist',
                // Paths only. The validator is written never to quote a rejected value, precisely
                // so that its output is safe to store here.
                after: ['problems' => array_slice($errors, 0, 20)],
            );

            return response()->json([
                'error' => 'payload_rejected',
                'problems' => $errors,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $report = $inventory->store($site, $payload);

        return response()->json([
            'received' => true,
            'report_id' => $report->id,
            'schema_version' => $report->schema_version,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
