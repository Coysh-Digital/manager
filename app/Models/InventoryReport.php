<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InventoryReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accepted operational-metadata report.
 *
 * The payload reached this table only by passing the inventory schema in the protocol package,
 * which is an allowlist: unknown keys are rejected outright, not stripped. Nothing here may
 * contain entries, assets, user records, credentials, licence keys or environment values.
 *
 * @property int $id
 * @property int $site_id
 * @property string $schema_version
 * @property array<string, mixed> $payload
 * @property Carbon $collected_at
 * @property Carbon $received_at
 * @property string $correlation_id
 */
class InventoryReport extends Model
{
    /** @use HasFactory<InventoryReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'schema_version',
        'payload',
        'collected_at',
        'received_at',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',

            // When the connector gathered the data, as opposed to when it arrived. A report that
            // sat in a queue for an hour should not read as current.
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Read a value from the payload by dotted path.
     */
    public function value(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }
}
