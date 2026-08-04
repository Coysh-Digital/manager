<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Audit\AuditRecorder;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\User;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\ProtocolException;
use coyshdigital\managerprotocol\RecoveryProof;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enrolling, proving and revoking the keys that backups are encrypted to.
 *
 * The whole zero-knowledge arrangement reduces to one question this class answers: *which public keys
 * does a site seal its next backup to?* Get that wrong and everything else is theatre, because an
 * artifact sealed to a key the platform chose is an artifact the platform can read.
 *
 * So three things happen here that would be easy to leave out:
 *
 *  - **A key is not usable until somebody has proven they hold the other half.** Structural validation
 *    of an X25519 public key establishes almost nothing - any 32 bytes is a valid one. The only
 *    meaningful check is a decryption, and requiring it turns enrolment into a restore rehearsal, which
 *    is the one thing that reliably catches a key nobody can actually find.
 *
 *  - **Fingerprints are recomputed from key material before they are served.** The stored fingerprint is
 *    an index, not evidence. A site pins against these values; if somebody with database access could
 *    change the fingerprint beside a key, they could make a site accept a key it was never shown.
 *
 *  - **Revocation is forward-only and nothing is ever deleted.** An artifact's manifest names the
 *    fingerprints it was sealed to, and those rows are the only thing that explains them a year later.
 *
 * What this class deliberately cannot do: produce a private key, store one, or add a recipient the
 * organisation did not enrol.
 */
