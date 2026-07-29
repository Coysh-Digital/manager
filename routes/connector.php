<?php

declare(strict_types=1);

use App\Http\Controllers\Connector\HeartbeatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------------------------------
| Connector API
|--------------------------------------------------------------------------------------------------
|
| Every route here is called by a connector running inside a customer's Craft installation. All
| traffic is outbound from the connector's point of view; the platform never calls back into a
| site, and the connector exposes no inbound management endpoint of its own. That is invariants 4
| and 5, and it is why a connector works from behind NAT or a firewall with no inbound rules.
|
| Routes are stateless: authentication is the signature on each request, so there is no session and
| nothing to protect with a CSRF token.
|
| Pairing is the one route that cannot be signature-authenticated, since the connector has no
| identity yet. It authenticates with a single-use enrolment code instead, and is rate limited
| separately.
|
*/

Route::middleware('connector.signed')->group(function (): void {
    Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
});
