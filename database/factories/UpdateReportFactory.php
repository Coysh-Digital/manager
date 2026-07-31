<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\UpdateReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UpdateReport>
 */
class UpdateReportFactory extends Factory
{
    protected $model = UpdateReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'schema_version' => 'updates.v1',
            'payload' => self::samplePayload(),
            'craft_update_available' => true,
            'craft_security_release' => true,
            'craft_current' => '5.6.2',
            'craft_latest' => '5.6.4',
            'plugin_updates' => 1,
            'plugin_security_releases' => 0,
            'abandoned_plugins' => 0,
            'checked_at' => now(),
            'received_at' => now(),
            'correlation_id' => (string) Str::ulid(),
        ];
    }

    /**
     * Kept in step with the protocol package's own fixture: if the schema tightens, this stops
     * validating rather than quietly producing invalid data.
     *
     * @return array<string, mixed>
     */
    public static function samplePayload(): array
    {
        return [
            'schema_version' => 'updates.v1',
            'checked_at' => now()->getTimestamp(),
            'craft' => [
                'current' => '5.6.2',
                'latest' => '5.6.4',
                'update_available' => true,
                'releases_behind' => 2,
                'security_release_available' => true,
            ],
            'plugins' => [
                [
                    'handle' => 'formie',
                    'name' => 'Formie',
                    'current' => '3.0.11',
                    'latest' => '3.0.14',
                    'update_available' => true,
                    'security_release_available' => false,
                ],
            ],
        ];
    }

    /**
     * A report from a 1.8 connector, carrying the release notes `updates.v2` added.
     *
     * Used to prove they never reach `update_reports.payload`, so it is deliberately the payload as
     * a site would send it rather than as the platform would store it.
     *
     * @return array<string, mixed>
     */
    public static function sampleV2Payload(): array
    {
        return [
            ...self::samplePayload(),
            'schema_version' => 'updates.v2',
            'plugins' => [
                [
                    'handle' => 'formie',
                    'name' => 'Formie',
                    'current' => '3.0.11',
                    'latest' => '3.0.14',
                    'update_available' => true,
                    'security_release_available' => false,
                    'releases' => [
                        [
                            'version' => '3.0.14',
                            'notes' => "### Fixed\n- Fixed an authentication bypass in the submissions endpoint.",
                            'critical' => true,
                            'date' => '2026-07-14',
                        ],
                        [
                            'version' => '3.0.12',
                            'notes' => "### Added\n- Added a submission retention setting.",
                            'critical' => false,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function upToDate(): static
    {
        return $this->state(fn (): array => [
            'craft_update_available' => false,
            'craft_security_release' => false,
            'plugin_updates' => 0,
            'plugin_security_releases' => 0,
            'payload' => [
                'schema_version' => 'updates.v1',
                'checked_at' => now()->getTimestamp(),
                'craft' => ['current' => '5.6.4', 'latest' => '5.6.4', 'update_available' => false],
                'plugins' => [],
            ],
        ]);
    }
}
