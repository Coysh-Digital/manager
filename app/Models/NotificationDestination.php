<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\NotificationEvent;
use App\Support\Concerns\HasExternalId;
use Database\Factories\NotificationDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Somewhere notifications are sent.
 *
 * @property int $id
 * @property string $external_id
 * @property int $organisation_id
 * @property string $transport
 * @property string $label
 * @property string $target
 * @property list<string> $events
 * @property string|null $signing_secret
 * @property bool $enabled
 * @property int $consecutive_failures
 * @property Carbon|null $last_delivery_at
 */
class NotificationDestination extends Model
{
    use HasExternalId;

    /** @use HasFactory<NotificationDestinationFactory> */
    use HasFactory;

    public const TRANSPORT_EMAIL = 'email';

    public const TRANSPORT_WEBHOOK = 'webhook';

    /**
     * After this many consecutive failures a destination stops being tried.
     *
     * A dead endpoint retried forever is a slow leak of worker time and log noise, and the failure
     * itself stops being visible among the repetitions.
     */
    public const FAILURE_LIMIT = 10;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'enabled' => 'boolean',

            // A webhook signing secret is a credential: a database dump alone must not let somebody
            // forge notifications that a receiver would accept.
            'signing_secret' => 'encrypted',

            'last_delivery_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isWebhook(): bool
    {
        return $this->transport === self::TRANSPORT_WEBHOOK;
    }

    /**
     * Whether this destination has asked for an event.
     *
     * An unknown event type never matches, so removing one from the catalogue silently stops
     * delivering it rather than throwing on a subscription nobody can see any more.
     */
    public function wants(string $event): bool
    {
        return NotificationEvent::isKnown($event) && in_array($event, $this->events, true);
    }

    /**
     * Whether this destination should still be attempted.
     */
    public function isDeliverable(): bool
    {
        return $this->enabled && $this->consecutive_failures < self::FAILURE_LIMIT;
    }

    public function hasFailedTooOften(): bool
    {
        return $this->consecutive_failures >= self::FAILURE_LIMIT;
    }
}
