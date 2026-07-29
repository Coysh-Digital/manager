<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CapabilityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability transition, kept forever.
 *
 * The specification asks for user, timestamp, previous state, new state, reason, source IP and
 * correlation ID on every capability change. That is this table. The grants table only knows where
 * things stand now.
 *
 * @property int $id
 * @property int $site_id
 * @property string $capability
 * @property string|null $previous_state
 * @property string $new_state
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $correlation_id
 */
class CapabilityEvent extends Model
{
    /** @use HasFactory<CapabilityEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'capability',
        'previous_state',
        'new_state',
        'actor_id',
        'actor_label',
        'reason',
        'ip',
        'user_agent',
        'correlation_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
