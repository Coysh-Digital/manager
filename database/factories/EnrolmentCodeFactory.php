<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnrolmentCode;
use App\Models\Site;
use coyshdigital\managerprotocol\Nonce;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrolmentCode>
 */
class EnrolmentCodeFactory extends Factory
{
    protected $model = EnrolmentCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'code_hash' => Nonce::hashEnrolmentCode(Nonce::generateEnrolmentCode()),
            'expires_at' => now()->addSeconds((int) config('manager.enrolment.ttl')),
        ];
    }

    /**
     * Store the hash of a code the caller is holding, so it can be presented for pairing.
     */
    public function forCode(string $code): static
    {
        return $this->state(fn (): array => ['code_hash' => Nonce::hashEnrolmentCode($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => ['consumed_at' => now()]);
    }
}
