<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\OutboundUrlGuard;
use App\Domain\Notifications\UnsafeDestinationException;
use App\Models\Membership;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Managing where notifications go.
 *
 * A webhook URL is validated by the same guard the transport uses, so a destination that would be
 * refused at send time is refused at the form instead - telling somebody now beats a silent failure
 * later. The transport re-checks anyway, because DNS changes.
 */
final class NotificationDestinationController
{
    public function __construct(
        private readonly OutboundUrlGuard $guard,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The Notifications tab of Settings.
     *
     * Readable by any member and writable only by an owner, which is what it was as a section of the
     * one settings screen. Each control inside is gated individually.
     */
    public function show(Organisation $organisation): View
    {
        return view('settings.notifications', [
            'organisation' => $organisation,
            'membership' => app(Membership::class),

            'destinations' => NotificationDestination::query()
                ->where('organisation_id', $organisation->id)
                ->with([
                    'deliveries' => fn ($query) => $query->latest('created_at')->limit(3),
                    'sites:id,name',
                ])
                ->orderBy('label')
                ->get(),
            'eventCatalogue' => NotificationEvent::catalogue(),

            // For the scope picker. Ordered by name, because somebody choosing which client's sites
            // a mailbox covers is looking for a name rather than scanning a fleet.
            'sites' => Site::query()
                ->where('organisation_id', $organisation->id)
                ->active()
                ->orderBy('name')
                ->get(['id', 'external_id', 'name', 'expected_domain']),
        ]);
    }

    public function store(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate([
            'transport' => ['required', 'in:email,webhook'],
            'label' => ['required', 'string', 'max:80'],
            'target' => ['required', 'string', 'max:512'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', array_keys(NotificationEvent::catalogue()))],

            /*
             | Scope. `all` is the default and writes no rows - see the pivot migration for why the
             | empty state is the whole of "every site" rather than a column saying so.
             |
             | `sites` is only read when the scope is `some`, and is required then: a destination
             | scoped to nothing would subscribe to five events and receive none of them, which is
             | a silent failure wearing the costume of a setting.
             */
            'scope' => ['nullable', 'in:all,some'],
            'sites' => ['array', 'required_if:scope,some'],
            'sites.*' => ['string', 'max:64'],
        ]);

        if ($validated['transport'] === NotificationDestination::TRANSPORT_EMAIL) {
            $request->validate(['target' => ['email']]);
        } else {
            try {
                // Checked here so somebody typing a private address is told now rather than wondering
                // later why nothing arrives.
                $this->guard->resolve($validated['target']);
            } catch (UnsafeDestinationException $e) {
                throw ValidationException::withMessages(['target' => $e->getMessage()]);
            }
        }

        /*
         | The scope, resolved before anything is written.
         |
         | Empty means every identifier was unrecognised, and the safe-looking thing to do there is
         | the dangerous one. No pivot rows is "all sites", so writing none would answer "only these
         | two" with "every site you have" - a scope that silently widened, on the one screen where
         | widening means telling somebody about a client that is not theirs.
         |
         | Refused rather than narrowed to nothing, which is equally wrong in the other direction: a
         | destination subscribed to five events and scoped to no site is a setting that can never
         | fire.
         */
        $scoped = [];

        if (($validated['scope'] ?? 'all') === 'some') {
            // Resolved against this organisation's own rows, so a ULID from somewhere else attaches
            // nothing rather than scoping a destination to a site its owner cannot see.
            $scoped = Site::query()
                ->where('organisation_id', $organisation->id)
                ->whereIn('external_id', $validated['sites'] ?? [])
                ->pluck('id')
                ->all();

            if ($scoped === []) {
                throw ValidationException::withMessages([
                    'sites' => 'None of those sites are in this fleet, so there is nothing to scope this destination to.',
                ]);
            }
        }

        $destination = NotificationDestination::query()->create([
            'organisation_id' => $organisation->id,
            'transport' => $validated['transport'],
            'label' => $validated['label'],
            'target' => $validated['target'],
            'events' => array_values($validated['events']),

            // Generated rather than asked for. A secret somebody chooses is a secret somebody reuses,
            // and the receiver only needs to be able to read it once.
            'signing_secret' => $validated['transport'] === NotificationDestination::TRANSPORT_WEBHOOK
                ? Str::random(48)
                : null,

            'created_by' => $request->user()->id,
            'created_by_label' => $request->user()->name ?: $request->user()->email,
        ]);

        if ($scoped !== []) {
            $destination->sites()->sync($scoped);
        }

        $this->audit->record(
            action: 'notification_destination.added',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'notification_destination',
            targetId: $destination->external_id,
            // The target is recorded because an operator needs to know where notifications started
            // going. The signing secret never is.
            after: [
                'transport' => $destination->transport,
                'target' => $destination->target,
                'events' => $destination->events,

                // Which sites, or all of them. Recorded because "why did this client hear about
                // that site" is a question the audit log should be able to answer.
                'sites' => $scoped === [] ? 'all' : $destination->sites()->pluck('name')->all(),
            ],
        );

        return back()->with(
            'status',
            $destination->isWebhook()
                ? 'Destination added. Its signing secret is shown once, below - save it now.'
                : 'Destination added.',
        )->with('freshSigningSecret', $destination->isWebhook() ? $destination->signing_secret : null);
    }

    /**
     * Send a test notification.
     *
     * Worth having: a destination that has never delivered anything is a destination nobody knows is
     * broken.
     */
    public function test(Request $request, NotificationDestination $destination): RedirectResponse
    {
        $this->authorise($destination);

        $this->notifier->dispatchTo($destination, new NotificationEvent(
            type: NotificationEvent::FINDING_OPENED,
            subject: 'Test notification from Manager',
            summary: 'If you are reading this, notifications are reaching this destination.',
            context: ['test' => true],
        ));

        return back()->with('status', 'Test queued. Check the delivery log below in a moment.');
    }

    public function destroy(Request $request, NotificationDestination $destination): RedirectResponse
    {
        $this->authorise($destination);

        $label = $destination->label;
        $external = $destination->external_id;
        $target = $destination->target;

        $destination->delete();

        $this->audit->record(
            action: 'notification_destination.removed',
            organisation: app(Organisation::class),
            actor: $request->user(),
            targetType: 'notification_destination',
            targetId: $external,
            before: ['label' => $label, 'target' => $target],
        );

        return back()->with('status', "Removed {$label}.");
    }

    private function authorise(NotificationDestination $destination): void
    {
        abort_if($destination->organisation_id !== app(Organisation::class)->id, 404);

        $this->authoriseOwner();
    }

    private function authoriseOwner(): void
    {
        abort_unless(app(Membership::class)->isOwner(), 403);
    }
}
