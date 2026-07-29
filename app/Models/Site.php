<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A managed Craft installation.
 *
 * @property int $id
 * @property string $external_id
 * @property int $organisation_id
 * @property string $name
 * @property string $expected_domain
 * @property string $environment
 * @property string $status
 * @property string|null $craft_version
 * @property string|null $craft_edition
 * @property string|null $php_version
 * @property string|null $connector_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_inventory_at
 * @property Carbon|null $archived_at
 */
class Site extends Model
{
    use HasExternalId;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_NEVER_CONNECTED = 'never_connected';

    public const STATUS_NOT_CONNECTED = 'not_connected';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'organisation_id',
        'name',
        'expected_domain',
        'environment',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_inventory_at' => 'datetime',
            'archived_at' => 'datetime',
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
     * @return HasMany<Connector, $this>
     */
    public function connectors(): HasMany
    {
        return $this->hasMany(Connector::class);
    }

    /**
     * The one connector currently permitted to speak for this site.
     *
     * A partial unique index guarantees there is at most one, so this is a genuine has-one rather
     * than a "first of many" that happens to work today.
     *
     * @return HasOne<Connector, $this>
     */
    public function activeConnector(): HasOne
    {
        return $this->hasOne(Connector::class)->where('state', Connector::STATE_ACTIVE);
    }

    /**
     * @return HasMany<CapabilityGrant, $this>
     */
    public function capabilityGrants(): HasMany
    {
        return $this->hasMany(CapabilityGrant::class);
    }

    /**
     * @return HasMany<InventoryReport, $this>
     */
    public function inventoryReports(): HasMany
    {
        return $this->hasMany(InventoryReport::class);
    }

    /**
     * @return HasMany<Heartbeat, $this>
     */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(Heartbeat::class);
    }

    /**
     * Capabilities currently granted, as a plain list.
     *
     * @return list<string>
     */
    public function grantedCapabilities(): array
    {
        return $this->capabilityGrants()
            ->where('state', CapabilityGrant::STATE_GRANTED)
            ->orderBy('capability')
            ->pluck('capability')
            ->all();
    }

    public function hasCapability(string $capability): bool
    {
        return $this->capabilityGrants()
            ->where('capability', $capability)
            ->where('state', CapabilityGrant::STATE_GRANTED)
            ->exists();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
