<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant.
 *
 * The self-hosted edition usually has exactly one. The boundary exists from the start anyway,
 * because retrofitting tenant isolation is how cross-tenant leaks happen.
 *
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property bool $mfa_required
 * @property int $backup_retention_days
 * @property int $backup_keep_count
 * @property int $backup_retention_weeks
 * @property int $backup_retention_months
 * @property string $backup_format_floor
 * @property string $timezone
 */
class Organisation extends Model
{
    use HasExternalId;

    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    /**
     * Mirrors of the column defaults, so a freshly created instance reads the same as a reloaded one.
     *
     * A database default is applied by the database and never travels back into the model that caused
     * the insert, so without these `$organisation->backup_format_floor` is null on the instance
     * `create()` returned and 'v1' the moment anybody reloads it. That is exactly the kind of
     * difference that makes a comparison behave one way in a request and another in a test.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mfa_required' => false,
        'backup_retention_days' => 30,
        'backup_keep_count' => 3,
        'backup_retention_weeks' => 4,
        'backup_retention_months' => 12,
        'backup_format_floor' => 'v1',
        'timezone' => 'UTC',
    ];

    protected $fillable = [
        'name',
        'mfa_required',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mfa_required' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Members whose access has not been revoked.
     *
     * @return HasMany<Membership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('revoked_at');
    }
}
