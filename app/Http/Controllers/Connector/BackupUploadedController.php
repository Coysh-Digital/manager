<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\BackupTimeline;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A site reporting that it finished writing an artifact straight to storage.
 *
 * Only reachable on the direct path. When bytes stream through the platform there is nothing to report
 * - they were hashed on the way past and the artifact was stored and verified in the same instant.
 *
 * The important thing about this endpoint is how little it is trusted. It does not carry a checksum, a
 * size, or anything else the platform could act on; a connector saying "done" only causes the platform
 * to go and ask the storage service, which is the party that actually saw the bytes. That is what keeps
 * `uploaded` and `stored` genuinely different states rather than two names for a connector's word.
 *
 * Idempotent, because a connector that timed out waiting for this response will send it again and must
 * not be told its backup failed.
 */
final class BackupUploadedController
{
    public function __invoke(
        Request $request,
        string $artifactId,
        BackupService $backups,
        BackupTimeline $timeline,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $artifact = BackupArtifact::query()
            ->where('external_id', $artifactId)
            ->where('site_id', $site->id)
            ->first();

        if ($artifact === null) {
            // Scoped to the reporting site, so one site cannot settle another's artifact. Uniform 404
            // rather than a distinction between "no such artifact" and "not yours", which would tell a
            // caller which identifiers exist.
            return $this->rejected('that artifact is not awaiting confirmation', $correlationId, 404);
        }

        if ($artifact->isStored()) {
            return $this->settled($artifact, $correlationId);
        }

        $timeline->connector(
            event: BackupEvent::UPLOAD_COMPLETED,
            site: $site,
            artifact: $artifact,
        );

        try {
            $confirmed = $backups->confirmDirectUpload($artifact);
        } catch (BackupRejectedException $e) {
            return $this->rejected($e->getMessage(), $correlationId);
        }

        if (! $confirmed->isStored()) {
            return $this->rejected(
                $confirmed->failure_reason ?? 'the artifact did not verify',
                $correlationId,
            );
        }

        return $this->settled($confirmed, $correlationId);
    }

    private function settled(BackupArtifact $artifact, CorrelationId $correlationId): JsonResponse
    {
        return response()->json([
            'stored' => true,
            'artifact' => $artifact->external_id,
            'expires_at' => $artifact->expires_at?->timestamp,
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }

    private function rejected(string $reason, CorrelationId $correlationId, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => 'artifact_rejected',
            'reason' => $reason,
            'correlation_id' => $correlationId->get(),
        ], $status, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }
}
