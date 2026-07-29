<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\CorrelationId;
use Closure;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a site to hold a capability before the route runs.
 *
 * Runs after signature verification, so the caller is already known to be the connector it claims
 * to be. That is why the refusal here is explicit rather than uniform: telling an authenticated
 * connector which capability it lacks gives nothing away, and letting it stop retrying something
 * it will never be allowed to do is better for everyone.
 *
 * Absence of a grant is a denial. A site that has never been granted anything can do nothing.
 */
final class RequiresCapability
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        /** @var Site|null $site */
        $site = $request->attributes->get('manager.site');

        if ($site === null || ! $site->hasCapability($capability)) {
            return response()->json([
                'error' => 'capability_not_granted',
                'capability' => $capability,
                'correlation_id' => $this->correlationId->get(),
            ], 403, [Protocol::HEADER_CORRELATION_ID => $this->correlationId->get()]);
        }

        return $next($request);
    }
}
