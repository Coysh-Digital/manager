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

    /**
     * Why a volume was not measured, in a sentence rather than a word.
     *
     * Three unrelated situations shared one grey badge until `system.v2` separated them, and they
     * want three different responses: nothing at all, a larger budget, and somebody fixing a
     * configuration. Reporting them identically is why "Not measured" read as a fault when most of
     * the time it is not one.
     *
     * Null for a volume that was measured, and for a report from a connector too old to say — an
     * older plugin sends `system.v1`, which has no reason to give, and inventing one from the shape
     * of what arrived would be guessing on a screen.
     *
     * @param  array<string, mixed>  $volume
     */
    public static function unmeasuredReason(array $volume): ?string
    {
        if (($volume['measured'] ?? true) !== false) {
            return null;
        }

        return match ($volume['unmeasured_reason'] ?? null) {
            'remote' => 'On remote storage. Walking it would mean an API call per directory, billed to the site, to fill in a dashboard — so it is left alone.',
            'timeout' => 'The walk ran out of the connector\'s time budget, so the figures beside it are a floor rather than a total. Raise storageWalkSeconds in the site\'s config/manager-connector.php to measure more of it.',
            'unreadable' => 'The path resolved but could not be opened. Unlike the other two this is a fault worth looking at: check the volume\'s filesystem settings and that the web user can read it.',
            default => null,
        };
    }

    /**
     * Whether a volume's bytes sit on the server's own disk.
     *
     * Null when the connector did not say, which is every `system.v1` report. A screen showing
     * "Remote" against a local volume would be claiming the bytes are not on a disk they are on, so
     * not knowing has to stay distinguishable from knowing.
     *
     * @param  array<string, mixed>  $volume
     */
    public static function isLocalVolume(array $volume): ?bool
    {
        return match ($volume['location'] ?? null) {
            'local' => true,
            'remote' => false,
            default => null,
        };
    }

    /**
     * Whether this report says where any of its volumes live.
     *
     * The column is drawn only when something can fill it. A column of em-dashes teaches people to
     * ignore a column.
     */
    public function reportsVolumeLocation(): bool
    {
        foreach ($this->volumes() as $volume) {
            if (self::isLocalVolume($volume) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything here is stored somewhere other than the site's own disk.
     *
     * Worth saying once under the table rather than per row: the disk figures above it describe the
     * server, and a remote volume's bytes are not on it.
     */
    public function hasRemoteVolumes(): bool
    {
        foreach ($this->volumes() as $volume) {
            if (self::isLocalVolume($volume) === false) {
                return true;
            }
        }

        return false;
    }
}
