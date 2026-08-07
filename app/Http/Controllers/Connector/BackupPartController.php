<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupPartOutOfOrderException;
use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Step two, in pieces: one part of the bytes. Requires `backups:create`.
 *
 * The same artifact {@see BackupUploadController} receives whole, arriving over several requests
 * instead of one. Nothing about the file changes - same envelope, same manifest, same signature, same
 * thing `manager-restore` opens. Only the number of requests does, and that is the point: a request
 * carrying an entire database is a request long enough for a proxy or a php-fpm pool to end, and when
 * one does the site is handed somebody else's HTML error page with nothing in this application's log
 * to match it against.
 *
 * **What the signature proves here is narrower than on the whole-file route, and it is worth being
 * exact about.** There, the hash the signature covered is compared against the checksum recorded at
 * the declare step before a byte is read: the two requests have to agree. A part has no pre-declared
 * hash - the declaration commits to the whole file, not to any slicing of it - so the signed
 * `Manager-Content-Sha256` proves only that these bytes are the bytes this site signed, which is a
 * transport check.
 *
 * The binding check is the whole-file SHA-256 from inside the signed manifest, and it is made once,
 * by {@see BackupService::assembleStaged()}, before anything reaches storage. A part that verified
 * against its own hash and then does not fit is caught there.
 *
 * The part number is in the request path, and the signature covers the path, so a captured part
 * cannot be replayed at a different offset either.
 */
final class BackupPartController
{
    public function __invoke(
        Request $request,
        string $artifactId,
        int $part,
        BackupService $backups,
        CorrelationId $correlationId,
    ): JsonResponse {
        /*
         | No time limit, for the reason {@see BackupUploadController} gives at length - though it
         | matters far less here. A part is bounded by the configured part size rather than by the
         | size of somebody's database, so this request is short by construction. Kept because the
         | limit that would bite is php-fpm's, which this cannot lift and cannot see.
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

        if ($part < 1) {
            return $this->rejected('parts are numbered from one', $correlationId);
        }

        if ($artifact->isStored()) {
            /*
             | Already finished. A connector that lost the response to `assembled` and went back to
             | re-sending parts is told it is done, rather than being told its backup failed - which
             | is the same reasoning that makes `assembled` itself idempotent.
            */
            return response()->json([
                'received_bytes' => (int) $artifact->expectedUploadBytes(),
                'next_part' => null,
            ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        /*
         | The length, before the body is read.
         |
         | The whole-file route can compare the signed hash against the checksum recorded at declare
         | before it reads anything. There is no equivalent for a part, because the declaration commits
         | to the whole file and not to any slicing of it - so the pre-read guard here is the exact
         | length this part must be, which the platform can compute and the middleware has already
         | required a `Content-Length` for. It is a tighter guard than the whole-file route's, which
         | only checks against a ceiling.
        */
        $expectedLength = $this->lengthFor($artifact, $part);

        if ($expectedLength === null) {
            return $this->rejected('that artifact was not declared for upload in parts', $correlationId);
        }

        if ((int) $request->server('CONTENT_LENGTH') !== $expectedLength) {
            return $this->rejected(
                "part {$part} of that artifact is {$expectedLength} bytes",
                $correlationId,
            );
        }

        $signedHash = (string) $request->attributes->get('manager.content_sha256', '');

        /*
         | One part at a time per artifact.
         |
         | A connector whose client timed out and retried while the first attempt is still writing
         | would otherwise have two requests seeking to the same offset in the same file. The loser
         | gets a 409 and retries, which is what it was going to do anyway.
         |
         | A cache lock rather than a database one: this is held across a body read, and holding a
         | transaction open for the length of an upload turns a slow client into an exhausted
         | connection pool. The store behind it is the same shared, atomic one replay protection
         | already requires.
        */
        $lock = Cache::lock('backup-part:'.$artifact->external_id, 3600);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'part_in_flight',
                'reason' => 'another part of that artifact is being received',
                'correlation_id' => $correlationId->get(),
            ], 409, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        // Symfony hands back php://input for a real request, so nothing is buffered on the way in.
        $input = $request->getContent(asResource: true);

        if (! is_resource($input)) {
            $lock->release();

            throw new RuntimeException('Could not open the request body for reading.');
        }

        try {
            $progress = $backups->stagePart($artifact, $part, $input, $signedHash);
        } catch (BackupPartOutOfOrderException $e) {
            /*
             | 409 rather than 422, and carrying somewhere to go.
             |
             | The artifact is fine and the upload is not over: the connector has simply lost its place,
             | which is what a dropped connection mid-part looks like from here. Answering with the part
             | to resume from turns that into one repeated part instead of a repeated database.
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
        } finally {
            fclose($input);
            $lock->release();
        }

        return response()->json([
            'received_bytes' => $progress['received_bytes'],
            'next_part' => $progress['next_part'],
        ], headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
    }

    /**
     * How many bytes this part must carry, or null when this artifact takes no parts.
     *
     * Every part but the last is a full part; the last is whatever is left over. Computed from the
     * size pinned onto the artifact at declare rather than from configuration, so that changing the
     * setting cannot move the boundaries of an upload already under way.
     */
    private function lengthFor(BackupArtifact $artifact, int $part): ?int
    {
        $partBytes = $artifact->ingest_part_bytes;

        if ($partBytes === null || $partBytes < 1) {
            return null;
        }

        $remaining = $artifact->expectedUploadBytes() - (($part - 1) * $partBytes);

        return $remaining > 0 ? (int) min($partBytes, $remaining) : null;
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
