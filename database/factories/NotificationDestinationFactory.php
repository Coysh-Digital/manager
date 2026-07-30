<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\NotificationEvent;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDestination>
 */
class NotificationDestinationFactory extends Factory
{
    protected $model = NotificationDestination::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'transport' => NotificationDestination::TRANSPORT_EMAIL,
            'label' => 'Operations mailbox',
            'target' => 'ops@example.org',
            'events' => array_keys(NotificationEvent::catalogue()),
            'enabled' => true,
        ];
    }

    public function webhook(string $url = 'https://hooks.example.org/manager'): static
    {
        return $this->state(fn (): array => [
            'transport' => NotificationDestination::TRANSPORT_WEBHOOK,
            'label' => 'Team channel',
            'target' => $url,
            'signing_secret' => Str::random(48),
        ]);
    }

    /**
     * @param  list<string>  $events
     */
    public function subscribedTo(array $events): static
    {
        return $this->state(fn (): array => ['events' => $events]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function failing(int $failures = NotificationDestination::FAILURE_LIMIT): static
    {
        return $this->state(fn (): array => [
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'last_failure_reason' => 'Could not connect to the destination.',
        ]);
    }
}
