<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Step two: the bytes. Requires `backups:create`.
 *
 * By the time this runs the request has been authenticated by signature, and the signature covered a
 * hash of the body declared in a header — so the body has not been read yet, and does not need to be
 * trusted. It is streamed straight past, hashed on the way, and only committed if the hash matches both
 * the header the signature covered and the checksum recorded at the declare step.
 *
 * The body is taken as a stream, never as a string. Asking the framework for the content the ordinary
 * way would hand over the whole thing at once, which for a database backup means holding a customer's
 * entire database in memory.
 */
final class BackupUploadController
{
    public function __invoke(
        Request $request,
        string $artifactId,
        BackupService $backups,
        CorrelationId $correlationId,
    ): JsonResponse {
        /*
         | No time limit on this request, and only on this request.
         |
         | Everything else here should finish in a second and a runaway one should be cut off, so the
         | ceiling stays where it is globally — the shipped image sets sixty seconds. This route is
         | different in kind: it is bounded by how fast a customer's uplink can move a file whose size
         | the operator has already agreed to accept, and there is no number that is both large enough
         | for a slow twenty-gigabyte upload and small enough to be a useful guard.
         |
         | Safe because nothing here does work per byte beyond hashing. The body is streamed past and
         | written out, never held, so a long request is a slow network rather than a process
         | accumulating anything.
         */
        set_time_limit(0);

        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $artifact = BackupArtifact::query()
            ->where('external_id', $artifactId)
            ->where('site_id', $site->id)
            ->first();

        if ($artifact === null) {
            return $this->rejected('that artifact is not awaiting bytes', $correlationId, 404);
        }

        // The hash the signature covered. Compared against what the declare step recorded before a
        // single byte is read: if a connector signed a different hash from the one it declared, the two
        // requests disagree and neither should be believed.
        //
        // Which hash that is depends on the format, and it is asked of the artifact rather than named
        // here. A v1 artifact uploads a bare encrypted stream; a v2 artifact uploads an envelope
        // wrapped around one, so its ciphertext hash covers only part of the file. Comparing against
        // the wrong one either rejects every valid upload or — worse — accepts a file whose manifest
        // had been replaced wholesale.
        $signedHash = (string) $request->attributes->get('manager.content_sha256', '');

        if (! hash_equals($artifact->expectedUploadSha256(), $signedHash)) {
            return $this->rejected(
                'the signed content hash does not match the declared artifact',
                $correlationId,
            );
        }

        // Symfony hands back php://input for a real request, so nothing is buffered on the way in.
        $input = $request->getContent(asResource: true);

        if (! is_resource($input)) {
            throw new RuntimeException('Could not open the request body for reading.');
        }

        try {
            $stored = $backups->storeArtifact($artifact, $input);
        } catch (BackupRejectedException $e) {
            return $this->rejected($e->getMessage(), $correlationId);
        } finally {
            fclose($input);
        }

        if (! $stored->isStored()) {
            return $this->rejected(
                $stored->failure_reason ?? 'the artifact did not verify',
                $correlationId,
            );
        }

        return response()->json([
            'stored' => true,
            'artifact' => $stored->external_id,
            'expires_at' => $stored->expires_at?->timestamp,
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
