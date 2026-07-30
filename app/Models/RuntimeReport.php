<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RuntimeReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accepted runtime report: disk usage, PHP limits, sampled response timings.
 *
 * The payload passed the system.v1 allowlist, which permits byte counts and numeric limits and has
 * nowhere to put a path, a file name or a directory listing.
 *
 * @property int $id
 * @property int $site_id
 * @property string $schema_version
 * @property array<string, mixed> $payload
 * @property int|null $storage_bytes
 * @property int|null $disk_free_bytes
 * @property int|null $disk_total_bytes
 * @property int|null $response_p95_ms
 * @property int|null $response_mean_ms
 * @property Carbon $collected_at
 * @property Carbon $received_at
 * @property string $correlation_id
 */
class RuntimeReport extends Model
{
    /** @use HasFactory<RuntimeReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
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

    public function value(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }

    /**
     * Asset volumes, largest first.
     *
     * @return list<array<string, mixed>>
     */
    public function volumes(): array
    {
        $volumes = $this->value('storage.volumes', []);

        if (! is_array($volumes)) {
            return [];
        }

        // usort reindexes, so the result is already a list.
        usort($volumes, static fn (array $a, array $b): int => ($b['bytes'] ?? 0) <=> ($a['bytes'] ?? 0));

        return $volumes;
    }

    /**
     * How full the disk is, as a percentage, or null when the connector could not read it.
     *
     * Remote and containerised filesystems often cannot answer, and a made-up figure on a screen
     * somebody sizes a server from is worse than an em-dash.
     */
    public function diskUsedPercent(): ?float
    {
        $total = $this->disk_total_bytes;
        $free = $this->disk_free_bytes;

        if ($total === null || $free === null || $total <= 0) {
            return null;
        }

        return round((1 - $free / $total) * 100, 1);
    }

    /**
     * Whether any volume ran out of its measuring budget.
     *
     * Worth surfacing, because a partial figure presented as a total is how somebody concludes that
     * 80% of an asset volume was deleted overnight.
     */
    public function hasUnmeasuredVolumes(): bool
    {
        foreach ($this->volumes() as $volume) {
            if (($volume['measured'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }
}
