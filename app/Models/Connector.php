<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConnectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paired connector's public identity.
 *
 * Only the public key is here. The private half is generated on the Craft installation and never
 * transmitted, so someone who takes a full copy of this database still cannot sign a request as
 * any site.
 *
 * @property int $id
 * @property int $site_id
 * @property string $public_key base64 Ed25519, 32 raw bytes
 * @property string|null $connector_version
 * @property string $state
 * @property string|null $submitted_domain
 * @property string|null $pending_reason
 * @property Carbon|null $paired_at
 * @property Carbon|null $key_rotated_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
class Connector extends Model
{
    /** @use HasFactory<ConnectorFactory> */
    use HasFactory;

    /** Pairing began but has not completed. */
    public const STATE_PENDING = 'pending';

    /** Paired from an unexpected domain; a user must confirm before it goes live. */
    public const STATE_PENDING_CONFIRMATION = 'pending_confirmation';

    /** The one connector permitted to speak for its site. */
    public const STATE_ACTIVE = 'active';

    /** Replaced by a newer pairing, deliberately authorised. */
    public const STATE_SUPERSEDED = 'superseded';

    /** Access withdrawn. Never returns to any other state. */
    public const STATE_REVOKED = 'revoked';

    public const REASON_DOMAIN_MISMATCH = 'domain_mismatch';

    protected $fillable = [
        'site_id',
        'public_key',
        'connector_version',
        'state',
        'submitted_domain',
        'pending_reason',
        'paired_at',
        'key_rotated_at',
        'last_seen_at',
        'revoked_at',
        'revoked_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'key_rotated_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function isRevoked(): bool
    {
        return $this->state === self::STATE_REVOKED;
    }

    public function awaitsConfirmation(): bool
    {
        return $this->state === self::STATE_PENDING_CONFIRMATION;
    }
}
