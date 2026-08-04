<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One copy of an artifact's data-encryption key, sealed to one recovery key.
 *
 * A v2 artifact carries one of these per recovery key that was active when the backup was taken, so
 * losing one key costs nothing while any of the others survive.
 *
 * The `wrapped_key` column is **not** encrypted at the model boundary, and that is a decision rather
 * than an omission. `BackupArtifact::wrapped_key` is cast to `encrypted`, and copying the pattern here
 * would look like the careful thing to do. It would be worse than useless: this platform cannot open a
 * sealed box either way, so the cast adds no confidentiality - and it would make a customer's ability
 * to restore depend on our `APP_KEY` surviving, quietly recreating the exact dependency the format
 * exists to remove.
 *
 * @property int $id
 * @property int $backup_artifact_id
 * @property string $fingerprint
 * @property string $public_key
 * @property string $wrapped_key
 * @property string|null $label
 * @property int|null $recovery_key_id
 */
class BackupArtifactRecipient extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<BackupArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(BackupArtifact::class, 'backup_artifact_id');
    }

    /**
     * The enrolled key this was sealed to, if the record still exists.
     *
     * Nullable on purpose. An artifact must stay describable after the key record is gone, and the
     * fingerprint on this row is what explains it.
     *
     * @return BelongsTo<RecoveryKey, $this>
     */
    public function recoveryKey(): BelongsTo
    {
        return $this->belongsTo(RecoveryKey::class, 'recovery_key_id');
    }
}
