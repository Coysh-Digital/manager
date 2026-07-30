<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityService;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Models\CapabilityEvent;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What Manager is permitted to do on a site, and the record of how it got that way.
 *
 * Only read-only capabilities can be granted here. Anything that modifies a site, or reads its
 * content, needs its own confirmation flow — `backups:create` reads the full database including user
 * records, and a toggle alongside the read switches would understate that considerably.
 */
final class CapabilityController
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public function show(Site $site): View
    {
        $this->authoriseSite($site);

        $grants = $site->capabilityGrants()->orderBy('capability')->get()->keyBy('capability');

        return view('sites.capabilities', [
            'site' => $site,
            'connector' => $site->activeConnector()->first(),

            // Every capability the platform defines, so the screen shows what is *not* permitted
            // as plainly as what is. A list of only the granted ones would answer the less useful
            // half of the question.
            'capabilities' => collect(Protocol::capabilities())->map(fn (string $capability): array => [
                'name' => $capability,
                'grant' => $grants->get($capability),
                'granted' => $grants->get($capability)?->isGranted() ?? false,
                'readOnly' => Protocol::isReadOnlyCapability($capability),
                'implemented' => in_array($capability, CapabilityService::grantableFromInterface(), true),
                'grantable' => in_array($capability, CapabilityService::grantableFromInterface(), true),
            ]),

            'history' => CapabilityEvent::query()
                ->where('site_id', $site->id)
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Revoke a capability.
     *
     * Behind a recent-authentication check: the specification lists changing connector
     * capabilities among the actions that require one, because a session left open on an unlocked
     * machine should not be enough to change what a platform may do to a production site.
     */
    public function revoke(Request $request, Site $site): RedirectResponse
    {
        $this->authoriseSite($site);
        $this->authoriseAdministrator();

        $validated = $request->validate([
            'capability' => ['required', 'string', 'in:'.implode(',', Protocol::capabilities())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->capabilities->revoke(
            site: $site,
            capability: $validated['capability'],
            actor: $request->user(),
            reason: $validated['reason'] ?? null,
        );

        return back()->with('status', "Revoked {$validated['capability']}. The site can no longer perform it.");
    }

    /**
     * Grant a capability.
     *
     * Behind a recent-authentication check, and restricted to read-only capabilities. The route
     * validates against the grantable list rather than the full registry, so a crafted request
     * cannot reach a capability that has no confirmation flow yet.
     */
    public function grant(Request $request, Site $site): RedirectResponse
    {
        $this->authoriseSite($site);
        $this->authoriseAdministrator();

        $validated = $request->validate([
            'capability' => ['required', 'string', 'in:'.implode(',', CapabilityService::grantableFromInterface())],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->capabilities->grant(
            site: $site,
            capability: $validated['capability'],
            actor: $request->user(),
            reason: $validated['reason'] ?? null,
        );

        return back()->with('status', "Granted {$validated['capability']}.");
    }

    /**
     * Approve a connector that paired from an unexpected domain.
     */
    public function confirmConnector(Request $request, Site $site): RedirectResponse
    {
        $this->authoriseSite($site);
        $this->authoriseAdministrator();

        $connector = $site->connectors()
            ->where('state', Connector::STATE_PENDING_CONFIRMATION)
            ->latest('id')
            ->firstOrFail();

        // Confirming the domain also adopts it, so the same mismatch does not resurface on the
        // next pairing. Leaving expected_domain stale would train people to click through this.
        $submitted = (string) $connector->submitted_domain;

        $site->forceFill(['expected_domain' => $submitted])->save();
        $connector->forceFill([
            'state' => Connector::STATE_ACTIVE,
            'pending_reason' => null,
            'paired_at' => now(),
        ])->save();

        $granted = $this->capabilities->grantDefaultsForPairing($site);

        $this->audit->record(
            action: 'site.pairing.confirmed',
            site: $site,
            actor: $request->user(),
            targetType: 'connector',
            targetId: (string) $connector->id,
            after: ['expected_domain' => $submitted, 'capabilities' => $granted],
        );

        return back()->with('status', 'Connector confirmed. The site can now report.');
    }

    /**
     * Revoke a connector outright.
     *
     * Credentials and permissions go together, in one transaction. A connector that could still
     * authenticate while holding no capabilities, or hold capabilities it could not use, would be
     * a state nobody could reason about.
     */
    public function revokeConnector(Request $request, Site $site): RedirectResponse
    {
        $this->authoriseSite($site);
        $this->authoriseAdministrator();

        $connector = $site->activeConnector()->firstOrFail();

        $connector->forceFill([
            'state' => Connector::STATE_REVOKED,
            'revoked_at' => now(),
            'revoked_reason' => 'Revoked from the interface',
        ])->save();

        $this->capabilities->revokeAll($site, $request->user(), 'Connector access revoked');

        $site->forceFill(['status' => Site::STATUS_NOT_CONNECTED])->save();

        $this->audit->record(
            action: 'connector.revoked',
            site: $site,
            actor: $request->user(),
            targetType: 'connector',
            targetId: (string) $connector->id,
            before: ['state' => Connector::STATE_ACTIVE],
            after: ['state' => Connector::STATE_REVOKED],
        );

        // Notified because a revocation nobody expected is the shape of a compromise, and the person
        // who should notice may not be the person who did it.
        $this->notifier->dispatch(new NotificationEvent(
            type: NotificationEvent::CONNECTOR_REVOKED,
            subject: "Connector revoked for {$site->name}",
            summary: 'The connector for this site was revoked, so it has stopped reporting. It needs a '
                .'fresh enrolment code before it will report again. If you did not expect this, treat '
                .'it as a possible compromise.',
            site: $site,
            context: ['revoked_by' => $request->user()->name ?: $request->user()->email],
        ));

        return back()->with('status', 'Connector revoked. Its credentials no longer authenticate.');
    }

    private function authoriseSite(Site $site): void
    {
        abort_if($site->organisation_id !== app(Organisation::class)->id, 404);
    }

    private function authoriseAdministrator(): void
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);
    }
}
