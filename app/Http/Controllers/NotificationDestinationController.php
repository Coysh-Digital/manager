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
                ->with(['deliveries' => fn ($query) => $query->latest('created_at')->limit(3)])
                ->orderBy('label')
                ->get(),
            'eventCatalogue' => NotificationEvent::catalogue(),
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
