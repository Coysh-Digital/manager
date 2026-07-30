<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\BackupArtifactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One encrypted database backup.
 *
 * This row describes an artifact. It never contains one, and it holds nothing that would help anybody
 * take another: no credentials, no connection details, no path on the site's own disk.
 *
 * @property int $id
 * @property string $external_id
 * @property int $site_id
 * @property int $organisation_id
 * @property int|null $remote_job_id
 * @property string $state
 * @property string|null $storage_key
 * @property string|null $storage_disk
 * @property string $scheme
 * @property string $stream_header
 * @property string|null $wrapped_key
 * @property string|null $wrapping_key_id
 * @property string $ciphertext_sha256
 * @property string $plaintext_sha256
 * @property int $ciphertext_bytes
 * @property int $plaintext_bytes
 * @property int $chunk_bytes
 * @property string|null $engine
 * @property string|null $engine_version
 * @property bool $compressed
 * @property Carbon $taken_at
 * @property Carbon|null $stored_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_reason
 * @property string|null $failure_reason
 */
class BackupArtifact extends Model
{
    use HasExternalId;

    /** @use HasFactory<BackupArtifactFactory> */
    use HasFactory;

    /** Declared, bytes not yet uploaded. */
    public const STATE_PENDING = 'pending';

    /** Bytes present and verified against the checksum the connector declared. */
    public const STATE_STORED = 'stored';

    /** The upload did not complete, or did not verify. */
    public const STATE_FAILED = 'failed';

    /** Removed. The row survives, because the audit trail should still show it existed. */
    public const STATE_DELETED = 'deleted';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'compressed' => 'boolean',
            'taken_at' => 'datetime',
            'stored_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'deleted_at' => 'datetime',

            // Wrapped once by the key service and encrypted again here. A database dump alone does not
            // open an artifact even if object storage was taken at the same time.
            'wrapped_key' => 'encrypted',
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
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<RemoteJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(RemoteJob::class, 'remote_job_id');
    }

    public function isStored(): bool
    {
        return $this->state === self::STATE_STORED;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /**
     * Whether the bytes are still there to be read.
     */
    public function isRetrievable(): bool
    {
        return $this->isStored() && $this->storage_key !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<BackupArtifact>  $query
     * @return Builder<BackupArtifact>
     */
    public function scopeStored(Builder $query): Builder
    {
        return $query->where('state', self::STATE_STORED);
    }

    /**
     * A size a person can read.
     */
    public function humanSize(): string
    {
        $bytes = $this->plaintext_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return $unit === 'B'
                    ? "{$bytes} B"
                    : number_format($bytes, $bytes < 10 ? 1 : 0)." {$unit}";
            }

            $bytes /= 1024;
        }

        return "{$bytes} B";
    }

    /**
     * The first twelve characters of the plaintext checksum.
     *
     * Enough for a person to compare two artifacts at a glance, which is what the interface needs. The
     * full value is available where it matters — the retrieval command verifies against all of it.
     */
    public function shortChecksum(): string
    {
        return substr($this->plaintext_sha256, 0, 12);
    }
}
