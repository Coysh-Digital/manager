<?php

declare(strict_types=1);

use App\Http\Controllers\Connector\BackupDeclareController;
use App\Http\Controllers\Connector\BackupProgressController;
use App\Http\Controllers\Connector\BackupUploadController;
use App\Http\Controllers\Connector\BackupUploadedController;
use App\Http\Controllers\Connector\HeartbeatController;
use App\Http\Controllers\Connector\InventoryController;
use App\Http\Controllers\Connector\JobClaimController;
use App\Http\Controllers\Connector\JobResultController;
use App\Http\Controllers\Connector\LoginsController;
use App\Http\Controllers\Connector\PairController;
use App\Http\Controllers\Connector\SystemController;
use App\Http\Controllers\Connector\UpdatesController;
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

    Route::post('updates', UpdatesController::class)
        ->middleware('capability:updates:read')
        ->name('updates');

    /*
     | Disk usage, PHP limits and sampled response timings.
     |
     | Its own capability rather than an extension of system:read. Measuring disk means walking a
     | directory tree and timing responses means observing traffic — both are a different kind of
     | collection from reading a version number, and widening an existing grant to cover them would
     | have sites start doing both without anybody deciding to.
     */
    Route::post('system', SystemController::class)
        ->middleware('capability:runtime:read')
        ->name('system');

    /*
     | Counts of failed control-panel sign-ins. Never usernames, never addresses — the schema has
     | nowhere to put either, which is where that promise is kept rather than in a code review.
     */
    Route::post('logins', LoginsController::class)
        ->middleware('capability:logins:read')
        ->name('logins');

    /*
     | Jobs.
     |
     | Claiming needs no capability of its own: every job names the capability it requires, and that
     | is checked when the job is queued and again when it is claimed. A capability gate here would
     | be a third, weaker check in front of two precise ones.
     |
     | The claim response is signed, because it carries instructions. The result response is not,
     | because it carries only an acknowledgement.
     */
    Route::post('jobs/claim', JobClaimController::class)->name('jobs.claim');
    Route::post('jobs/{job}/result', JobResultController::class)->name('jobs.result');

    /*
     | Backups, step one: declare what is about to be uploaded.
     |
     | An ordinary signed request with a small JSON body, so it goes through the ordinary middleware.
     | Only the bytes need special handling.
     */
    Route::post('backups', BackupDeclareController::class)
        ->middleware('capability:backups:create')
        ->name('backups.declare');

    /*
     | Which phase a backup has reached, as reported by the site.
     |
     | Telemetry, and nothing acts on it. Exactly one stage is sent today — the dump, because it is
     | the longest phase and the only one whose information is not derivable from a later report.
     | Reports for the other phases are accepted so a newer connector needs no platform change.
     */
    Route::post('backups/progress', BackupProgressController::class)
        ->middleware('capability:backups:create')
        ->name('backups.progress');

    /*
     | A site reporting that it wrote an artifact straight to storage.
     |
     | Carries nothing but the fact. The platform did not see those bytes, so it asks the storage
     | service rather than believing the site, which is what keeps "uploaded" and "stored" different
     | states. Idempotent: a connector that timed out waiting for the answer will ask again.
     */
    Route::post('backups/{artifactId}/uploaded', BackupUploadedController::class)
        ->middleware('capability:backups:create')
        ->name('backups.uploaded');
});

/*
| Backups, step two: the bytes.
|
| Outside the group above because it needs the middleware in streaming mode. The ordinary mode hashes
| the request body to verify the signature, which for an artifact would mean reading a customer's
| entire database into memory before deciding whether to trust the request that carried it.
|
| In streaming mode the hash comes from a header the signature covers, so authentication happens before
| the body is touched at all — and the body is then compared against a promise that has already been
| verified as coming from this connector.
*/
Route::put('backups/{artifactId}/content', BackupUploadController::class)
    ->middleware(['connector.signed:stream', 'capability:backups:create'])
    ->name('backups.upload');
