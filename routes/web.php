<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasskeyChallengeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationDestinationController;
use App\Http\Controllers\PaletteController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\RecoveryKeyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SiteAuditController;
use App\Http\Controllers\SiteBackupController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteHealthController;
use App\Http\Controllers\SiteNoteController;
use App\Http\Controllers\SiteSecurityController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SiteUpdateController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UpdateController;
use App\Models\Site;
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

    // What the command palette can jump to. Read-only, scoped to the organisation, and fetched once
    // per page rather than per keystroke — a fleet is small enough to filter in the browser, and a
    // search-as-you-type endpoint would put what somebody typed into the access log.
    Route::get('palette', PaletteController::class)->name('palette');

    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');

    /*
     | One site, six screens.
     |
     | Real routes rather than panels switched by script: a tab somebody can link to, bookmark and
     | reach with the back button is worth more than one that saves a request, and each screen loads
     | only the data it shows rather than all of it on every visit.
     |
     | Every route here carries `site.scoped`, which is what stops one organisation reading another's
     | site by pasting in a ULID.
     */
    Route::middleware('site.scoped')->group(function (): void {
        Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::get('sites/{site}/health', [SiteHealthController::class, 'show'])->name('sites.health');
        Route::get('sites/{site}/updates', [SiteUpdateController::class, 'show'])->name('sites.updates');

        // Craft's published release notes, cut to the versions between this site's and the latest.
        // Fetched when somebody opens the panel, not when the screen renders.
        Route::get('sites/{site}/updates/changelog', [SiteUpdateController::class, 'changelog'])
            ->name('sites.updates.changelog');

        // The same panel for a plugin, served from notes connectors forwarded rather than from
        // anything fetched. The handle is constrained to the protocol's own pattern here as well as
        // in the controller, so a malformed one never reaches a query.
        Route::get('sites/{site}/updates/changelog/plugins/{handle}', [SiteUpdateController::class, 'pluginChangelog'])
            ->where('handle', '[A-Za-z0-9._-]{1,128}')
            ->name('sites.updates.changelog.plugin');

        Route::get('sites/{site}/security', [SiteSecurityController::class, 'show'])->name('sites.security');
        Route::get('sites/{site}/backups', [SiteBackupController::class, 'show'])->name('sites.backups');

        // Read-only, and the same tenant scoping as the screen it serves. It carries a job
        // identifier and a phase name — nothing the page rendering it cannot already see.
        Route::get('sites/{site}/backups/status', [SiteBackupController::class, 'status'])
            ->name('sites.backups.status');

        Route::get('sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings');
        Route::get('sites/{site}/audit', [SiteAuditController::class, 'show'])->name('sites.audit');

        // Capabilities became a section of Settings rather than a screen of its own. The route name
        // stays so the links pointing at it from the fleet screens keep working.
        Route::get('sites/{site}/capabilities', fn (Site $site) => redirect()
            ->route('sites.settings', $site)
            ->withFragment('capabilities'))->name('sites.capabilities');
    });

    Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');

    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');

    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('backups/status', [BackupController::class, 'status'])->name('backups.status');

    /*
     | Clearing a "Did not complete" notice.
     |
     | Outside the recent-authentication group deliberately, and for the same reason notes are: this
     | destroys nothing. The job row, its failure reason and the audit entry for the failure all
     | survive — only the panel stops leading with it. Administrators, matching the button that asks
     | for a backup in the first place.
     */
    Route::post('backups/failures/dismiss', [BackupController::class, 'dismissFailure'])
        ->name('backups.failures.dismiss');
    Route::post('backups/failures/clear', [BackupController::class, 'clearFailures'])
        ->name('backups.failures.clear');

    /*
     | Notes.
     |
     | Any member may write one — it changes nothing about the site, and the value comes entirely
     | from being easy enough that people actually do it. Outside the recent-authentication group for
     | the same reason: a note is not a privileged action, and making somebody re-enter their password
     | to record "the client wants PHP left alone" is how a feature goes unused.
     */
    Route::middleware('site.scoped')->group(function (): void {
        Route::post('sites/{site}/notes', [SiteNoteController::class, 'store'])->name('sites.notes.store');
        Route::post('sites/{site}/notes/{note}/pin', [SiteNoteController::class, 'pin'])->name('sites.notes.pin');
        Route::delete('sites/{site}/notes/{note}', [SiteNoteController::class, 'destroy'])->name('sites.notes.destroy');
    });

    // Refreshing asks a site to re-send what it already sends on a schedule. No recent-auth gate and no
    // administrator requirement: it is the least privileged useful action here, and gating it would
    // only make people wait for cron.
    Route::post('sites/refresh-all', [SiteController::class, 'refreshAll'])->name('sites.refresh-all');
    Route::post('sites/{site}/refresh', [SiteController::class, 'refresh'])
        ->middleware('site.scoped')
        ->name('sites.refresh');

    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');

    Route::get('account', [AccountController::class, 'show'])->name('account.show');

    // Which clock this account reads dated times in. Outside the recent-authentication group below,
    // with the notes and for the same reason: it is a display preference, it grants nothing, and
    // asking somebody to re-enter their password to stop subtracting eleven hours in their head is
    // how a setting goes unused.
    Route::post('account/timezone', [AccountController::class, 'updateTimezone'])->name('account.timezone');

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
        // Requesting a backup and deleting one both need recent authentication: one asks a production
        // site for a copy of its database, the other destroys one irrecoverably.
        // Adding a site and issuing a code both sit behind recent authentication. A code is a bearer
        // secret until it is consumed, so a stale session must not be able to mint one.
        Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
        Route::delete('backups/{artifact}', [BackupController::class, 'destroy'])->name('backups.destroy');

        /*
         | Downloading an artifact's ciphertext.
         |
         | Behind recent authentication with the other two, and for the same reason: this hands over a
         | complete copy of a customer's database, encrypted, and a session left open on an unlocked
         | machine must not be enough. It decrypts nothing — see the controller for why that
         | distinction is the whole design, and why this route can exist when a plaintext one still
         | should not.
        */
        Route::get('backups/{artifact}/ciphertext', BackupDownloadController::class)
            ->name('backups.download');

        // Everything below acts on one named site, so all of it is tenant-scoped by the same
        // middleware the read screens use.
        Route::middleware('site.scoped')->group(function (): void {
            Route::post('sites/{site}/enrolment-code', [SiteController::class, 'issueCode'])
                ->name('sites.enrolment-code');

            // Renaming a site, or changing the domain it is expected to pair from, is a change to
            // what Manager believes about a production install. Administrators only, recently
            // authenticated, and audited with the previous value.
            Route::post('sites/{site}/settings', [SiteSettingsController::class, 'update'])
                ->name('sites.settings.update');

            Route::post('backups/sites/{site}', [BackupController::class, 'store'])->name('backups.store');

            Route::post('sites/{site}/capabilities/grant-confirmed', [CapabilityController::class, 'grantConfirmed'])
                ->name('capabilities.grant-confirmed');
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
        });

        Route::post('findings/{finding}/acknowledge', [FindingController::class, 'acknowledge'])->name('findings.acknowledge');
        Route::post('findings/{finding}/reopen', [FindingController::class, 'reopen'])->name('findings.reopen');

        Route::post('settings/mfa', [SettingsController::class, 'updateMfa'])->name('settings.mfa');
        Route::post('settings/connectors/rotate', [SettingsController::class, 'rotateAllConnectors'])
            ->name('settings.connectors.rotate');

        /*
         | Recovery keys.
         |
         | Owners only, like people and notification destinations, because adding one decides who can
         | read every future backup this organisation takes. Note what is absent: there is no route
         | that generates a key. A private key produced on this server would exist in a response body,
         | and the claim that we cannot read backups would become a claim about our own discipline
         | rather than a property of the system. Keys come from `manager-restore keygen`, on a machine
         | we never see.
         |
         | Bound on external_id like everything else, so a sequential identifier is never in a URL.
         */
        // Retention and the time zone the backup schedule reads. Owner-level, because shortening
        // retention decides how far back this organisation can recover from.
        Route::post('settings/retention', [SettingsController::class, 'updateRetention'])
            ->name('settings.retention');

        // Sends to the signed-in owner's own address and nowhere else. A destination field here
        // would be an open relay on an authenticated page.
        Route::post('settings/mail/test', [SettingsController::class, 'testMail'])
            ->name('settings.mail.test');

        Route::post('settings/recovery-keys', [RecoveryKeyController::class, 'store'])
            ->name('recovery-keys.store');
        Route::post('settings/recovery-keys/{recoveryKey}/prove', [RecoveryKeyController::class, 'prove'])
            ->name('recovery-keys.prove');
        Route::post('settings/recovery-keys/{recoveryKey}/challenge', [RecoveryKeyController::class, 'rechallenge'])
            ->name('recovery-keys.challenge');
        Route::delete('settings/recovery-keys/{recoveryKey}', [RecoveryKeyController::class, 'revoke'])
            ->name('recovery-keys.revoke');

        /*
         | People.
         |
         | Owners only, and every action audited. Deciding who can reach a control plane at all is a
         | different kind of decision from managing the sites inside it, so it sits above the
         | administrator role rather than beside it.
         */
        Route::post('settings/people', [TeamController::class, 'invite'])->name('team.invite');
        Route::post('settings/people/{membership}/resend', [TeamController::class, 'resend'])->name('team.resend');
        Route::post('settings/people/{membership}/role', [TeamController::class, 'updateRole'])->name('team.role');
        Route::delete('settings/people/{membership}', [TeamController::class, 'revoke'])->name('team.revoke');

        Route::post('settings/notifications', [NotificationDestinationController::class, 'store'])
            ->name('notifications.store');
        Route::post('settings/notifications/{destination}/test', [NotificationDestinationController::class, 'test'])
            ->name('notifications.test');
        Route::delete('settings/notifications/{destination}', [NotificationDestinationController::class, 'destroy'])
            ->name('notifications.destroy');

        Route::post('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
        Route::post('account/password', [AccountController::class, 'updatePassword'])->name('account.password');

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
