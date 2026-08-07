<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupPartOutOfOrderException;
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
 * A site reporting that it has sent every part.
 *
 * Deliberately not the same endpoint as `backups/{artifact}/uploaded`, which looks like it should be.
 * That one means "the bytes went straight to storage and you never saw them, go and ask the store" -
 * a claim this platform answers by asking somebody other than the site. This one means the opposite:
 * the platform has the bytes, on its own disk, and can check them itself. Folding the two together
 * would collapse a distinction the whole `uploaded` / `stored` split exists to keep.
 *
 * Carries nothing. Every number it could report - how many parts, how many bytes - the platform
 * already has, and would be checking against its own record anyway. What it triggers is the
 * verification: hash the assembled file, compare against the checksum inside the signed manifest, and
 * store only if they match.
 *
 * Idempotent, because a connector that timed out waiting for this response will send it again and must
 * not be told its backup failed.
 */
final class BackupAssembledController
{
    public function __invoke(
        Request $request,
        string $artifactId,
        BackupService $backups,
        BackupTimeline $timeline,
        CorrelationId $correlationId,
    ): JsonResponse {
        /*
         | Hashing an assembled artifact is a pass over a file whose size the operator has already
         | agreed to accept, and there is no number that is both large enough for a twenty-gigabyte
         | one and small enough to be a useful guard. Same reasoning as the upload routes.
        */
        set_time_limit(0);

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
            $assembled = $backups->assembleStaged($artifact);
        } catch (BackupPartOutOfOrderException $e) {
            /*
             | Asked before the last part arrived.
             |
             | Answered with where to continue rather than with a failure, and the artifact is left
             | pending. Everything that did arrive verified against its own hash, so the upload is
             | still perfectly capable of finishing - throwing it away because a connector's
             | arithmetic was off by one would cost somebody a backup for no reason.
            */
            return response()->json([
                'error' => 'part_out_of_order',
                'reason' => $e->getMessage(),
                'resume_from_part' => $e->resumeFromPart,
                'received_bytes' => $e->receivedBytes,
                'correlation_id' => $correlationId->get(),
            ], 409, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        } catch (BackupRejectedException $e) {
            return $this->rejected($e->getMessage(), $correlationId);
        }

        if (! $assembled->isStored()) {
            return $this->rejected(
                $assembled->failure_reason ?? 'the artifact did not verify',
                $correlationId,
            );
        }

        return $this->settled($assembled, $correlationId);
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
