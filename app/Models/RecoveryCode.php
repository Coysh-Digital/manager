<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecoveryCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use second-factor fallback.
 *
 * Stored hashed and shown in plaintext exactly once, at generation. Used codes are marked rather
 * than deleted so that "three left, one used on Tuesday" is answerable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon|null $used_at
 */
class RecoveryCode extends Model
{
    /** @use HasFactory<RecoveryCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
