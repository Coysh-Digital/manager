<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LoginReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Counts of failed control-panel sign-ins on one site.
 *
 * Four integers and a timestamp. There is nowhere here to put a username, an email address, a user
 * id or a source address, and that is the design rather than an omission: a record of who tried to
 * sign in as whom on somebody else's website is not something this platform has a stated purpose
 * for collecting.
 *
 * The figures are a floor, not a total. Craft resets a user's failed-attempt counter on a successful
 * sign-in, so an attacker who eventually gets in erases their own tally — which is why every screen
 * showing these numbers says so beside them.
 *
 * @property int $id
 * @property int $site_id
 * @property string $schema_version
 * @property array<string, mixed> $payload
 * @property int $window_hours
 * @property int $failed_attempts
 * @property int $accounts_with_failures
 * @property int $accounts_locked
 * @property int $admin_accounts_affected
 * @property Carbon|null $last_failure_at
 * @property Carbon $collected_at
 * @property Carbon $received_at
 * @property string $correlation_id
 */
class LoginReport extends Model
{
    /** @use HasFactory<LoginReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_failure_at' => 'datetime',
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
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
     * Whether this looks like something rather than background noise.
     *
     * A handful of failures a day is somebody mistyping their password. The thresholds are
     * deliberately unexciting: a locked account means somebody cannot work right now, and attempts
     * against an administrator account are a different signal from attempts against an author.
     */
    public function isNotable(): bool
    {
        return $this->accounts_locked > 0
            || $this->admin_accounts_affected > 0
            || $this->failed_attempts >= 20;
    }

    public function tone(): string
    {
        return match (true) {
            $this->accounts_locked > 0 => 'bad',
            $this->isNotable() => 'warn',
            $this->failed_attempts > 0 => 'info',
            default => 'ok',
        };
    }
}
