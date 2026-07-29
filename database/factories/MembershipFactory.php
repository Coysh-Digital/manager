<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'user_id' => User::factory(),
            'role' => Membership::ROLE_MEMBER,
            'revoked_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => Membership::ROLE_OWNER]);
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role' => Membership::ROLE_ADMIN]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
