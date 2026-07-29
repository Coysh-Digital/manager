<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Health\Diagnostics;
use Illuminate\Http\JsonResponse;

/**
 * Readiness for orchestrators.
 *
 * Liveness is Laravel's own /up, which answers "is PHP running". This answers the different and
 * more useful question: "can this instance actually serve a request".
 *
 * The body names which check failed but carries no configuration values, because a readiness
 * endpoint is usually reachable from wherever the load balancer is and should not be a way to read
 * the environment.
 */
final class HealthController
{
    public function ready(Diagnostics $diagnostics): JsonResponse
    {
        $checks = $diagnostics->readiness();

        $failed = array_values(array_filter($checks, fn ($check): bool => $check->failed()));

        return response()->json([
            'ready' => $failed === [],
            'checks' => array_map(fn ($check): array => [
                'name' => $check->name,
                'status' => $check->status,
            ], $checks),
        ], $failed === [] ? 200 : 503);
    }
}
