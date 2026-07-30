<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyChallengeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------------------------------
|
| There is no registration route. Accounts are created by an owner, or through the one-time setup
| flow, which disables itself permanently once an owner exists.
|
*/

/*
 | Readiness, for orchestrators. Liveness is Laravel's own /up, which answers "is PHP running";
 | this answers "can this instance serve a request". Unauthenticated by necessity — a load balancer
 | has no credentials — so it names which check failed and nothing else.
 */
Route::get('ready', [HealthController::class, 'ready'])->name('health.ready');

// First run only. The middleware makes the route stop resolving once an owner account exists, so
// it is closed rather than merely hidden.
Route::middleware('setup.available')->group(function (): void {
    Route::get('setup', [SetupController::class, 'show'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');

    // Reached only with a pending challenge in the session. Nobody is authenticated at this point.
    Route::get('two-factor', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])->name('two-factor.store');

    // The same challenge, satisfied with a passkey instead of a typed code. Scoped to the pending
    // user, and gated so another account's passkey cannot close this challenge.
    Route::post('two-factor/passkey/options', [PasskeyChallengeController::class, 'options'])->name('two-factor.passkey.options');
    Route::post('two-factor/passkey', [PasskeyChallengeController::class, 'store'])->name('two-factor.passkey.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'organisation', 'second-factor'])->group(function (): void {
    Route::redirect('/', '/sites');

    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::get('sites/{site}/capabilities', [CapabilityController::class, 'show'])->name('sites.capabilities');

    Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');

    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');

    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');

    Route::get('account', [AccountController::class, 'show'])->name('account.show');

    // Where Laravel's RequirePassword middleware sends anyone whose confirmation has gone stale.
    Route::get('confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmPasswordController::class, 'store'])->name('password.confirm.store');

    /*
     | Sensitive actions.
     |
     | Everything here requires the password to have been confirmed recently. The specification
     | lists changing connector capabilities among the actions needing recent authentication, and
     | the same reasoning covers disabling a second factor or reading out fresh recovery codes: a
     | session left open on an unlocked machine must not be enough.
     */
    Route::middleware('password.confirm')->group(function (): void {
        Route::post('sites/{site}/capabilities/grant', [CapabilityController::class, 'grant'])
            ->name('sites.capabilities.grant');
        Route::post('sites/{site}/capabilities/revoke', [CapabilityController::class, 'revoke'])
            ->name('sites.capabilities.revoke');
        Route::post('sites/{site}/connector/confirm', [CapabilityController::class, 'confirmConnector'])
            ->name('sites.connector.confirm');
        Route::post('sites/{site}/connector/revoke', [CapabilityController::class, 'revokeConnector'])
            ->name('sites.connector.revoke');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

        // Enqueues a job rather than reaching into the site: the platform cannot contact a
        // connector, so this waits for the site to come and ask.
        Route::post('updates/{site}/refresh', [UpdateController::class, 'refresh'])->name('updates.refresh');

        Route::post('findings/{finding}/acknowledge', [FindingController::class, 'acknowledge'])->name('findings.acknowledge');
        Route::post('findings/{finding}/reopen', [FindingController::class, 'reopen'])->name('findings.reopen');

        Route::post('settings/mfa', [SettingsController::class, 'updateMfa'])->name('settings.mfa');
        Route::post('settings/connectors/rotate', [SettingsController::class, 'rotateAllConnectors'])
            ->name('settings.connectors.rotate');

        Route::post('account/two-factor/start', [AccountController::class, 'startTotp'])->name('account.totp.start');
        Route::post('account/two-factor/confirm', [AccountController::class, 'confirmTotp'])->name('account.totp.confirm');
        Route::delete('account/two-factor', [AccountController::class, 'disableTotp'])->name('account.totp.disable');
        Route::post('account/recovery-codes', [AccountController::class, 'regenerateRecoveryCodes'])->name('account.recovery-codes');

        Route::post('account/passkeys/options', [PasskeyController::class, 'options'])->name('passkeys.options');
        Route::post('account/passkeys', [PasskeyController::class, 'store'])->name('passkeys.store');
        Route::delete('account/passkeys/{id}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy');
        Route::delete('account/sessions/{id}', [AccountController::class, 'revokeSession'])->name('account.sessions.revoke');
    });
});
