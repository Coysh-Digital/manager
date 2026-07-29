<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecoveryCode>
 */
class RecoveryCodeFactory extends Factory
{
    protected $model = RecoveryCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code_hash' => Hash::make(Str::random(16)),
            'used_at' => null,
        ];
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }
}
