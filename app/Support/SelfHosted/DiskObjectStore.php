<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\ObjectStore;
use App\Domain\Backup\BackupService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Artifact storage on a Laravel filesystem disk.
 *
 * One implementation covers both self-hosted arrangements, because Flysystem already abstracts them:
 * a local volume for a single-server installation, or an S3-compatible bucket - MinIO, Backblaze, S3
 * itself - configured through the same disk. That is the whole reason to go through a disk rather than
 * an SDK.
 *
 * The disk is named in configuration and never derived from a request. A key is always built by
 * {@see BackupService} from identifiers the platform generated, so nothing a
 * connector sends reaches a path.
 */
final class DiskObjectStore implements ObjectStore
{
    public function put(string $key, $stream): int
    {
        // writeStream rather than put: an artifact can be gigabytes, and put() would read it into
        // memory first.
        if (! $this->disk()->writeStream($key, $stream)) {
            throw new RuntimeException('Could not write the artifact to storage.');
        }

        return $this->bytes($key);
    }

    public function readStream(string $key)
    {
        $stream = $this->disk()->readStream($key);

        if ($stream === null) {
            // Deliberately does not repeat the key. A caller asking for something that is not there is
            // told that, not told where we looked.
            throw new RuntimeException('That artifact is no longer in storage.');
        }

        return $stream;
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    public function delete(string $key): bool
    {
        if (! $this->disk()->exists($key)) {
            return false;
        }

        return $this->disk()->delete($key);
    }

    public function bytes(string $key): int
    {
        return $this->disk()->size($key);
    }

    /**
     * Whether this disk can hand the browser a URL of its own.
     *
     * The two arrangements this one class covers genuinely differ here, which is the reason the
     * contract allows null. A local volume has no URL to give and never will, so downloads stream
     * through the application - correct, but a worker held for the length of the transfer. A disk
     * pointed at MinIO, Backblaze or S3 itself can sign one, and then the browser fetches the bytes
     * directly and the application is not involved past the redirect. That is not a Cloud-only
     * capability and it would be wrong to make an operator pay for their own object store twice.
     *
     * The local driver is excluded deliberately, and it is worth saying why, because it will happily
     * answer. Laravel signs a URL to a route it serves itself, which looks like the same thing and is
     * not: it ignores the response headers passed below, so the browser saves a customer's artifact
     * under whatever the path implies instead of the name `manager-restore` expects to be handed, and
     * it serves the bytes from outside this application's authorisation and audit with the signature
     * as the only guard. Streaming through the download route is slower and correct. This was found
     * by a test asserting a local disk gives no URL - which, before this check, it did.
     */
    public function temporaryUrl(string $key, int $seconds): ?string
    {
        $name = (string) config('manager.backups.disk');

        if ((string) config("filesystems.disks.{$name}.driver") !== 's3') {
            return null;
        }

        $disk = $this->disk();

        if (! $disk instanceof FilesystemAdapter || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        return $disk->temporaryUrl($key, now()->addSeconds($seconds), [
            'ResponseContentType' => 'application/octet-stream',

            // The key's final segment is the artifact's own identifier, so this is the name
            // `manager-restore` expects to be handed. A redirect leaves nowhere to set it later.
            'ResponseContentDisposition' => 'attachment; filename="'.basename($key).'"',
        ]);
    }

    public function describe(): string
    {
        $disk = (string) config('manager.backups.disk');

        // The driver and the disk name, never the bucket credentials or a signed URL.
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'unknown');

        return "{$disk} ({$driver})";
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('manager.backups.disk'));
    }
}
