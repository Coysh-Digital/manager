<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Connector\PlatformKeypair;
use App\Domain\Connector\ResponseSigner;
use App\Domain\Job\JobService;
use App\Models\Connector;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
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
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        /** @var Connector $connector */
        $connector = $request->attributes->get('manager.connector');

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Jobs::MAX_CLAIM_BATCH],
        ]);

        $envelopes = $jobs->claimFor($site, $connector, (int) ($validated['limit'] ?? Jobs::MAX_CLAIM_BATCH));

        return $signer->sign(
            payload: [
                'jobs' => $envelopes->values()->all(),

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
            ],
            siteExternalId: $site->external_id,
            requestNonce: (string) $request->attributes->get('manager.nonce'),
        );
    }
}
