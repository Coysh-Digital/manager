<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LoginReport;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginReport>
 */
class LoginReportFactory extends Factory
{
    protected $model = LoginReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'schema_version' => 'logins.v1',
            'payload' => self::samplePayload(),
            'window_hours' => 24,
            'failed_attempts' => 3,
            'accounts_with_failures' => 1,
            'accounts_locked' => 0,
            'admin_accounts_affected' => 0,
            'last_failure_at' => now()->subHours(2),
            'collected_at' => now(),
            'received_at' => now(),
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function samplePayload(): array
    {
        return [
            'schema_version' => 'logins.v1',
            'collected_at' => now()->getTimestamp(),
            'window_hours' => 24,
            'failed_attempts' => 3,
            'accounts_with_failures' => 1,
            'accounts_locked' => 0,
            'admin_accounts_affected' => 0,
            'last_failure_at' => now()->subHours(2)->getTimestamp(),
        ];
    }

    /**
     * The shape that needs a person: somebody locked out, and administrators being targeted.
     */
    public function underAttack(): static
    {
        return $this->state(fn (): array => [
            'failed_attempts' => 214,
            'accounts_with_failures' => 6,
            'accounts_locked' => 2,
            'admin_accounts_affected' => 1,
            'last_failure_at' => now()->subMinutes(3),
            'payload' => [
                ...self::samplePayload(),
                'failed_attempts' => 214,
                'accounts_with_failures' => 6,
                'accounts_locked' => 2,
                'admin_accounts_affected' => 1,
            ],
        ]);
    }
}
