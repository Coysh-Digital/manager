<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One observation about a backup.
 *
 * Distinct from an audit event, and the line between them is worth stating because it is easy to blur:
 * **decisions and accesses are audited; observations are recorded here.** Somebody requesting a backup,
 * an artifact being stored, a backup being downloaded — those are things a person or a system chose to
 * do, they need to be provable, and they go into the hash-chained log. "The dump started" is a report
 * about the world, it may be lost in transit, and it does not need to be provable.
 *
 * That distinction is also why nothing may decide anything on these rows. The vocabulary is closed and
 * the reporter is recorded, so a reader can tell what the platform saw for itself from what a site told
 * it. A site can lie, or fall silent, and neither should be able to move a state machine.
 *
 * @property int $id
 * @property int|null $backup_artifact_id
 * @property int|null $remote_job_id
 * @property int $organisation_id
 * @property int $site_id
 * @property string $event
 * @property string $source
 * @property string $outcome
 * @property string|null $detail
 * @property int|null $bytes
 * @property int|null $duration_ms
 * @property string|null $correlation_id
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 */
class BackupEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /** A person or the scheduler asked for a backup. */
    public const REQUESTED = 'requested';

    /** The site collected the job. */
    public const CLAIMED = 'claimed';

    /** The site began dumping. The longest phase, and the only one worth a report of its own. */
    public const DUMP_STARTED = 'dump_started';

    /** Reserved. Declared so a later connector can send it without a platform change. */
    public const DUMP_COMPLETED = 'dump_completed';

    /** Reserved. */
    public const ENCRYPTED = 'encrypted';

    /** Reserved. */
    public const UPLOAD_STARTED = 'upload_started';

    /** The platform accepted a declaration. */
    public const DECLARED = 'declared';

    /** The bytes finished arriving, wherever they went. */
    public const UPLOAD_COMPLETED = 'upload_completed';

    /** The platform confirmed the stored bytes are the declared ones. */
    public const INTEGRITY_VERIFIED = 'integrity_verified';

    /** An expiry was computed and recorded. */
    public const RETENTION_SET = 'retention_set';

    /** Past retention, awaiting the sweep. */
    public const EXPIRED = 'expired';

    /** Removed from storage. */
    public const DELETED = 'deleted';

    /** Something went wrong. The detail says what, in language safe to store. */
    public const FAILED = 'failed';

    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_CONNECTOR = 'connector';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BackupArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(BackupArtifact::class, 'backup_artifact_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Whether the site's clock disagreed with ours by enough to be worth showing.
     *
     * A timeline that renders a site's own timestamps without saying they are the site's own is a
     * timeline that can be reordered by a wrong clock. Ordering always uses ours; this decides whether
     * to mention the difference.
     */
    public function clockSkewSeconds(): int
    {
        return (int) abs($this->recorded_at->diffInSeconds($this->occurred_at));
    }

    public function hasNotableClockSkew(): bool
    {
        return $this->clockSkewSeconds() > 300;
    }
}
