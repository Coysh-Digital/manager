<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\DirectUploadGrants;
use App\Domain\Backup\UploadGrant;
use App\Models\RemoteJob;
use App\Models\Site;

/**
 * The self-hosted answer: no.
 *
 * A self-hosted installation usually writes artifacts to a disk on the same machine, and there is
 * nothing to presign — no storage service to hold a signature, and no separate hop to remove. Sites
 * upload through the application as they always have, which on one box is also the shortest path the
 * bytes could take.
 *
 * An operator who has pointed `MANAGER_BACKUP_DRIVER` at an S3-compatible service still gets this
 * answer, and that is deliberate rather than an omission. Issuing presigned requests means holding
 * credentials with permission to mint them, on a box that also serves the control panel, in a
 * deployment where nobody has reviewed the bucket policy. The failure mode of the cautious choice is a
 * gigabyte passing through nginx; the failure mode of the other one is a signing credential in an
 * environment file. A customer who wants bytes to bypass this server entirely has the better option
 * anyway: point the *site* at its own bucket, and the artifact never comes here at all.
 */
final class NoDirectUploads implements DirectUploadGrants
{
    public function grantFor(
        Site $site,
        RemoteJob $job,
        string $expectedSha256Base64,
        int $maxBytes,
        string $expectedCrc32c = '',
    ): ?UploadGrant {
        return null;
    }

    /**
     * Never called, because nothing on this edition is ever left awaiting confirmation.
     *
     * An artifact uploaded through the application is hashed as it streams past, so it becomes
     * `stored` and `verified` in the same instant and never passes through `uploaded`. Returning null
     * rather than throwing keeps the contract honest if that ever stops being true.
     */
    public function confirm(string $storageKey, string $expectedSha256Base64, ?string $reference = null): ?int
    {
        return null;
    }
}
