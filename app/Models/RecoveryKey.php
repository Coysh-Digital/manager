<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Backup\RecoveryKeyService;
use App\Support\Concerns\HasExternalId;
use Database\Factories\RecoveryKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public key that backups may be encrypted to.
 *
 * The organisation holds the other half. This platform does not, has never had it, and has no column
 * it could be put in - which is the property everything else about backups now rests on.
 *
 * The state machine is small and one edge of it is missing on purpose:
 *
 * ```
 *   submit ──▶ pending_proof ──▶ active ──▶ revoked (terminal)
 * ```
 *
 * There is no way back from `revoked`, and no way to delete a row at all. Both are the same reasoning:
 * an artifact's manifest names the fingerprints it was sealed to, and a fingerprint nothing in the
 * database explains is worse than a revoked key that does. It also means somebody who managed to add a
 * key of their own cannot tidy up after themselves.
 *
 * @property int $id
 * @property string $external_id
 * @property int $organisation_id
 * @property string $state
 * @property string $public_key
 * @property string $fingerprint
 * @property string|null $label
 * @property string|null $challenge
 * @property string|null $challenge_response_hash
 * @property Carbon|null $challenge_expires_at
 * @property int $challenge_attempts
 * @property Carbon|null $activated_at
 * @property Carbon|null $last_proved_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $created_by_id
 * @property string|null $created_by_label
 * @property int|null $revoked_by_id
 * @property string|null $revoked_by_label
 */
class RecoveryKey extends Model
{
    use HasExternalId;

    /** @use HasFactory<RecoveryKeyFactory> */
    use HasFactory;

    /** Submitted, not yet demonstrated to be usable. Never a recipient of anything. */
    public const STATE_PENDING_PROOF = 'pending_proof';

    /** Proven. Every new backup is sealed to it. */
    public const STATE_ACTIVE = 'active';

    /** Excluded from new backups. Terminal; artifacts already taken keep their copy. */
    public const STATE_REVOKED = 'revoked';

    /** How long an enrolment challenge stands before it has to be reissued. */
    public const CHALLENGE_TTL_MINUTES = 60;

    /** Wrong answers before a challenge is burned and must be reissued. */
    public const MAX_CHALLENGE_ATTEMPTS = 5;

    /** After this long without a demonstration, the interface starts asking. */
    public const REPROVE_AFTER_DAYS = 180;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'challenge_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'last_proved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'challenge_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function isAwaitingProof(): bool
    {
        return $this->state === self::STATE_PENDING_PROOF;
    }

    public function isRevoked(): bool
    {
        return $this->state === self::STATE_REVOKED;
    }

    public function challengeHasExpired(): bool
    {
        return $this->challenge_expires_at === null || $this->challenge_expires_at->isPast();
    }

    public function challengeIsExhausted(): bool
    {
        return $this->challenge_attempts >= self::MAX_CHALLENGE_ATTEMPTS;
    }

    /**
     * Whether the organisation should be asked to demonstrate this key again.
     *
     * Nothing is disabled when this returns true. A key that stops working because nobody logged in for
     * six months would be a worse failure than the one being guarded against; this only drives a prompt.
     */
    public function isDueReproof(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $last = $this->last_proved_at ?? $this->activated_at;

        return $last === null || $last->addDays(self::REPROVE_AFTER_DAYS)->isPast();
    }

    /**
     * @param  Builder<RecoveryKey>  $query
     * @return Builder<RecoveryKey>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::STATE_ACTIVE);
    }

    /**
     * The fingerprint in six groups, which is how it is displayed everywhere.
     *
     * Read from the column rather than recomputed, because this is presentation. Anywhere the value is
     * about to be *used* - served to a site, compared against a manifest - it is recomputed from the
     * key material instead. See {@see RecoveryKeyService::activeFor()}.
     */
    public function shortFingerprint(): string
    {
        // The last two groups are enough to tell one of an organisation's keys from another at a
        // glance in a list, and the full value is always one click away.
        return '…'.substr($this->fingerprint, -9);
    }
}
