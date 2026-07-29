<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RemoteJob>
 */
class RemoteJobFactory extends Factory
{
    protected $model = RemoteJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => Jobs::INVENTORY_REFRESH,
            'schema_version' => 'v1',
            'parameters' => [],
            'state' => Jobs::STATE_QUEUED,
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    public function claimed(): static
    {
        return $this->state(fn (): array => [
            'state' => Jobs::STATE_CLAIMED,
            'claimed_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'claim_count' => 1,
        ]);
    }

    /**
     * Claimed, but past its maximum runtime with nothing reported.
     */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'state' => Jobs::STATE_CLAIMED,
            'claimed_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(30),
            'claim_count' => 1,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'state' => Jobs::STATE_SUCCEEDED,
            'finished_at' => now(),
            'result' => ['reported' => true],
        ]);
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }
}
