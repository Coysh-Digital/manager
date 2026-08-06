<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Works out who wants to hear about an event, and queues the deliveries.
 *
 * Queued rather than sent inline. A notification is triggered while handling a connector's report, and
 * a slow or hanging destination must not be able to make a site's report time out - which would turn a
 * misconfigured webhook into a site that appears to have stopped reporting.
 */
final class Notifier
{
    /**
     * Dispatch an event to every destination that asked for it.
     *
     * @return int how many deliveries were queued
     */
    public function dispatch(NotificationEvent $event, ?Organisation $organisation = null): int
    {
        $organisation ??= $event->site?->organisation_id === null
            ? null
            : Organisation::query()->find($event->site->organisation_id);

        if ($organisation === null) {
            return 0;
        }

        $destinations = $this->destinationsFor($organisation, $event->type, $event->site);

        foreach ($destinations as $destination) {
            DeliverNotification::dispatch($destination, $event);
        }

        return $destinations->count();
    }

    /**
     * Queue a delivery to one specific destination, bypassing subscription matching.
     *
     * Used by the test button. It deliberately ignores the subscription list: somebody testing a
     * destination wants to know whether it is reachable, not whether it is subscribed.
     */
    public function dispatchTo(NotificationDestination $destination, NotificationEvent $event): void
    {
        DeliverNotification::dispatch($destination, $event);
    }

    /**
     * Who asked for this event, about this site.
     *
     * Two filters, and they are different questions. `wants()` is the subscription - which kinds of
     * thing this destination cares about. `covers()` is the scope - which sites it is responsible
     * for. A destination narrowed to one client's sites still subscribes to backup failures; it
     * simply should not hear about somebody else's.
     *
     * The scope is eager-loaded so that a fleet-wide event over a dozen destinations is one extra
     * query rather than a dozen.
     *
     * @return Collection<int, NotificationDestination>
     */
    private function destinationsFor(Organisation $organisation, string $type, ?Site $site): Collection
    {
        return NotificationDestination::query()
            ->where('organisation_id', $organisation->id)
            ->where('enabled', true)
            // A destination that has failed too often is skipped rather than retried forever. The
            // failures stay on the record; it simply stops consuming workers.
            ->where('consecutive_failures', '<', NotificationDestination::FAILURE_LIMIT)
            ->with('sites:id')
            ->get()
            ->filter(fn (NotificationDestination $destination): bool => $destination->wants($type)
                && $destination->covers($site))
            ->values();
    }
}
