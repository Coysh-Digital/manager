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
 */
class Organisation extends Model
{
    use HasExternalId;

    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

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
