<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\BackupArtifactFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property string|null $upload_reference
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
 * @property string $format_version
 * @property string|null $manifest
 * @property string|null $manifest_sha256
 * @property string|null $manifest_signature
 * @property string|null $artifact_id
 * @property int|null $sequence
 * @property string|null $artifact_sha256
 * @property string|null $artifact_crc32c
 * @property int|null $artifact_bytes
 * @property string|null $upload_mode
 * @property string|null $stage
 * @property Carbon|null $stage_at
 */
class BackupArtifact extends Model
{
    use HasExternalId;

    /** @use HasFactory<BackupArtifactFactory> */
    use HasFactory;

    /** Declared, bytes not yet uploaded. */
    public const STATE_PENDING = 'pending';

    /**
     * Bytes present, integrity not yet confirmed by this platform.
     *
     * Only reachable on the direct-to-object-storage path. When bytes stream through the platform they
     * are hashed on the way past, so storing and verifying happen in the same instant and this state is
     * skipped. When they go straight to storage the connector can only report that it finished, and
     * calling that `stored` would be claiming a check nobody had run.
     */
    public const STATE_UPLOADED = 'uploaded';

    /** Bytes present and verified against the checksum the connector declared. */
    public const STATE_STORED = 'stored';

    /** The upload did not complete, or did not verify. */
    public const STATE_FAILED = 'failed';

    /** Past retention, bytes not yet removed. Distinct from deleted, because the sweep runs later. */
    public const STATE_EXPIRED = 'expired';

    /** Removed. The row survives, because the audit trail should still show it existed. */
    public const STATE_DELETED = 'deleted';

    /** Key sealed to this platform and re-wrapped for storage. We can read these. */
    public const FORMAT_V1 = 'v1';

    /** Key sealed only to the organisation's recovery keys. We cannot read these. */
    public const FORMAT_V2 = 'v2';

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
            'stage_at' => 'datetime',

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

    /**
     * @return HasMany<BackupArtifactRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BackupArtifactRecipient::class);
    }

    /**
     * @return HasMany<BackupEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(BackupEvent::class)->orderBy('recorded_at');
    }

    public function isStored(): bool
    {
        return $this->state === self::STATE_STORED;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isUploaded(): bool
    {
        return $this->state === self::STATE_UPLOADED;
    }

    /**
     * Whether this platform is able to decrypt it.
     *
     * The question worth asking directly rather than inferring from a version string at each call site,
     * because the answer is the whole difference between the two formats and the interface has to say
     * it out loud for v1 artifacts.
     */
    public function isReadableByPlatform(): bool
    {
        return $this->format_version === self::FORMAT_V1;
    }

    public function isZeroKnowledge(): bool
    {
        return $this->format_version === self::FORMAT_V2;
    }

    /**
     * Whether the bytes are still there to be read.
     */
    public function isRetrievable(): bool
    {
        return $this->isStored() && $this->storage_key !== null;
    }

    /**
     * Whether this row is a record of a backup that never existed.
     *
     * A declaration that failed, or one that was never uploaded and has since been failed by the
     * prune command, holds no ciphertext and no key. It is a row on a screen and nothing else, and
     * until there was a way to remove one they accumulated for ever: the delete button asks
     * {@see self::isRetrievable()}, which these can never satisfy, and nothing else ever deleted
     * them.
     *
     * Deliberately not expired or deleted artifacts. Those may well have held bytes, and their
     * tombstone - the state, the reason, the destroyed key - is the record that they did. This is
     * for the ones where there is nothing to be the record of.
     */
    public function neverStored(): bool
    {
        return $this->storage_key === null
            && in_array($this->state, [self::STATE_PENDING, self::STATE_FAILED], true);
    }

    /**
     * The checksum the uploaded bytes must have.
     *
     * The two formats upload different things. A v1 artifact is a bare encrypted stream, so its
     * ciphertext hash covers the whole upload. A v2 artifact is an envelope wrapped around that same
     * stream, so the ciphertext hash covers only part of the file and comparing against it would
     * reject every valid upload. Asked as one question here rather than branched on at each call site,
     * because getting it wrong in one place produces a check that silently passes.
     */
    public function expectedUploadSha256(): string
    {
        return $this->isZeroKnowledge() && $this->artifact_sha256 !== null
            ? $this->artifact_sha256
            : $this->ciphertext_sha256;
    }

    public function expectedUploadBytes(): int
    {
        return $this->isZeroKnowledge() && $this->artifact_bytes !== null
            ? $this->artifact_bytes
            : $this->ciphertext_bytes;
    }

    /**
     * The same question as {@see self::expectedUploadBytes()}, asked of a whole set in SQL.
     *
     * How many bytes an artifact costs is one rule, and it has to be the same rule wherever it is
     * asked. It was not: admission control measured `artifact_bytes` - the whole file, settled to its
     * real size when the upload completed - while every meter summed `ciphertext_bytes`, which is the
     * encrypted stream without its envelope, is declared by the connector, and is never checked
     * against anything. A quota admitted on one number and reported on a different, unverified one.
     *
     * Expressed here rather than repeated at each call site, for the reason the docblock above
     * already gives: getting it wrong in one place produces a check that silently passes.
     *
     * Collections should use `expectedUploadBytes()` directly. This exists for the sums that cannot
     * hydrate the rows first - a quota check runs on the connector's upload path and must not load an
     * organisation's entire artifact history to add up a column.
     */
    public static function storedBytesExpression(): Expression
    {
        return DB::raw(sprintf(
            'CASE WHEN format_version = %s AND artifact_bytes IS NOT NULL THEN artifact_bytes ELSE ciphertext_bytes END',
            DB::getPdo()->quote(self::FORMAT_V2),
        ));
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
     * full value is available where it matters - the retrieval command verifies against all of it.
     */
    public function shortChecksum(): string
    {
        return substr($this->plaintext_sha256, 0, 12);
    }
}
