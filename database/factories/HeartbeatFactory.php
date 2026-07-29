<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Heartbeat;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Heartbeat>
 */
class HeartbeatFactory extends Factory
{
    protected $model = Heartbeat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'connector_version' => '1.0.0',
            'source_ip' => fake()->ipv4(),
            'correlation_id' => (string) Str::ulid(),
            'received_at' => now(),
        ];
    }
}
