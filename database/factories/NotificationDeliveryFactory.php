<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\NotificationEvent;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_destination_id' => NotificationDestination::factory(),
            'event' => NotificationEvent::FINDING_OPENED,
            'subject' => 'Development mode is on in production',
            'outcome' => NotificationDelivery::OUTCOME_SENT,
            'status_code' => 200,
            'duration_ms' => 84,
            'correlation_id' => (string) Str::ulid(),
            'created_at' => now(),
        ];
    }

    public function failed(string $reason = 'Could not connect to the destination.'): static
    {
        return $this->state(fn (): array => [
            'outcome' => NotificationDelivery::OUTCOME_FAILED,
            'status_code' => null,
            'failure_reason' => $reason,
        ]);
    }
}
