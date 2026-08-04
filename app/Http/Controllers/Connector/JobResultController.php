<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Models\Connector;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * A connector reporting how a job went.
 *
 * The result is validated against the job definition's result schema. A connector cannot report a
 * shape the platform never asked for, which matters for the same reason inventory reports are
 * validated: it is the boundary that stops a compromised site sending whatever it likes.
 *
 * The response is unsigned. It carries no instruction - only an acknowledgement - and signing
 * everything indiscriminately trains a connector to treat a signature as decoration.
 */
final class JobResultController
{
    public function __invoke(
        Request $request,
        string $job,
        JobService $jobs,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        /** @var Connector $connector */
        $connector = $request->attributes->get('manager.connector');

        $validated = $request->validate([
            'succeeded' => ['required', 'boolean'],
            'result' => ['nullable', 'array'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $recorded = $jobs->report(
                site: $site,
                connector: $connector,
                jobExternalId: $job,
                succeeded: (bool) $validated['succeeded'],
                result: $validated['result'] ?? [],
                failureReason: $validated['failure_reason'] ?? null,
            );
        } catch (JobRejectedException $e) {
            Log::info('Job result rejected.', [
                'correlation_id' => $correlationId->get(),
                'reason' => $e->reason,
            ]);

            return response()->json([
                'error' => 'result_rejected',
                'reason' => $e->reason,
                // Field paths, never values. Useful for fixing a connector, useless for extracting
                // anything.
                'problems' => $e->problems,
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        return response()->json([
            'recorded' => true,
            'state' => $recorded->state,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
