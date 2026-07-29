<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CapabilityEvent;
use App\Models\CapabilityGrant;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CapabilityEvent>
 */
class CapabilityEventFactory extends Factory
{
    protected $model = CapabilityEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'capability' => 'inventory:read',
            'previous_state' => null,
            'new_state' => CapabilityGrant::STATE_GRANTED,
            'actor_label' => fake()->name(),
            'ip' => fake()->ipv4(),
            'correlation_id' => (string) Str::ulid(),
            'created_at' => now(),
        ];
    }
}
