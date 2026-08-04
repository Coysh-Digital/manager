<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\WebhookTransport;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Support\CorrelationId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Delivers one notification to one destination.
 *
 * Queued so a slow destination cannot delay whatever triggered it - a hanging webhook must not make a
 * connector's report time out, because that would turn a misconfigured destination into a site that
 * appears to have stopped reporting.
 *
 * Retries are deliberately few and spread out. A destination that is down stays down for minutes, not
 * milliseconds, and hammering it achieves nothing except filling the delivery log.
 */
final class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int> seconds between attempts
     */
    public array $backoff = [30, 300];

    /**
     * Give up rather than retrying for hours. A notification about something that happened yesterday
     * is not worth the delivery attempt.
     */
    public int $timeout = 30;

    public function __construct(
        private readonly NotificationDestination $destination,
        private readonly NotificationEvent $event,
    ) {}

    public function handle(
        WebhookTransport $webhooks,
        EmailTransport $emails,
        CorrelationId $correlationId,
    ): void {
        $destination = $this->destination->fresh();

        // Re-read rather than trusted from the constructor: it may have been disabled, or its
        // subscription changed, between queueing and running.
        if ($destination === null || ! $destination->isDeliverable()) {
            return;
        }

        // A test delivery is sent regardless of subscriptions: somebody testing a destination wants to
        // know whether it is reachable, not whether it is subscribed. Everything else still has to
        // have been asked for.
        $isTest = ($this->event->context['test'] ?? false) === true;

        if (! $isTest && ! $destination->wants($this->event->type)) {
            return;
        }

        $result = $destination->isWebhook()
            ? $webhooks->send($destination, $this->event)
            : $emails->send($destination, $this->event);

        NotificationDelivery::query()->create([
            'notification_destination_id' => $destination->id,
            'event' => $this->event->type,
            'subject' => $this->event->subject,
            'outcome' => $result['outcome'],
            'status_code' => $result['status_code'],
            'failure_reason' => $result['failure_reason'],
            'duration_ms' => $result['duration_ms'],
            'correlation_id' => $correlationId->get(),
            'created_at' => Carbon::now(),
        ]);

        if ($result['outcome'] === NotificationDelivery::OUTCOME_SENT) {
            $destination->forceFill([
                'last_delivery_at' => Carbon::now(),
                // Reset, so an endpoint that recovers is not eventually disabled by history.
                'consecutive_failures' => 0,
                'last_failure_reason' => null,
            ])->save();

            return;
        }

        $destination->forceFill([
            'last_failure_at' => Carbon::now(),
            'consecutive_failures' => $destination->consecutive_failures + 1,
            'last_failure_reason' => $result['failure_reason'],
        ])->save();

        // Thrown so the queue retries. Once the failure count reaches the limit the guard at the top
        // of this method stops further attempts entirely.
        throw new \RuntimeException('Notification delivery failed: '.($result['failure_reason'] ?? 'unknown'));
    }
}
