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
     * Above five gigabytes an object store will not accept a single request, so an implementation
     * should return a grant carrying {@see UploadPart}s instead. `$expectedCrc32c` is what makes that
     * possible, and it is worth being exact about what it costs.
     *
     * On the single-request path the store enforces `$expectedSha256Base64` — a cryptographic hash the
     * connector signed. On the multipart path it cannot: a store can only confirm a whole-object
     * checksum across an assembly when the algorithm linearises, and SHA-256 does not. CRC-32C does,
     * so that is what the store checks, and **a CRC is forgeable where a SHA is not.**
     *
     * That is a real difference and not one to gloss over. It is also not a weakening of anything that
     * was doing work: this platform cannot read a v2 or v3 artifact whatever checksum guarded it, and
     * what binds those bytes to that site has always been the Ed25519 signature over the manifest,
     * checked offline by the customer with `manager-restore`. The transport checksum's job is to catch
     * corruption between a site and a bucket, and for that the two are equivalent.
     *
     * @param  string  $expectedSha256Base64  the whole-file checksum the storage service must enforce
     * @param  string  $expectedCrc32c  the same bytes as CRC-32C hex, for a multipart assembly
     */
    public function grantFor(
        Site $site,
        RemoteJob $job,
        string $expectedSha256Base64,
        int $maxBytes,
        string $expectedCrc32c = '',
    ): ?UploadGrant;

    /**
     * Confirm that an object arrived, at the size and checksum that were declared.
     *
     * Separate from the grant because the platform never sees the bytes on this path and has to ask
     * the storage service instead. Returns the stored size, or null when the object is not there.
     *
     * This is what makes `uploaded` a state rather than a claim: a connector says it finished, and
     * nothing is called `stored` until something other than the connector agrees.
     *
     * `$reference` is the store's own handle for a multipart upload still in progress. Given one, an
     * implementation completes the upload before asking about it — which is where the store validates
     * the assembled whole and refuses it if the parts do not add up to what was promised. It is the
     * platform that completes it, never the connector, so nothing here is taken on a site's word.
     */
    public function confirm(string $storageKey, string $expectedSha256Base64, ?string $reference = null): ?int;
}