final class RecoveryKeyService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly BackupKeypair $legacyKeypair,
    ) {}

    /**
     * Register a candidate public key and issue its proof challenge.
     *
     * @throws RecoveryKeyRejectedException
     */
    public function submit(
        Organisation $organisation,
        string $publicKey,
        ?string $label,
        ?User $actor = null,
    ): RecoveryKey {
        $publicKey = trim($publicKey);

        if (! Sealing::isValidBoxPublicKey($publicKey)) {
            throw new RecoveryKeyRejectedException(
                'That is not a recovery public key. It should be 44 characters, or the whole block '
                .'beginning "-----BEGIN MANAGER RECOVERY KEY-----".'
            );
        }

        if (! Sealing::isUsableBoxPublicKey($publicKey)) {
            // A small-order point. Anything sealed to one can be opened by anybody, and libsodium would
            // refuse to seal to it anyway - but it would refuse on the night a site had already dumped
            // its database to disk, rather than here, next to the field it was pasted into.
            throw new RecoveryKeyRejectedException(
                'That key cannot be used for encryption. It is a special value that would produce a '
                .'backup anybody could read.'
            );
        }

        if ($this->isPlatformKey($publicKey)) {
            // Somebody has pasted the platform's own artifact key, almost certainly by copying it out
            // of an environment file while looking for something to paste. Accepting it would silently
            // restore exactly the arrangement recovery keys exist to end.
            throw new RecoveryKeyRejectedException(
                'That is this platform\'s own encryption key. Registering it would make every backup '
                .'readable by us, which is the opposite of what a recovery key is for.'
            );
        }

        try {
            $fingerprint = KeyFingerprint::forRecoveryKey($publicKey);
        } catch (ProtocolException) {
            throw new RecoveryKeyRejectedException('That is not a recovery public key.');
        }

        if ($label !== null && mb_strlen($label) > 120) {
            throw new RecoveryKeyRejectedException('A label must be 120 characters or fewer.');
        }

        return DB::transaction(function () use ($organisation, $publicKey, $fingerprint, $label, $actor): RecoveryKey {
            $organisation = Organisation::query()->whereKey($organisation->id)->lockForUpdate()->firstOrFail();

            $existing = RecoveryKey::query()
                ->where('organisation_id', $organisation->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($existing !== null) {
                // Scoped to this organisation. Telling a submitter that a key is registered *somewhere*
                // would answer a question about another tenant.
                throw new RecoveryKeyRejectedException(
                    $existing->isRevoked()
                        ? 'That key was revoked for this organisation and cannot be re-enrolled. Generate a new one.'
                        : 'That key is already registered here, as '.($existing->label ?? $fingerprint).'.'
                );
            }

            $live = RecoveryKey::query()
                ->where('organisation_id', $organisation->id)
                ->whereIn('state', [RecoveryKey::STATE_ACTIVE, RecoveryKey::STATE_PENDING_PROOF])
                ->count();

            if ($live >= Protocol::MAX_BACKUP_RECIPIENTS) {
                // Every recipient is another copy of the key that opens every backup, so the list is
                // bounded by the wire format rather than by taste.
                throw new RecoveryKeyRejectedException(
                    'This organisation already has '.Protocol::MAX_BACKUP_RECIPIENTS.' recovery keys, '
                    .'which is the most a backup can be sealed to. Revoke one first.'
                );
            }

            $key = RecoveryKey::query()->create([
                'organisation_id' => $organisation->id,
                'state' => RecoveryKey::STATE_PENDING_PROOF,
                'public_key' => $publicKey,
                'fingerprint' => $fingerprint,
                'label' => $label,
                'created_by_id' => $actor?->id,
                'created_by_label' => $actor?->name ?: $actor?->email,
            ]);

            $this->issueChallenge($key);

            $this->audit->record(
                action: 'backup.recovery_key.submitted',
                organisation: $organisation,
                actor: $actor,
                targetType: 'recovery_key',
                targetId: $key->external_id,
                // Fingerprint and label only. The public key is safe to record but adds nothing a
                // fingerprint does not, and the fewer places key material sits the better.
                after: ['fingerprint' => $fingerprint, 'label' => $label, 'state' => $key->state],
            );

            return $key;
        });
    }

    /**
     * Mint a fresh challenge for a key awaiting proof.
     *
     * Only the sealed blob and the hash of the expected answer are stored, so a stolen database yields
     * nothing that would let somebody activate a key they cannot open.
     */
    public function issueChallenge(RecoveryKey $key): string
    {
        $challenge = RecoveryProof::generateChallenge();
        $plaintext = (string) base64_decode($challenge, true);

        $sealed = Sealing::seal($plaintext, $key->public_key);
        $expected = RecoveryProof::responseFor($plaintext);

        sodium_memzero($plaintext);

        $key->forceFill([
            'challenge' => $sealed,

            // Normalised on both sides of the comparison, so an operator who retyped the answer with
            // different separators or in lower case is not told their key is wrong.
            'challenge_response_hash' => hash('sha256', KeyFingerprint::normalise($expected)),
            'challenge_expires_at' => Carbon::now()->addMinutes(RecoveryKey::CHALLENGE_TTL_MINUTES),
            'challenge_attempts' => 0,
        ])->save();

        return $sealed;
    }

    /**
     * Accept a proof of possession and activate the key.
     *
     * @throws RecoveryKeyRejectedException
     */
    public function prove(RecoveryKey $key, string $answer, ?User $actor = null): RecoveryKey
    {
        /*
         | The mismatch path deliberately returns rather than throwing, and the throw happens after the
         | transaction has committed.
         |
         | Getting this wrong is easy and quiet: an exception thrown inside the transaction rolls back
         | the attempt counter along with everything else, so every wrong answer is the first wrong
         | answer and the attempt limit enforces nothing at all. The audit record of the failure would
         | disappear with it, which matters more - repeated failed proofs against one key is exactly the
         | signal somebody should be able to see.
         */
        [$key, $wasActive, $matched] = DB::transaction(
            function () use ($key, $answer): array {
                $key = RecoveryKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();

                if ($key->isRevoked()) {
                    throw new RecoveryKeyRejectedException('That key has been revoked.');
                }

                if ($key->challenge_response_hash === null || $key->challengeHasExpired()) {
                    throw new RecoveryKeyRejectedException(
                        'That challenge has expired. Ask for a new one and run the command again.'
                    );
                }

                if ($key->challengeIsExhausted()) {
                    throw new RecoveryKeyRejectedException(
                        'Too many wrong answers for that challenge. Ask for a new one.'
                    );
                }

                $key->increment('challenge_attempts');

                $matches = hash_equals(
                    $key->challenge_response_hash,
                    hash('sha256', KeyFingerprint::normalise($answer)),
                );

                if (! $matches) {
                    return [$key, false, false];
                }

                $wasActive = $key->isActive();

                $key->forceFill([
                    'state' => RecoveryKey::STATE_ACTIVE,
                    'activated_at' => $key->activated_at ?? Carbon::now(),
                    'last_proved_at' => Carbon::now(),

                    // Cleared on success. A used challenge sitting in a row is a value with no purpose
                    // and a small amount of risk.
                    'challenge' => null,
                    'challenge_response_hash' => null,
                    'challenge_expires_at' => null,
                    'challenge_attempts' => 0,
                ])->save();

                $this->ratchetFormatFloor($key->organisation);

                return [$key, $wasActive, true];
            }
        );

        if (! $matched) {
            $this->audit->record(
                action: 'backup.recovery_key.proof_failed',
                organisation: $key->organisation,
                actor: $actor,
                targetType: 'recovery_key',
                targetId: $key->external_id,
                outcome: 'failure',
                failureReason: 'The submitted proof did not match.',
                after: ['fingerprint' => $key->fingerprint, 'attempt' => $key->challenge_attempts],
            );

            throw new RecoveryKeyRejectedException(
                'That is not the right answer for this challenge. Check you used the secret half of '
                .'the key whose fingerprint is '.$key->fingerprint.'.'
            );
        }

        $this->audit->record(
            action: $wasActive ? 'backup.recovery_key.reproved' : 'backup.recovery_key.activated',
            organisation: $key->organisation,
            actor: $actor,
            targetType: 'recovery_key',
            targetId: $key->external_id,
            after: ['fingerprint' => $key->fingerprint, 'label' => $key->label, 'state' => $key->state],
        );

        return $key;
    }

    /**
     * Take a key out of service for future backups.
     *
     * Forward-only, and the wording matters: artifacts already taken keep the copy of their key that
     * was sealed to this one, and remain openable by it forever. Revoking is not a way to make an old
     * backup unreadable, and the interface should not imply that it is.
     *
     * @throws RecoveryKeyRejectedException
     */
    public function revoke(RecoveryKey $key, string $reason, ?User $actor = null, bool $acceptLastKey = false): RecoveryKey
    {
        return DB::transaction(function () use ($key, $reason, $actor, $acceptLastKey): RecoveryKey {
            $key = RecoveryKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();

            if ($key->isRevoked()) {
                return $key;
            }

            $remaining = RecoveryKey::query()
                ->where('organisation_id', $key->organisation_id)
                ->active()
                ->whereKeyNot($key->id)
                ->count();

            if ($remaining === 0 && ! $acceptLastKey && $key->isActive()) {
                // Silently breaking every nightly backup in the organisation is worse than the friction
                // of asking. The caller has to say, in as many words, that it meant this.
                throw new RecoveryKeyRejectedException(
                    'That is the only active recovery key. Revoking it stops every backup in this '
                    .'organisation, because there would be nothing left to encrypt one to.'
                );
            }

            $key->forceFill([
                'state' => RecoveryKey::STATE_REVOKED,
                'revoked_at' => Carbon::now(),
                'revoked_reason' => mb_substr($reason, 0, 255),
                'revoked_by_id' => $actor?->id,
                'revoked_by_label' => $actor?->name ?: $actor?->email,

                'challenge' => null,
                'challenge_response_hash' => null,
                'challenge_expires_at' => null,
            ])->save();

            $this->audit->record(
                action: 'backup.recovery_key.revoked',
                organisation: $key->organisation,
                actor: $actor,
                targetType: 'recovery_key',
                targetId: $key->external_id,
                before: ['state' => RecoveryKey::STATE_ACTIVE],
                after: [
                    'fingerprint' => $key->fingerprint,
                    'label' => $key->label,
                    'state' => $key->state,
                    'reason' => $key->revoked_reason,
                    'active_keys_remaining' => $remaining,
                ],
            );

            return $key;
        });
    }

    /**
     * The keys a new backup should be sealed to.
     *
     * Every fingerprint is recomputed from the key material on the way out. The stored column is an
     * index; this is the value a Craft site compares against its pinned list, and serving one that had
     * been edited in the database would let somebody make a site accept a key it was never shown.
     *
     * A row whose stored fingerprint disagrees with its key is excluded rather than corrected. Something
     * has happened to the database, and quietly repairing it in a read path would hide that.
     *
     * @return Collection<int, RecoveryKey>
     */
    public function activeFor(Organisation $organisation): Collection
    {
        return RecoveryKey::query()
            ->where('organisation_id', $organisation->id)
            ->active()
            ->orderBy('activated_at')
            ->get()
            ->filter(function (RecoveryKey $key): bool {
                try {
                    return KeyFingerprint::matches(
                        KeyFingerprint::forRecoveryKey($key->public_key),
                        $key->fingerprint,
                    );
                } catch (ProtocolException) {
                    return false;
                }
            })
            ->values();
    }

    /**
     * The recipient list as a connector receives it, on a signed response.
     *
     * @return list<array{fingerprint: string, public_key: string, label: string|null}>
     */
    public function recipientsFor(Organisation $organisation): array
    {
        return $this->activeFor($organisation)
            ->map(static fn (RecoveryKey $key): array => [
                'fingerprint' => $key->fingerprint,
                'public_key' => $key->public_key,
                'label' => $key->label,
            ])
            ->values()
            ->all();
    }

    public function hasActiveKey(Organisation $organisation): bool
    {
        return $this->activeFor($organisation)->isNotEmpty();
    }

    /**
     * Move an organisation to the zero-knowledge format, once and permanently.
     *
     * Called on the first activation. There is no method to move it back: doing so would mean returning
     * to backups this platform can read, and that should not be one click away from somebody who has not
     * understood what it means.
     */
    private function ratchetFormatFloor(Organisation $organisation): void
    {
        // Reloaded rather than read off whatever instance was passed in. This runs inside a
        // transaction that already holds a lock on the key, and the organisation model reaching here
        // may have been loaded before another request raised the floor.
        $current = Organisation::query()->whereKey($organisation->id)->lockForUpdate()->firstOrFail();

        if ($current->backup_format_floor === Protocol::BACKUP_FORMAT_V2) {
            return;
        }

        $current->forceFill(['backup_format_floor' => Protocol::BACKUP_FORMAT_V2])->save();
        $organisation->setAttribute('backup_format_floor', Protocol::BACKUP_FORMAT_V2);

        $this->audit->record(
            action: 'backup.format_floor.raised',
            organisation: $organisation,
            actorType: 'system',
            actorLabel: 'Recovery keys',
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: ['backup_format_floor' => Protocol::BACKUP_FORMAT_V1],
            after: ['backup_format_floor' => Protocol::BACKUP_FORMAT_V2],
        );
    }

    /**
     * Whether a submitted key is the platform's own legacy artifact key.
     */
    private function isPlatformKey(string $publicKey): bool
    {
        if (! $this->legacyKeypair->isConfigured()) {
            return false;
        }

        return hash_equals($this->legacyKeypair->publicKey(), $publicKey);
    }
}
