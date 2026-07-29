<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryReport;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryReport>
 */
class InventoryReportFactory extends Factory
{
    protected $model = InventoryReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'schema_version' => 'inventory.v1',
            'payload' => self::samplePayload(),
            'collected_at' => now(),
            'received_at' => now(),
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    /**
     * A payload that satisfies the inventory allowlist.
     *
     * Kept in step with the protocol package's own fixture: if the schema tightens, this stops
     * validating and the factory has to be updated rather than quietly producing invalid data.
     *
     * @return array<string, mixed>
     */
    public static function samplePayload(): array
    {
        return [
            'schema_version' => 'inventory.v1',
            'collected_at' => now()->getTimestamp(),
            'connector' => ['version' => '1.0.0'],
            'craft' => ['version' => '5.10.8.1', 'edition' => 'pro'],
            'php' => ['version' => '8.3.14'],
            'database' => ['engine' => 'mysql', 'version' => '8.0.36'],
            'environment' => 'production',
        ];
    }
}
