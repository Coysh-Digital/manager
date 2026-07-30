<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BackupArtifact;
use App\Models\Organisation;
use App\Models\Site;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupArtifact>
 */
class BackupArtifactFactory extends Factory
{
    protected $model = BackupArtifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'site_id' => $site,

            // Resolved from the site rather than created independently, so a factory-made artifact
            // never ends up attributed to a different organisation from its own site.
            'organisation_id' => fn (array $attributes): int => Site::query()
                ->whereKey($attributes['site_id'])
                ->value('organisation_id') ?? Organisation::factory()->create()->id,

            'state' => BackupArtifact::STATE_STORED,
            'storage_key' => 'org-1/site-1/2026/07/'.fake()->uuid().'.artifact',
            'storage_disk' => 'backups',

            'scheme' => ArtifactStream::SCHEME,
            'stream_header' => base64_encode(random_bytes(24)),
            'wrapped_key' => 'sh1:'.base64_encode(random_bytes(56)),
            'wrapping_key_id' => 'sh1:0123456789ab',

            'ciphertext_sha256' => hash('sha256', fake()->uuid()),
            'plaintext_sha256' => hash('sha256', fake()->uuid()),
            'ciphertext_bytes' => 18874368,
            'plaintext_bytes' => 18857984,
            'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,

            'engine' => 'mysql',
            'engine_version' => '8.0.36',
            'compressed' => true,

            'taken_at' => now()->subHours(2),
            'stored_at' => now()->subHours(2),
            'verified_at' => now()->subHours(2),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'state' => BackupArtifact::STATE_PENDING,
            'storage_key' => null,
            'storage_disk' => null,
            'stored_at' => null,
            'verified_at' => null,
            'expires_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function deleted(): static
    {
        return $this->state(fn (): array => [
            'state' => BackupArtifact::STATE_DELETED,
            'deleted_at' => now()->subHour(),
            'deleted_reason' => 'Retention',
            'storage_key' => null,
            'wrapped_key' => null,
        ]);
    }
}
