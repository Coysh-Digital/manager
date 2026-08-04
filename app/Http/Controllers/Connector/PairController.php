<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupKeypair;
use App\Domain\Connector\PlatformKeypair;
use App\Domain\Connector\ResponseSigner;
use App\Domain\Pairing\PairingRejected;
use App\Domain\Pairing\PairingService;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The one connector route that cannot be signature-authenticated.
 *
 * At this point the connector has no identity for the platform to verify, so it authenticates with
 * a single-use enrolment code instead. Everything else it ever sends is signed.
 *
 * The response is signed by the platform, and that signature is the connector's first proof it is
 * talking to the right server rather than to whatever intercepted the request. The connector
 * stores the platform public key from this response and checks every later instruction against it.
 */
final class PairController
{
    public function __invoke(
        Request $request,
        PairingService $pairing,
        ResponseSigner $signer,
        PlatformKeypair $keypair,
        BackupKeypair $backups,
        CorrelationId $correlationId,
    ): JsonResponse {
        $validated = $this->validate($request);

        try {
            $result = $pairing->pair(
                code: $validated['enrolment_code'],
                publicKey: $validated['public_key'],
                connectorVersion: $validated['connector_version'],
                submittedHost: $validated['site_url'],
                ip: $request->ip(),
            );
        } catch (PairingRejected $e) {
            // The reason is in the audit log and the application log, keyed by correlation ID. The
            // connector is told only that pairing failed: distinguishing "no such code" from
            // "already used" would let someone probing learn which codes had ever existed.
            Log::info('Pairing rejected.', [
                'correlation_id' => $correlationId->get(),
                'reason' => $e->reason,
            ]);

            return response()->json([
                'error' => 'pairing_failed',
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        // Signed over a nonce the connector chose, so this response cannot be captured and
        // replayed at some other connector later.
        return $signer->sign(
            payload: [
                'site_id' => $result->site->external_id,
                'platform_public_key' => $keypair->publicKey(),

                // The encryption key artifacts get sealed to. Separate from the signing key above,
                // and null when backups are not configured - a connector that is told nothing seals
                // nothing rather than guessing.
                'backup_public_key' => $backups->isConfigured() ? $backups->publicKey() : null,

                // The platform decides these; the connector cannot ask for more. A pairing held
                // for confirmation gets none at all until a person approves it.
                'capabilities' => $result->capabilities,

                'state' => $result->isLive ? 'active' : 'pending_confirmation',
                'expected_domain' => $result->site->expected_domain,
            ],
            siteExternalId: $result->site->external_id,
            requestNonce: $validated['nonce'],
        );
    }

    /**
     * @return array{enrolment_code: string, public_key: string, connector_version: string, site_url: string, nonce: string}
     */
    private function validate(Request $request): array
    {
        $validated = $request->validate([
            'enrolment_code' => ['required', 'string', 'max:128'],
            'public_key' => ['required', 'string', 'max:128'],
            'connector_version' => ['required', 'string', 'max:32'],
            'site_url' => ['required', 'string', 'max:255'],

            // Chosen by the connector so it can bind the signed response to this exact request.
            'nonce' => ['required', 'string', 'max:64'],
        ]);

        // A connector must not be able to ask for capabilities, or to smuggle in a field the
        // platform has not agreed to read. Rejecting unknown keys keeps "silent capability
        // expansion" from having a route in.
        $unexpected = array_diff(array_keys($request->all()), array_keys($validated));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'payload' => 'Unrecognised fields in pairing request: '.implode(', ', $unexpected),
            ]);
        }

        return $validated;
    }
}
