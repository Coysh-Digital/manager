<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrolmentCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use code that pairs one connector to one site.
 *
 * The plaintext is shown to the user once and never stored. Consuming a code is deliberately not
 * done through this model: see the atomic statement in the pairing service, which is what makes
 * "single-use" survive two connectors racing with the same code.
 *
 * @property int $id
 * @property int $site_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_ip
 * @property int $attempts
 * @property int|null $replace_authorised_by
 * @property Carbon|null $replace_authorised_at
 */
class EnrolmentCode extends Model
{
    /** @use HasFactory<EnrolmentCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'code_hash',
        'expires_at',
        'created_by',
        'replace_authorised_by',
        'replace_authorised_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'replace_authorised_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether a user has explicitly authorised this code to displace a live connector.
     */
    public function authorisesReplacement(): bool
    {
        return $this->replace_authorised_by !== null;
    }
}
