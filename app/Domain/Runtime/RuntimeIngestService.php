<?php

declare(strict_types=1);

namespace App\Domain\Runtime;

use App\Models\RuntimeReport;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a runtime report, or refuses it.
 *
 * Same allowlist discipline as everything else that arrives from a site: unknown keys are rejected
 * rather than stripped, so a connector that started sending a filesystem path — because somebody
 * added one in a hurry — is refused rather than quietly having it dropped and never noticing.
 */
final class RuntimeIngestService
{
    public const SCHEMA = 'system.v1';

    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        return SchemaValidator::forSchema(self::SCHEMA)->validate($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(Site $site, array $payload): RuntimeReport
    {
        return DB::transaction(function () use ($site, $payload): RuntimeReport {
            $now = Carbon::now();

            return RuntimeReport::query()->create([
                'site_id' => $site->id,
                'schema_version' => (string) ($payload['schema_version'] ?? self::SCHEMA),
                'payload' => $payload,

                // Denormalised for sorting. Rounded to whole milliseconds because the figures are
                // sampled from at most two hundred requests, and a decimal place would imply a
                // precision the sample size does not support.
                'storage_bytes' => $this->totalStorageBytes($payload),
                'disk_free_bytes' => $payload['storage']['disk_free_bytes'] ?? null,
                'disk_total_bytes' => $payload['storage']['disk_total_bytes'] ?? null,
                'response_mean_ms' => isset($payload['response']['mean_ms'])
                    ? (int) round((float) $payload['response']['mean_ms'])
                    : null,
                'response_p95_ms' => isset($payload['response']['p95_ms'])
                    ? (int) round((float) $payload['response']['p95_ms'])
                    : null,

                'collected_at' => Carbon::createFromTimestamp((int) ($payload['collected_at'] ?? $now->getTimestamp())),
                'received_at' => $now,
                'correlation_id' => $this->correlationId->get(),
            ]);
        });
    }

    /**
     * Everything Craft is holding on disk: the asset volumes plus its own storage directory.
     *
     * Volumes that could not be walked contribute nothing rather than a guess. The report says
     * separately that some were unmeasured, so the total is understood as a floor rather than
     * silently wrong.
     *
     * @param  array<string, mixed>  $payload
     */
    private function totalStorageBytes(array $payload): ?int
    {
        $volumes = $payload['storage']['volumes'] ?? null;
        $storage = $payload['storage']['storage_bytes'] ?? null;

        if (! is_array($volumes) && $storage === null) {
            return null;
        }

        $total = (int) ($storage ?? 0);

        foreach (is_array($volumes) ? $volumes : [] as $volume) {
            if (($volume['measured'] ?? true) !== false) {
                $total += (int) ($volume['bytes'] ?? 0);
            }
        }

        return $total;
    }
}
