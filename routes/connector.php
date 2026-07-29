<?php

declare(strict_types=1);

use App\Http\Controllers\Connector\HeartbeatController;
use App\Http\Controllers\Connector\InventoryController;
use App\Http\Controllers\Connector\PairController;
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

// Rate limited hard, and by source network only: there is no site identity to key on until
// pairing succeeds. Enrolment codes carry 256 bits of entropy, so this is defence in depth rather
// than the primary protection against guessing.
Route::post('pair', PairController::class)
    ->middleware('throttle:10,15')
    ->name('pair');

Route::middleware('connector.signed')->group(function (): void {
    // Liveness only. Needs no capability: it carries no site data, and reporting anything beyond
    // "still here" would be collection without a stated purpose.
    Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');

    Route::post('inventory', InventoryController::class)
        ->middleware('capability:inventory:read')
        ->name('inventory');
});
