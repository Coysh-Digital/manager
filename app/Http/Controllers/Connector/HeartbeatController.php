<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Models\Connector;
use App\Models\Heartbeat;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records that a site is alive.
 *
 * Carries no site data and needs no capability: it is inherent to being paired, and reporting
 * anything beyond liveness here would be collection without a stated purpose.
 *
 * The response is unsigned because it contains no commands and no security-sensitive
 * configuration. Signing everything indiscriminately would train connectors to treat a signature
 * as decoration rather than as something to check.
 */
final class HeartbeatController
{
    public function __invoke(Request $request, CorrelationId $correlationId): JsonResponse
    {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        /** @var Connector $connector */
        $connector = $request->attributes->get('manager.connector');

        $now = Carbon::now();

        // Both writes or neither, so a site can never appear to have checked in without a
        // corresponding record of it having done so.
        DB::transaction(function () use ($site, $connector, $request, $correlationId, $now): void {
            Heartbeat::query()->create([
                'site_id' => $site->id,
                'connector_version' => $connector->connector_version,
                'source_ip' => $request->ip(),
                'correlation_id' => $correlationId->get(),
                'received_at' => $now,
            ]);

            $site->forceFill([
                'last_seen_at' => $now,
                'status' => Site::STATUS_CONNECTED,
            ])->save();

            $connector->forceFill(['last_seen_at' => $now])->save();
        });

        return response()->json([
            'received' => true,
            'server_time' => $now->getTimestamp(),
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
