<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver a notification.
 *
 * Recorded because an unnoticed delivery failure is worse than no notifications at all: the operator
 * believes they are covered when they are not.
 *
 * @property int $id
 * @property string $event
 * @property string $outcome
 * @property int|null $status_code
 * @property string|null $failure_reason
 */
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_FAILED = 'failed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<NotificationDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(NotificationDestination::class, 'notification_destination_id');
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::OUTCOME_SENT;
    }
}
