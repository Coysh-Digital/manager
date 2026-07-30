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
     * A short description of where artifacts are going, for diagnostics.
     *
     * Never a credential, and never a full URL with anything embedded in it.
     */
    public function describe(): string;
}
