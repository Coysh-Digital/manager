<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CapabilityGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The current position of one capability on one site.
 *
 * Absence of a row means denied, so a site starts able to do nothing at all. The history of how it
 * got here lives in {@see CapabilityEvent}.
 *
 * @property int $id
 * @property int $site_id
 * @property string $capability
 * @property string $state
 * @property Carbon|null $granted_at
 * @property Carbon|null $revoked_at
 * @property string|null $reason
 */
class CapabilityGrant extends Model
{
    /** @use HasFactory<CapabilityGrantFactory> */
    use HasFactory;

    public const STATE_GRANTED = 'granted';

    public const STATE_REVOKED = 'revoked';

    protected $fillable = [
        'site_id',
        'capability',
        'state',
        'granted_by',
        'granted_at',
        'revoked_by',
        'revoked_at',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * Who turned this on.
     *
     * Nullable, and legitimately so: the pairing defaults are granted by the system rather than by a
     * person, and a grant whose author left is still a grant.
     *
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isGranted(): bool
    {
        return $this->state === self::STATE_GRANTED;
    }
}
