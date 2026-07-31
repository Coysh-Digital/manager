<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A correlation identifier on the responses nobody planned for.
 *
 * Every *handled* rejection on the connector API already carries one: the signature middleware, the
 * capability middleware and each connector controller put it in the body and in the
 * `Manager-Correlation-Id` header. An **unhandled** exception did not, because it never passes
 * through any of that code — Laravel renders it directly, and the connector received
 * `{"message":"Server Error"}`.
 *
 * The visible cost, reported from a live site: a backup failing with
 *
 *     RuntimeException: The platform rejected the request (HTTP 500). Correlation ID: unknown
 *
 * "unknown" is the connector saying the body had no identifier in it, which left nothing to search
 * the platform log for. The one failure that most needs tracing was the only one not traceable, and
 * that is backwards — an unhandled error is by definition the one nobody anticipated.
 *
 * Registered with `respond()` rather than `render()`. `respond()` receives the response Laravel has
 * already produced, so the status mapping and the JSON-versus-HTML decision are left exactly as they
 * were and this only decorates. Nothing is added to the body beyond the identifier: with
 * `APP_DEBUG=false` the message stays "Server Error", and the exception detail belongs in the log
 * rather than on the wire to somebody's Craft site.
 */
final class ConnectorErrorResponse
{
    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        $correlationId = app(CorrelationId::class)->get();

        // Idempotent in both directions. A handled rejection has already chosen its identifier and
        // put it in both places; overwriting it here would make the header and the body disagree
        // with the audit row that recorded the same event.
        if (! $response->headers->has(Protocol::HEADER_CORRELATION_ID)) {
            $response->headers->set(Protocol::HEADER_CORRELATION_ID, $correlationId);
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $body = $response->getData(true);

        if (! is_array($body) || array_key_exists('correlation_id', $body)) {
            return $response;
        }

        $response->setData([...$body, 'correlation_id' => $correlationId]);

        return $response;
    }
}
