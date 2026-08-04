<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\ObjectStore;
use App\Domain\Audit\AuditRecorder;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands over an artifact's ciphertext, and only ever its ciphertext.
 *
 * The backups screen had no download button for a long time, and the reasoning was sound as far as it
 * went: decrypting a multi-gigabyte artifact inside a web request means holding a worker against a
 * timeout it will probably lose, and leaving a half-written file that cannot be told apart from a
 * complete one. That argument is about *decryption*, and it still holds - there is no route here or
 * anywhere else that decrypts through a browser, and `manager:backups:fetch` remains how a v1 artifact
 * is turned into SQL.
 *
 * What the argument never covered is handing over the bytes exactly as they are stored. A v2 artifact
 * is sealed to the organisation's own recovery keys, so this platform cannot read it either; the
 * console says so plainly and tells the customer to run `manager-restore decrypt`. Until this route
 * existed it gave them no way to obtain the file that command operates on. On a self-hosted
 * installation that was an inconvenience, because the operator owns the disk. On Cloud the bucket
 * belongs to us, so the one feature whose entire purpose is that we cannot read a customer's backup
 * left that customer unable to read it either, without an operator opening an SSH session for them.
 *
 * So: ciphertext, streamed or redirected, never decrypted.
 */
final class BackupDownloadController
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(
        Request $request,
        BackupArtifact $artifact,
        Organisation $organisation,
    ): RedirectResponse|StreamedResponse {
        abort_if($artifact->organisation_id !== $organisation->id, 404);

        /*
         | Administrators, not owners alone.
         |
         | Deleting a backup and enrolling a recovery key are both owner-level, and it is worth saying
         | why this is not. Those two decide, respectively, whether a backup continues to exist and who
         | can ever read one. This decides neither. What leaves here is ciphertext: for a v2 artifact
         | nothing on this platform can open it, and for a v1 artifact the key is wrapped by a service
         | an administrator has no path to. An administrator can already ask a production site for a
         | fresh dump, which is a strictly larger capability than fetching one that already exists.
        */
        abort_unless(app(Membership::class)->canAdminister(), 403);

        // Covers deleted, expired and still-uploading in one question, and answers all three the same
        // way. Which of them it was is on the screen the caller came from.
        abort_unless($artifact->isRetrievable(), 404);

        $key = (string) $artifact->storage_key;
        $url = $this->store->temporaryUrl($key, self::URL_SECONDS);

        $this->audit->record(
            action: 'backup.ciphertext_downloaded',
            site: $artifact->site,
            actor: $request->user(),
            targetType: 'backup_artifact',
            targetId: $artifact->external_id,
            after: [
                'artifact_bytes' => $artifact->expectedUploadBytes(),
                'zero_knowledge' => $artifact->isZeroKnowledge(),

                // Whether the bytes travelled through this platform or straight from the store. Worth
                // recording because the two have different blast radii if anything is ever questioned,
                // and because a Cloud installation quietly falling back to streaming is a fault.
                'delivery' => $url !== null ? 'redirect' : 'stream',
            ],
            request: $request,
        );

        if ($url !== null) {
            // The browser fetches from the store directly: resumable, no worker held, and nothing of
            // ours touching a customer's database on the way past.
            return redirect()->away($url);
        }

        return $this->stream($artifact, $key);
    }

    /**
     * The fallback, for a store that cannot sign a URL - a local volume, typically.
     */
    private function stream(BackupArtifact $artifact, string $key): StreamedResponse
    {
        $handle = $this->store->readStream($key);

        /*
         | No time limit, for the same reason the upload route has none: this is bounded by how fast a
         | connection can move a file whose size is already known, and there is no number that is both
         | large enough for a slow transfer and small enough to be a useful guard. Nothing here does
         | work per byte, so a long request is a slow network rather than a process accumulating
         | anything.
        */
        set_time_limit(0);

        return response()->streamDownload(
            function () use ($handle): void {
                while (! feof($handle)) {
                    $chunk = fread($handle, Protocol::ARTIFACT_CHUNK_BYTES);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    flush();
                }

                fclose($handle);
            },
            $artifact->external_id.'.artifact',
            [
                'Content-Type' => 'application/octet-stream',

                // Taken from the declaration rather than by asking the store, which would be a second
                // round trip to learn something already recorded. It is also what gives the browser a
                // progress bar, which on a multi-gigabyte file is the difference between waiting and
                // assuming it has hung.
                'Content-Length' => (string) $artifact->expectedUploadBytes(),
            ],
        );
    }

    /**
     * How long a signed URL lives.
     *
     * Long enough to survive a slow click and a redirect, short enough that a link left in browser
     * history or a proxy log is not a way back to a customer's encrypted database. The transfer
     * itself is not bounded by this - S3 checks the signature when the request opens, not throughout.
     */
    private const URL_SECONDS = 300;
}
