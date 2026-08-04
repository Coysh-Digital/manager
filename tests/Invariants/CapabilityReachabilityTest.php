<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use Illuminate\Support\Facades\Route;

/**
 * Every capability this platform enforces can actually be granted.
 *
 * The capability system has two halves that are edited in different files at different times: a
 * route says what it requires, and {@see CapabilityService} says what an administrator may hand out.
 * Nothing has ever checked that the second covers the first, and the gap is not theoretical - it
 * shipped.
 *
 * `runtime:read` and `logins:read` were built end to end. The endpoints existed and enforced them,
 * the connector scheduled a task for each, the tables were migrated. But neither reached
 * {@see CapabilityService::grantableFromInterface()}, so the capabilities screen described both as
 * "Not yet available", no administrator could turn them on, and the connector queued work every
 * thirty minutes that could only ever be refused. The visible symptom was on somebody else's
 * machine: a growing list of failed queue jobs in the control panel of a site we monitor.
 *
 * This is the check that would have caught it, and it is deliberately one-directional. A capability
 * with no route is fine - `security:read` and `licences:read` are reported inside another payload
 * rather than through an endpoint of their own. A route with no way to grant what it demands is not.
 */
it('offers a way to grant every capability an endpoint requires', function (): void {
    $enforced = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
                continue;
            }

            // 'capability:runtime:read' - the capability itself contains the colon this splits on,
            // so take everything after the first one rather than the second field.
            $enforced[substr($middleware, strlen('capability:'))] = $route->uri();
        }
    }

    expect($enforced)->not->toBeEmpty('No route enforces a capability. Has the middleware been renamed?');

    $grantable = array_merge(
        CapabilityService::grantableFromInterface(),
        CapabilityService::confirmable(),
        CapabilityService::pairingDefaults(),
    );

    $unreachable = [];

    foreach ($enforced as $capability => $uri) {
        if (! in_array($capability, $grantable, true)) {
            $unreachable[] = "{$capability} (required by {$uri})";
        }
    }

    expect($unreachable)->toBe([], implode("\n", [
        'These capabilities are enforced but cannot be granted by anyone:',
        '  '.implode("\n  ", $unreachable),
        'A connector asking for one gets a refusal it can do nothing about, and on Craft that is a',
        'failed queue job on the customer\'s site. Add it to CapabilityService::grantableFromInterface()',
        'if it is read-only and finished, or to confirmable() if it reads content - or remove the',
        'endpoint if it is not.',
    ]));
});
