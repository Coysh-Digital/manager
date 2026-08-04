<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\BillingAdministration;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityService;
use App\Domain\Health\Diagnostics;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The General tab of Settings: what this installation is, how it is doing, and the two
 * organisation-wide switches.
 *
 * The health panel is the same {@see Diagnostics} that `manager:doctor` runs, not a second set of
 * checks written for the screen. Two implementations would eventually disagree, and the one somebody
 * is looking at would be the wrong one.
 *
 * People, notification destinations and recovery keys used to be sections of this one screen, and
 * their queries ran on every visit to it whichever section somebody came for. Each is now a tab with
 * its reads on the controller that already owned its writes.
 *
 * Everything that changes state here needs recent authentication, and the irreversible actions need
 * the organisation name typed as well.
 */
final class SettingsController
{
    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly CapabilityService $capabilities,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public function show(Organisation $organisation): View
    {
        return view('settings.index', [
            'organisation' => $organisation,
            'checks' => $this->diagnostics->forReader(),
            'membership' => app(Membership::class),

            // Where to manage payment, or null when nobody bills this installation — which is the
            // answer here, and the reason the section below it renders nothing self-hosted.
            'billingUrl' => app(BillingAdministration::class)->url(),

            // Null on an installation that has no way to know, which is most of them. See
            // config/manager.php.
            'version' => config('manager.version'),

            'siteCount' => $organisation->sites()->active()->count(),
            'connectorCount' => Connector::query()
                ->whereIn('site_id', $organisation->sites()->select('id'))
                ->where('state', Connector::STATE_ACTIVE)
                ->count(),

            // Shown so an operator can see what a new site will and will not be able to do before
            // they pair one, rather than discovering it afterwards.
            'pairingDefaults' => CapabilityService::pairingDefaults(),
            'grantable' => CapabilityService::grantableFromInterface(),
        ]);
    }

    /**
     * Require every member of this organisation to hold a second factor.
     *
     * Existing accounts without one are not locked out immediately; they are required to enrol before
     * they can do anything else. Locking people out of a control plane to improve its security is a
     * trade that tends to be made once and regretted at 2am.
     */
    public function updateMfa(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate(['mfa_required' => ['required', 'boolean']]);

        $was = $organisation->mfa_required;
        $now = (bool) $validated['mfa_required'];

        if ($was === $now) {
            return back();
        }

        $organisation->forceFill(['mfa_required' => $now])->save();

        $this->audit->record(
            action: $now ? 'organisation.mfa.required' : 'organisation.mfa.optional',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: ['mfa_required' => $was],
            after: ['mfa_required' => $now],
        );

        $withoutSecondFactor = $organisation->activeMemberships()
            ->with('user')
            ->get()
            ->reject(fn (Membership $membership): bool => $membership->user->hasConfirmedTotp())
            ->count();

        return back()->with(
            'status',
            $now
                ? ($withoutSecondFactor > 0
                    ? "Two-factor authentication is now required. {$withoutSecondFactor} ".
                      ($withoutSecondFactor === 1 ? 'member has' : 'members have').
                      ' not enrolled yet and will be asked to before they can continue.'
                    : 'Two-factor authentication is now required. Every member already has it.')
                : 'Two-factor authentication is no longer required.',
        );
    }

    /**
     * Revoke every connector in the organisation.
     *
     * The blunt instrument, for when a platform credential is suspected of having leaked. Every site
     * stops reporting until each is re-paired with a fresh enrolment code, which is the point:
     * whatever the old keys were trusted for, they no longer are.
     */
    public function rotateAllConnectors(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate(['confirm_organisation' => ['required', 'string']]);

        if (strcasecmp(trim($validated['confirm_organisation']), $organisation->name) !== 0) {
            return back()->withErrors([
                'confirm_organisation' => 'That does not match this organisation\'s name.',
            ]);
        }

        $revoked = 0;

        DB::transaction(function () use ($organisation, $request, &$revoked): void {
            $sites = $organisation->sites()->active()->get();

            foreach ($sites as $site) {
                $connector = $site->activeConnector()->first();

                if ($connector === null) {
                    continue;
                }

                $connector->forceFill([
                    'state' => Connector::STATE_REVOKED,
                    'revoked_at' => now(),
                    'revoked_reason' => 'Organisation-wide key rotation',
                ])->save();

                // Credentials and permissions go together, as everywhere else.
                $this->capabilities->revokeAll($site, $request->user(), 'Organisation-wide key rotation');

                $site->forceFill(['status' => Site::STATUS_NOT_CONNECTED])->save();

                $revoked++;
            }

            $this->audit->record(
                action: 'organisation.connectors.rotated',
                organisation: $organisation,
                actor: $request->user(),
                targetType: 'organisation',
                targetId: $organisation->external_id,
                after: ['connectors_revoked' => $revoked, 'sites' => $sites->count()],
            );
        });

        // After the transaction, so a rotation that rolled back does not announce itself. Notified for
        // the same reason a single revocation is, only more so: this silences the entire fleet, and if
        // the account that did it was compromised, the notification is the one thing the attacker
        // cannot suppress from in here.
        if ($revoked > 0) {
            $this->notifier->dispatch(new NotificationEvent(
                type: NotificationEvent::CONNECTOR_REVOKED,
                subject: "Every connector in {$organisation->name} was revoked",
                summary: "All {$revoked} active ".($revoked === 1 ? 'connector was' : 'connectors were').
                    ' revoked at once, so the whole fleet has stopped reporting. Each site needs a '
                    .'fresh enrolment code. If you did not expect this, treat it as a possible '
                    .'compromise of the account that did it.',
                context: ['connectors_revoked' => $revoked],
            ), $organisation);
        }

        return back()->with(
            'warning',
            $revoked === 0
                ? 'There were no active connectors to revoke.'
                : "Revoked {$revoked} ".($revoked === 1 ? 'connector' : 'connectors').
                  '. Each site needs a fresh enrolment code before it will report again.',
        );
    }

    private function authoriseOwner(): void
    {
        abort_unless(app(Membership::class)->isOwner(), 403);
    }
}
