<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's access to an organisation.
 *
 * Revocation sets a timestamp rather than deleting the row, so audit records keep pointing at
 * something real. Access checks read {@see self::isActive()}, which means revocation takes effect
 * on the very next request rather than whenever the session happens to expire.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $revoked_at
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = [
        'organisation_id',
        'user_id',
        'role',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Whether this membership may manage sites, capabilities and other members.
     */
    public function canAdminister(): bool
    {
        return $this->isActive()
            && in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function isOwner(): bool
    {
        return $this->isActive() && $this->role === self::ROLE_OWNER;
    }
}
