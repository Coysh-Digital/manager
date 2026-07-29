<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->company(),
            'expected_domain' => $domain,
            'environment' => 'production',
            'status' => Site::STATUS_NEVER_CONNECTED,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (): array => [
            'status' => Site::STATUS_CONNECTED,
            'craft_version' => '5.10.8.1',
            'craft_edition' => 'pro',
            'php_version' => '8.3.14',
            'connector_version' => '1.0.0',
            'last_seen_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
