<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Contracts\BackupSizeLimit;
use App\Domain\Backup\BackupKeypair;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\RecoveryKeyService;
use App\Domain\Connector\PlatformKeypair;
use App\Domain\Connector\ResponseSigner;
use App\Domain\Job\JobService;
use App\Models\Connector;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A connector asking what it should do.
 *
 * Outbound-initiated, like everything else: the platform never pushes work at a site. That is what
 * lets a connector behind NAT do useful work with no inbound firewall rule (invariants 4 and 5).
 *
 * The response is **signed**, because it carries instructions. Without that, anything sitting between
 * the connector and the platform could hand a site a job the platform never issued. A connector that
 * cannot verify the signature must discard the response rather than act on it.
 */
final class JobClaimController
{
    public function __invoke(
        Request $request,
        JobService $jobs,
        ResponseSigner $signer,
        PlatformKeypair $keypair,
        BackupKeypair $backups,
        RecoveryKeyService $recoveryKeys,
        BackupSizeLimit $backupSize,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        /** @var Connector $connector */
        $connector = $request->attributes->get('manager.connector');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Jobs::MAX_CLAIM_BATCH],
        ]);

        $envelopes = $jobs->claimFor($site, $connector, (int) ($validated['limit'] ?? Jobs::MAX_CLAIM_BATCH));

        $zeroKnowledge = $site->organisation->backup_format_floor === Protocol::BACKUP_FORMAT_V2;

        return $signer->sign(
            payload: [
                'jobs' => $envelopes->values()->all(),

                /*
                 | Which artifact format this site should produce, and for v2 the keys to seal to.
                 |
                 | On the signed response for the same reason the capability list is: this is
                 | security-sensitive configuration, and a connector that only learned it at pairing
                 | could never follow a rotation.
                 |
                 | Never inside a job envelope. A `backup.create` job carries one optional parameter,
                 | `max_megabytes`, and that is the whole of what belongs there: a size can only ever
                 | cause a site to do less work. A recipient, a schema version or a destination cannot
                 | travel that way - the first two because a job claimed before a change and run after
                 | it would instruct the wrong answer, and the third because it must not exist at all.
                 |
                 | (This paragraph used to say the job carried no parameters at all. That stopped
                 | being true when the hosted edition gained the ability to lift a site's size limit.)
                 |
                 | Note what a signature does and does not buy here. It proves the platform said this.
                 | It does not prove the platform said something honest, and a compromised platform
                 | could serve a key of its own. The control for that is not on this side: the
                 | connector refuses any key whose fingerprint is not pinned in a file on the Craft
                 | server, which is somewhere this platform cannot reach.
                 */
                'backup' => [
                    'format' => $zeroKnowledge ? Protocol::BACKUP_FORMAT_V2 : Protocol::BACKUP_FORMAT_V1,
                    'min_connector_version' => Protocol::MIN_CONNECTOR_VERSION_FOR_V2,

                    /*
                     | Which declaration schemas this build can read, most preferred first.
                     |
                     | A connector picks the newest it also implements and falls back to backup.v2
                     | when it recognises none - which is what a connector older than v3 does with a
                     | key it has never seen. That is what lets the ceiling move without a cutover:
                     | neither side has to be upgraded before the other, and no backup is lost while
                     | a fleet catches up.
                     |
                     | Distinct from `format` above, which is the one-way commitment about who can
                     | read an artifact. This is about how large one may be, and the two must not be
                     | run together - a size question riding on a security ratchet would make lifting
                     | a ceiling irreversible.
                     */
                    'declarations' => BackupService::ACCEPTED_DECLARATIONS,

                    /*
                     | And what this platform will actually accept.
                     |
                     | Sent so a site with a database larger than the ceiling is refused before it
                     | dumps, rather than after it has dumped, encrypted and offered. That is the
                     | precise failure this change exists to fix: a site did all of that work nightly
                     | and kept none of it.
                     |
                     | A size, never a destination. It can only ever cause a connector to do less.
                     |
                     | Zero is the wire's way of saying there is no ceiling, and the connector reads
                     | it as one - it skips the check on zero exactly as it does on an absent field.
                     | The field is still sent rather than omitted, because omitting it is how an
                     | older platform says "I have no opinion", and this platform has one.
                     */
                    'max_artifact_bytes' => $backupSize->ceilingBytes() ?? 0,

                    // Empty when the organisation has none active. The connector then refuses before
                    // taking a dump, so no plaintext database is written for a backup that could not
                    // have been encrypted.
                    'recipients' => $zeroKnowledge
                        ? $recoveryKeys->recipientsFor($site->organisation)
                        : [],
                ],

                // The platform's current view of what this site may do.
                //
                // Carried here, on a signed response, because a capability list is security-sensitive
                // configuration. Without it the connector's copy would stay frozen at whatever
                // pairing agreed, so granting a capability would change nothing on the site and
                // revoking one would leave it still collecting.
                'capabilities' => $site->grantedCapabilities(),

                // Echoed so a connector can confirm it is still talking to the platform it paired
                // with, without keeping a separate record to compare against.
                'platform_public_key' => $keypair->publicKey(),

                // The legacy artifact encryption key, for organisations still on v1.
                //
                // Withheld the moment an organisation has recovery keys. A connector too old to use
                // them then finds nothing to seal to and refuses outright, rather than quietly
                // producing a backup this platform could read - and its existing message, "the
                // platform has no artifact encryption key configured", is exactly right for that.
                'backup_public_key' => ! $zeroKnowledge && $backups->isConfigured()
                    ? $backups->publicKey()
                    : null,
            ],
            siteExternalId: $site->external_id,
            requestNonce: (string) $request->attributes->get('manager.nonce'),
        );
    }
}
