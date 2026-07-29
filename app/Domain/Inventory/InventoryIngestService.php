<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Models\InventoryReport;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts an operational-metadata report, or refuses it.
 *
 * The schema is an allowlist and unknown keys are **rejected**, not stripped. Stripping would let
 * a connector start collecting more than was agreed and have the platform quietly discard the
 * evidence; rejecting makes the drift visible the moment it appears, which is the only way anyone
 * finds out.
 *
 * Nothing that reaches this table may contain entries, assets, user records, credentials, licence
 * keys or environment values. The schema is where that is enforced; tests/Invariants is where it
 * is proved.
 */
final class InventoryIngestService
{
    public const SCHEMA = 'inventory.v1';

    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * Validate a payload without storing it.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string> empty when acceptable
     */
    public function validate(array $payload): array
    {
        return SchemaValidator::forSchema(self::SCHEMA)->validate($payload);
    }

    /**
     * Store an already-validated payload and refresh the site's summary columns.
     *
     * @param  array<string, mixed>  $payload
     */
    public function store(Site $site, array $payload): InventoryReport
    {
        return DB::transaction(function () use ($site, $payload): InventoryReport {
            $now = Carbon::now();

            $report = InventoryReport::query()->create([
                'site_id' => $site->id,
                'schema_version' => self::SCHEMA,
                'payload' => $payload,

                // When the connector gathered it, not when it arrived. A report that sat in a
                // queue for an hour must not read as current.
                'collected_at' => Carbon::createFromTimestamp($payload['collected_at']),
                'received_at' => $now,
                'correlation_id' => $this->correlationId->get(),
            ]);

            // Denormalised so the fleet table can sort and filter without reaching into jsonb on
            // every row. The report stays the record of what was actually received.
            $site->forceFill([
                'craft_version' => $payload['craft']['version'] ?? null,
                'craft_edition' => $payload['craft']['edition'] ?? null,
                'php_version' => $payload['php']['version'] ?? null,
                'connector_version' => $payload['connector']['version'] ?? null,
                'last_inventory_at' => $now,
                'last_seen_at' => $now,
                'status' => Site::STATUS_CONNECTED,
            ])->save();

            return $report;
        });
    }
}
