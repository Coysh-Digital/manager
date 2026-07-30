<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\ObjectStore;
use App\Domain\Backup\BackupService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Artifact storage on a Laravel filesystem disk.
 *
 * One implementation covers both self-hosted arrangements, because Flysystem already abstracts them:
 * a local volume for a single-server installation, or an S3-compatible bucket — MinIO, Backblaze, S3
 * itself — configured through the same disk. That is the whole reason to go through a disk rather than
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
