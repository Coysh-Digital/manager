<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RuntimeReport;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RuntimeReport>
 */
class RuntimeReportFactory extends Factory
{
    protected $model = RuntimeReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'schema_version' => 'system.v1',
            'payload' => self::samplePayload(),
            'storage_bytes' => 4_509_715_660,
            'disk_free_bytes' => 21_474_836_480,
            'disk_total_bytes' => 107_374_182_400,
            'response_mean_ms' => 84,
            'response_p95_ms' => 212,
            'collected_at' => now(),
            'received_at' => now(),
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    /**
     * Kept in step with the protocol package's own schema: if the allowlist tightens, this stops
     * validating rather than quietly producing invalid data.
     *
     * @return array<string, mixed>
     */
    public static function samplePayload(): array
    {
        return [
            'schema_version' => 'system.v1',
            'collected_at' => now()->getTimestamp(),
            'storage' => [
                'volumes' => [
                    ['handle' => 'images', 'bytes' => 4_294_967_296, 'files' => 18_402, 'measured' => true],
                    ['handle' => 'documents', 'bytes' => 214_748_364, 'files' => 903, 'measured' => true],
                ],
                'storage_bytes' => 0,
                'disk_free_bytes' => 21_474_836_480,
                'disk_total_bytes' => 107_374_182_400,
            ],
            'php' => [
                'version' => '8.3.14',
                'sapi' => 'fpm-fcgi',
                'memory_limit_bytes' => 268_435_456,
                'max_execution_time' => 120,
                'upload_max_filesize_bytes' => 67_108_864,
                'post_max_size_bytes' => 67_108_864,
                'max_input_vars' => 1000,
                'opcache_enabled' => true,
                'opcache_memory_used_bytes' => 94_371_840,
                'opcache_memory_free_bytes' => 39_845_888,
                'extensions' => 47,
            ],
            'response' => [
                'samples' => 200,
                'window_hours' => 6,
                'mean_ms' => 84.3,
                'p50_ms' => 61.0,
                'p95_ms' => 212.4,
                'max_ms' => 1180.9,
            ],
        ];
    }

    /**
     * A volume too large to walk inside the time budget.
     *
     * Reported as unmeasured rather than as empty: a partial figure presented as a total is how
     * somebody concludes an asset volume was emptied overnight.
     */
    public function withUnmeasuredVolume(): static
    {
        return $this->state(function (): array {
            $payload = self::samplePayload();
            $payload['storage']['volumes'][] = [
                'handle' => 'archive', 'bytes' => 0, 'measured' => false,
            ];

            return ['payload' => $payload];
        });
    }

    /**
     * What a connector sending `system.v3` reports, on a site with nothing wrong with it.
     *
     * A separate state rather than the default, deliberately. The default has to keep describing a
     * site whose connector is *not* yet on v3, because most of a fleet is not on the day a version
     * ships - and a default that answered these would make every test that happens to create a
     * report assert against a shape half the fleet does not send.
     *
     * @param  array<string, mixed>  $overrides  merged into the v3 sections
     * @return array<string, mixed>
     */
    public static function craftPayload(array $overrides = []): array
    {
        $payload = self::samplePayload();
        $payload['schema_version'] = 'system.v3';

        $payload['php']['missing_extensions'] = [];
        $payload['php']['image_driver'] = 'imagick';

        $payload['craft'] = [
            'deprecation_count' => 0,
            'session_rows' => 29,
            'security_key_set' => true,
            'cp_trigger_default' => true,
        ];
        $payload['database'] = ['size_bytes' => 21_390_950];
        $payload['paths'] = ['storage' => true, 'cpresources' => true, 'config_project' => true];

        foreach ($overrides as $section => $values) {
            $payload[$section] = is_array($values) && is_array($payload[$section] ?? null)
                ? [...$payload[$section], ...$values]
                : $values;
        }

        return $payload;
    }

    /**
     * A site whose connector reports `system.v3`.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function describingCraft(array $overrides = []): static
    {
        return $this->state(fn (): array => [
            'schema_version' => 'system.v3',
            'payload' => self::craftPayload($overrides),
        ]);
    }
}
