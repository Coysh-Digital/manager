<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CapabilityGrant;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapabilityGrant>
 */
class CapabilityGrantFactory extends Factory
{
    protected $model = CapabilityGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'capability' => 'inventory:read',
            'state' => CapabilityGrant::STATE_GRANTED,
            'granted_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'state' => CapabilityGrant::STATE_REVOKED,
            'revoked_at' => now(),
        ]);
    }

    public function capability(string $capability): static
    {
        return $this->state(fn (): array => ['capability' => $capability]);
    }
}
