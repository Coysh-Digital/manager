<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step one of an upload: the connector says what it is about to send. Requires `backups:create`.
 *
 * Separate from the upload itself so that the checksum means something. A single request carrying both
 * the bytes and their claimed hash would have to be read before it could be judged; declaring first
 * means the claim is authenticated, recorded, and then compared against a stream that arrives after it.
 *
 * The response carries the artifact identifier the connector needs for step two. Nothing else — in
 * particular no URL, no token and no storage location, because the connector already knows where to
 * send it and being told would mean it could be told somewhere else.
 */
final class BackupDeclareController
{
    public function __invoke(
        Request $request,
        BackupService $backups,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $jobId = $payload['job_id'] ?? null;

        if (! is_string($jobId) || $jobId === '') {
            return $this->rejected('an artifact must name the job it belongs to', $correlationId);
        }

        // The job has to belong to this site, be a backup job, and be one this connector actually
        // claimed. A declaration against somebody else's job, or against a job that was never issued,
        // is refused — an artifact is only ever accepted as the result of work the platform asked for.
        $job = RemoteJob::query()
            ->where('external_id', $jobId)
            ->where('site_id', $site->id)
            ->where('type', Jobs::BACKUP_CREATE)
            ->where('state', Jobs::STATE_CLAIMED)
            ->first();

        if ($job === null) {
            // Uniform with every other rejection here: no distinction between "no such job", "not your
            // job" and "not claimed", because the difference would tell a caller which job identifiers
            // exist.
            return $this->rejected('that job is not awaiting an artifact', $correlationId);
        }

        try {
            $artifact = $backups->declareArtifact($site, $job, $payload);
        } catch (BackupRejectedException $e) {
            return $this->rejected($e->getMessage(), $correlationId);
        }

        return response()->json([
            'artifact' => $artifact->external_id,

            // Told plainly whether this is a fresh declaration or the one a previous attempt made. A
            // connector retrying after a timeout needs to know it should not dump the database again.
            'already_declared' => ! $artifact->isPending(),
            'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }

    private function rejected(string $reason, CorrelationId $correlationId): JsonResponse
    {
        return response()->json([
            'error' => 'artifact_rejected',
            'reason' => $reason,
            'correlation_id' => $correlationId->get(),
        ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
