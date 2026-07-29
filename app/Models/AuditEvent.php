<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\AuditRecorder;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the append-only audit history.
 *
 * Writes go through {@see AuditRecorder}, which assigns the sequence number and
 * computes the chain hash. The model refuses to update or delete so that a stray `save()` fails in
 * the application rather than at the database trigger, where the error would be less obvious.
 *
 * @property int $id
 * @property int|null $organisation_id
 * @property int $seq
 * @property string $actor_type
 * @property string|null $actor_label
 * @property string $action
 * @property string $outcome
 * @property string $prev_hash
 * @property string $hash
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const ACTOR_USER = 'user';

    public const ACTOR_CONNECTOR = 'connector';

    public const ACTOR_SYSTEM = 'system';

    /** The predecessor of the first event in any chain. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit events are append-only and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit events are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS;
    }
}
