<?php

declare(strict_types=1);

namespace App\Domain\Connector;

use App\Support\CorrelationId;
use coyshdigital\managerprotocol\CanonicalResponse;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;

/**
 * Signs responses that carry commands or security-sensitive configuration.
 *
 * The signature covers the request nonce as well as the body, which binds a response to the single
 * request that asked for it. Without that binding, a captured response - a set of granted
 * capabilities, say - could be replayed at the connector against some later request.
 *
 * A connector that expects a signed response and does not get a valid one must treat the whole
 * exchange as failed, never as an unsigned success.
 */
final class ResponseSigner
{
    public function __construct(
        private readonly PlatformKeypair $keypair,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload, string $siteExternalId, string $requestNonce, int $status = 200): JsonResponse
    {
        $correlationId = $this->correlationId->get();

        // Encoded once, here. Signing one string and letting the framework re-encode another would
        // produce a signature over bytes the connector never sees.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $canonical = new CanonicalResponse(
            siteId: $siteExternalId,
            requestNonce: $requestNonce,
            status: $status,
            body: $body,
        );

        $signature = $canonical->sign($this->keypair->secretKey());

        return new JsonResponse($body, $status, [
            Protocol::HEADER_SIGNATURE => Protocol::SIGNATURE_SCHEME.'='.$signature,
            Protocol::HEADER_CORRELATION_ID => $correlationId,
        ], json: true);
    }
}
