<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProbeReport;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProbeReport>
 */
class ProbeReportFactory extends Factory
{
    protected $model = ProbeReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'payload' => self::samplePayload(),
            'status' => 200,
            'redirects_to_https' => true,
            'answers_everything' => false,
            'exposed_count' => 0,
            'probed_at' => now(),
        ];
    }

    /**
     * A site with nothing wrong with it.
     *
     * The default deliberately: a factory whose default state opens findings makes every unrelated
     * test that happens to create one start failing for reasons its author did not choose.
     *
     * @return array<string, mixed>
     */
    public static function samplePayload(): array
    {
        return [
            'status' => 200,
            'headers' => [
                'strict-transport-security' => 'max-age=31536000; includeSubDomains',
                'content-security-policy' => "default-src 'self'",
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'referrer-policy' => 'strict-origin-when-cross-origin',
                'permissions-policy' => 'geolocation=(), camera=()',
                'content-encoding' => 'gzip',
            ],
            'redirects_to_https' => true,
            'answers_everything' => false,
        ];
    }

    /**
     * A site serving none of the headers.
     */
    public function bare(): self
    {
        return $this->state(function (): array {
            $payload = self::samplePayload();
            $payload['headers'] = ['server' => 'nginx/1.24.0', 'x-powered-by' => 'PHP/8.3.14'];

            return ['payload' => $payload];
        });
    }

    /**
     * A site with its environment file in the webroot.
     */
    public function exposing(string $path = '/.env'): self
    {
        return $this->state(function () use ($path): array {
            $payload = self::samplePayload();
            $payload['exposed'] = [['path' => $path, 'status' => 200, 'bytes' => 812]];

            return ['payload' => $payload, 'exposed_count' => 1];
        });
    }

    /**
     * A site that answers 200 for anything, where no exposure result means anything.
     */
    public function answersEverything(): self
    {
        return $this->state(function (): array {
            $payload = self::samplePayload();
            $payload['answers_everything'] = true;

            return ['payload' => $payload, 'answers_everything' => true];
        });
    }
}
