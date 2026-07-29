<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\HasExternalId;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A platform account.
 *
 * @property int $id
 * @property string $external_id
 * @property string $name
 * @property string $email
 * @property string|null $totp_secret decrypted transparently by the cast
 * @property Carbon|null $totp_confirmed_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_authenticated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasExternalId;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',

            // Encrypted at rest. A database dump on its own does not yield working second factors.
            'totp_secret' => 'encrypted',

            'totp_confirmed_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /**
     * This user's live membership of an organisation, if any.
     */
    public function membershipFor(Organisation $organisation): ?Membership
    {
        return $this->memberships()
            ->where('organisation_id', $organisation->id)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Whether a second factor has been enrolled *and* proved.
     *
     * A secret that was generated but never confirmed with a valid code does not count. Otherwise
     * starting enrolment and abandoning it would satisfy an organisation's MFA requirement while
     * leaving the account effectively single-factor.
     */
    public function hasConfirmedTotp(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }

    /**
     * How many recovery codes remain unused.
     */
    public function unusedRecoveryCodeCount(): int
    {
        return $this->recoveryCodes()->whereNull('used_at')->count();
    }
}
