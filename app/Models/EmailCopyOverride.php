<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\EmailCopy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Wording somebody has changed, for one email.
 *
 * A row exists only where a default has been overridden, so the absence of one *is* the default —
 * see {@see EmailCopy}, which is the only thing that should read this. Reverting is a delete; there
 * is no flag to unset and no row that exists but does not apply.
 *
 * Installation-scoped, like {@see MailSetting} and for the same reason: what an invitation says is a
 * property of the installation that sends it. See the migration.
 *
 * @property string $key
 * @property string|null $subject
 * @property string|null $body
 * @property int|null $updated_by
 * @property Carbon $updated_at
 */
final class EmailCopyOverride extends Model
{
    /**
     * Nothing is mass-assignable.
     *
     * Writes go through EmailCopy::put(), which is the only place that knows which keys are
     * editable and which tokens the wording may contain. A `create()` from a request payload would
     * be a way past both of those checks.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
