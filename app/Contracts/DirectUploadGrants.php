<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Backup\UploadGrant;
use App\Models\RemoteJob;
use App\Models\Site;

/**
 * Short-lived permission for a site to write an artifact straight into object storage.
 *
 * The fifth edition seam, and the one with the narrowest surface. Self-hosted binds an implementation
 * that always returns null, which means artifacts keep streaming through the application exactly as
 * they always have — an operator running Manager on one box with a local disk has nothing to presign
 * and nothing to gain.
 *
 * The hosted edition binds one that issues presigned S3 requests, and the reason is not performance.
 * It is that a gigabyte of ciphertext passing through a web server is a gigabyte of ciphertext written
 * to that server's temporary directory, held by its process, and counted against its request timeout —
 * for no benefit, since the platform cannot read it either way. Removing that leg removes a place the
 * bytes exist.
 *
 * Deliberately absent: any method that reads, lists, deletes or grants access to an existing object. A
 * grant is write-once permission for one key that does not exist yet.
 */
interface DirectUploadGrants
{
    /**
     * Issue a grant for one artifact, or null when this edition does not issue them.
     *
     * @param  string  $expectedSha256Base64  the whole-file checksum the storage service must enforce
     */
    public function grantFor(
        Site $site,
        RemoteJob $job,
        string $expectedSha256Base64,
        int $maxBytes,
    ): ?UploadGrant;

    /**
     * Confirm that an object arrived, at the size and checksum that were declared.
     *
     * Separate from the grant because the platform never sees the bytes on this path and has to ask
     * the storage service instead. Returns the stored size, or null when the object is not there.
     *
     * This is what makes `uploaded` a state rather than a claim: a connector says it finished, and
     * nothing is called `stored` until something other than the connector agrees.
     */
    public function confirm(string $storageKey, string $expectedSha256Base64): ?int;
}
