<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Backup\BackupService;

/**
 * Where backup artifacts live.
 *
 * The seam between the two editions. Self-hosted writes to a configured filesystem disk, which may be
 * a local volume or an S3-compatible bucket the operator runs; Cloud writes to per-organisation
 * storage. The domain never knows which, and never handles a whole artifact in memory: everything here
 * takes or returns a stream.
 *
 * Deliberately small. There is no list, no copy, no move and no signed-URL method, because the backup
 * pipeline needs none of them and each would be one more thing an attacker who reached this interface
 * could do. In particular there is no way to ask for a public URL to an artifact: retrieval goes
 * through the platform, where it can be authorised and audited.
 */
interface ObjectStore
{
    /**
     * Write a stream to a key, returning the number of bytes stored.
     *
     * Overwrites. The caller is responsible for generating a key it does not mind overwriting — see
     * how {@see BackupService} derives one from the artifact's own identifier.
     *
     * @param  resource  $stream
     */
    public function put(string $key, $stream): int;

    /**
     * Open a stored artifact for reading.
     *
     * @return resource
     *
     * @throws \RuntimeException if the key does not exist
     */
    public function readStream(string $key);

    public function exists(string $key): bool;

    /**
     * Remove an artifact.
     *
     * Returns false when the key was already absent rather than throwing: retention deleting something
     * twice is not an error, and a failure to notice that would leave a record undeleted.
     */
    public function delete(string $key): bool;

    public function bytes(string $key): int;

    /**
     * A short-lived URL a browser can fetch an object from directly, or null if this store has none.
     *
     * Null is an ordinary answer, not a failure: a local volume has no URL to give and never will.
     * Callers fall back to streaming the object through the application, which is correct but holds a
     * worker for as long as the transfer takes. Where a store *can* answer — S3, and anything
     * S3-compatible behind a Flysystem disk — the browser fetches the bytes itself, the transfer is
     * resumable, and no worker is involved at all. On a multi-gigabyte artifact that is the whole
     * difference between a download that works and one that works until a database gets big enough.
     *
     * Implementations must name the object after the key's final segment, because a caller that
     * redirects never sees the response and cannot set a filename afterwards.
     *
     * What a URL from here grants is a read of one object for a few minutes. It must embed no
     * credential that outlives it and name no other object.
     */
    public function temporaryUrl(string $key, int $seconds): ?string;

    /**
     * A short description of where artifacts are going, for diagnostics.
     *
     * Never a credential, and never a full URL with anything embedded in it.
     */
    public function describe(): string;
}
