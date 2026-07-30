<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Contracts\KeyService;
use App\Contracts\ObjectStore;
use App\Domain\Audit\AuditRecorder;
use App\Models\BackupArtifact;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\ProtocolException;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Receiving, storing and reading back backup artifacts.
 *
 * The pipeline has three steps and they are separate on purpose:
 *
 *  1. **Declare.** The connector says what it is about to send — sizes, checksums, and the artifact key
 *     sealed to this platform. Validated against `backup.v1`, which is an allowlist.
 *  2. **Store.** The bytes arrive, are hashed as they stream past, and are committed only if the hash
 *     matches what step 1 declared and the signature covered.
 *  3. **Report.** The job succeeds or fails as usual.
 *
 * Splitting declare from store is what makes the checksum meaningful. A single request carrying both
 * the bytes and their claimed hash would have to be read before it could be judged; here the claim is
 * authenticated first, and the stream is compared against a promise made before it started.
 *
 * Step 1 is keyed on the job, which is what makes a retry harmless — invariant 16. A connector that
 * declares twice for the same job gets the same artifact back, not a second one.
 */
final class BackupService
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly KeyService $keys,
        private readonly BackupKeypair $keypair,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Record what a connector is about to upload.
     *
     * @param  array<string, mixed>  $declaration  already known to satisfy backup.v1
     *
     * @throws BackupRejectedException
     */
    public function declareArtifact(Site $site, RemoteJob $job, array $declaration): BackupArtifact
    {
        $problems = SchemaValidator::forSchema('backup.v1')->validate($declaration);

        if ($problems !== []) {
            // Rejected rather than partially accepted. A declaration that does not satisfy the schema
            // means the two sides disagree about the format, and storing an artifact under that
            // disagreement is how an unreadable backup gets created.
            throw new BackupRejectedException('That artifact declaration did not satisfy backup.v1.');
        }

        /** @var array<string, mixed> $artifact */
        $artifact = $declaration['artifact'];

        if ($artifact['scheme'] !== ArtifactStream::SCHEME) {
            throw new BackupRejectedException(
                'This platform does not implement that artifact encryption scheme.'
            );
        }

        if ($artifact['chunk_bytes'] !== Protocol::ARTIFACT_CHUNK_BYTES) {
            // A reader has to frame the stream the way the writer did. Accepting a different chunk size
            // would store something this build cannot read back.
            throw new BackupRejectedException('That artifact uses a chunk size this platform cannot read.');
        }

        $limit = (int) config('manager.backups.max_bytes');

        if ($artifact['ciphertext_bytes'] > $limit) {
            throw new BackupRejectedException('That artifact is larger than this platform accepts.');
        }

        // The sealed key is opened here, at the boundary, and re-wrapped for storage. Two reasons: it
        // confirms straight away that the key is one this platform can actually use — better than
        // discovering it when somebody needs the backup — and it means the stored form is wrapped by
        // the key service, which is the seam the Cloud edition replaces.
        try {
            $plaintextKey = $this->keypair->unseal((string) $artifact['sealed_key']);
        } catch (ProtocolException) {
            throw new BackupRejectedException('That artifact key was not sealed to this platform.');
        }

        if (strlen($plaintextKey) !== ArtifactStream::KEY_BYTES) {
            sodium_memzero($plaintextKey);

            throw new BackupRejectedException('That artifact key is not the expected length.');
        }

        return DB::transaction(function () use ($site, $job, $artifact, $plaintextKey): BackupArtifact {
            // Invariant 16. A retried report finds the artifact its first attempt created rather than
            // creating a second one, and the unique index on remote_job_id enforces it even if two
            // requests arrive at once.
            $existing = BackupArtifact::query()
                ->where('remote_job_id', $job->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                sodium_memzero($plaintextKey);

                return $existing;
            }

            $created = BackupArtifact::query()->create([
                'site_id' => $site->id,
                'organisation_id' => $site->organisation_id,
                'remote_job_id' => $job->id,
                'state' => BackupArtifact::STATE_PENDING,

                'scheme' => $artifact['scheme'],
                'stream_header' => $artifact['header'],

                'wrapped_key' => $this->keys->wrap($plaintextKey, 'backup'),
                'wrapping_key_id' => $this->keys->currentKeyIdentifier(),

                'ciphertext_sha256' => $artifact['ciphertext_sha256'],
                'plaintext_sha256' => $artifact['plaintext_sha256'],
                'ciphertext_bytes' => $artifact['ciphertext_bytes'],
                'plaintext_bytes' => $artifact['plaintext_bytes'],
                'chunk_bytes' => $artifact['chunk_bytes'],

                'engine' => $artifact['engine'] ?? null,
                'engine_version' => $artifact['engine_version'] ?? null,
                'compressed' => (bool) ($artifact['compressed'] ?? false),

                'taken_at' => Carbon::createFromTimestamp((int) $artifact['taken_at']),
            ]);

            sodium_memzero($plaintextKey);

            $this->audit->record(
                action: 'backup.declared',
                site: $site,
                actorType: 'connector',
                actorLabel: 'Connector',
                targetType: 'backup_artifact',
                targetId: $created->external_id,
                // Sizes and checksums. Never the key, and never anything from inside the artifact.
                after: [
                    'ciphertext_bytes' => $created->ciphertext_bytes,
                    'plaintext_bytes' => $created->plaintext_bytes,
                    'plaintext_sha256' => $created->plaintext_sha256,
                ],
            );

            return $created;
        });
    }

    /**
     * Take delivery of the bytes.
     *
     * Streamed to a local staging file first, hashing as it goes, and only moved into storage once the
     * hash matches. The alternative — streaming straight into storage and deleting on mismatch — leaves
     * an unverified artifact in the bucket for as long as the upload takes, and leaves it there for good
     * if the platform dies mid-request.
     *
     * @param  resource  $input
     *
     * @throws BackupRejectedException
     */
    public function storeArtifact(BackupArtifact $artifact, $input): BackupArtifact
    {
        if (! $artifact->isPending()) {
            // Already settled. A second upload for the same artifact is refused rather than allowed to
            // overwrite one that has already been verified.
            throw new BackupRejectedException('That artifact has already been settled.');
        }

        $staging = tempnam(sys_get_temp_dir(), 'mgr-artifact-');

        if ($staging === false) {
            throw new RuntimeException('Could not open a staging file for the artifact.');
        }

        try {
            $received = $this->stage($input, $staging, $artifact->ciphertext_bytes);

            if (! hash_equals($artifact->ciphertext_sha256, $received['sha256'])) {
                return $this->fail($artifact, 'The uploaded bytes did not match the declared checksum.');
            }

            if ($received['bytes'] !== $artifact->ciphertext_bytes) {
                return $this->fail($artifact, 'The uploaded artifact was not the declared size.');
            }

            $key = $this->storageKeyFor($artifact);

            $handle = fopen($staging, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Could not reopen the staged artifact.');
            }

            try {
                $this->store->put($key, $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            $artifact->forceFill([
                'state' => BackupArtifact::STATE_STORED,
                'storage_key' => $key,
                'storage_disk' => (string) config('manager.backups.disk'),
                'stored_at' => Carbon::now(),

                // Verified at the same moment it was stored, because it was not stored until it
                // verified. Recorded separately so a later re-verification has somewhere to go.
                'verified_at' => Carbon::now(),
                'expires_at' => $this->expiryFor($artifact->organisation_id),
            ])->save();

            $this->audit->record(
                action: 'backup.stored',
                site: $artifact->site,
                actorType: 'connector',
                actorLabel: 'Connector',
                targetType: 'backup_artifact',
                targetId: $artifact->external_id,
                after: [
                    'ciphertext_bytes' => $artifact->ciphertext_bytes,
                    'plaintext_sha256' => $artifact->plaintext_sha256,
                    'expires_at' => $artifact->expires_at?->toIso8601String(),
                ],
            );

            return $artifact;
        } finally {
            // Whatever happened. A staged artifact left behind is a plaintext-adjacent copy of a
            // customer database sitting in a temporary directory.
            if (is_file($staging)) {
                @unlink($staging);
            }
        }
    }

    /**
     * Open a stored artifact as decrypted plaintext.
     *
     * The caller gets a stream and is responsible for what happens to it. Nothing here writes plaintext
     * anywhere: the decrypting happens on the way to wherever the caller is sending it.
     *
     * @param  resource  $output
     * @return array{plaintext_sha256: string, plaintext_bytes: int}
     *
     * @throws BackupRejectedException
     */
    public function readArtifactTo(BackupArtifact $artifact, $output): array
    {
        if (! $artifact->isRetrievable()) {
            throw new BackupRejectedException('That artifact is not available.');
        }

        if ($artifact->scheme !== ArtifactStream::SCHEME) {
            throw new BackupRejectedException('This build does not implement that artifact\'s encryption scheme.');
        }

        $wrapped = $artifact->wrapped_key;

        if (! is_string($wrapped) || $wrapped === '') {
            throw new BackupRejectedException('That artifact has no key and cannot be opened.');
        }

        $key = $this->keys->unwrap($wrapped, 'backup');

        $input = $this->store->readStream((string) $artifact->storage_key);

        try {
            $result = ArtifactStream::decrypt($input, $output, $key);
        } catch (ProtocolException $e) {
            throw new BackupRejectedException($e->getMessage());
        } finally {
            sodium_memzero($key);

            if (is_resource($input)) {
                fclose($input);
            }
        }

        // The checksum taken on the site, compared after decryption here. This is the check that says
        // "this is the backup that was taken", which the ciphertext hash cannot answer.
        if (! hash_equals($artifact->plaintext_sha256, $result['plaintext_sha256'])) {
            throw new BackupRejectedException(
                'The decrypted artifact did not match the checksum recorded when it was taken.'
            );
        }

        return $result;
    }

    /**
     * Remove an artifact and the key that opened it.
     *
     * The row survives so the audit trail still shows the artifact existed, but the wrapped key does
     * not: whatever is left in storage after a deletion that half-failed is unreadable.
     */
    public function delete(BackupArtifact $artifact, string $reason, ?User $actor = null): void
    {
        $key = $artifact->storage_key;

        $removed = $key === null ? false : $this->store->delete($key);

        $artifact->forceFill([
            'state' => BackupArtifact::STATE_DELETED,
            'deleted_at' => Carbon::now(),
            'deleted_reason' => $reason,

            // Destroyed before the row is saved, not after. If the storage delete failed, the bytes
            // that remain are bytes nobody holds a key for.
            'wrapped_key' => null,
            'storage_key' => null,
        ])->save();

        $this->audit->record(
            action: 'backup.deleted',
            site: $artifact->site,
            actor: $actor,
            actorType: $actor === null ? 'system' : 'user',
            actorLabel: $actor === null ? 'Retention' : null,
            targetType: 'backup_artifact',
            targetId: $artifact->external_id,
            after: [
                'reason' => $reason,
                'bytes_removed' => $removed,
                'plaintext_sha256' => $artifact->plaintext_sha256,
            ],
        );
    }

    /**
     * Mark a declared artifact that never arrived, or never verified.
     */
    public function fail(BackupArtifact $artifact, string $reason): BackupArtifact
    {
        $key = $artifact->storage_key;

        if ($key !== null) {
            $this->store->delete($key);
        }

        $artifact->forceFill([
            'state' => BackupArtifact::STATE_FAILED,
            'failure_reason' => $reason,
            'storage_key' => null,

            // A key for an artifact that does not exist protects nothing and is one more secret to
            // hold.
            'wrapped_key' => null,
        ])->save();

        $this->audit->record(
            action: 'backup.failed',
            site: $artifact->site,
            actorType: 'connector',
            actorLabel: 'Connector',
            targetType: 'backup_artifact',
            targetId: $artifact->external_id,
            outcome: 'failure',
            failureReason: $reason,
        );

        return $artifact;
    }

    /**
     * When an artifact taken now would expire.
     *
     * Computed at storage time rather than read at sweep time, so changing the policy later does not
     * silently re-date artifacts already taken. An operator who shortens retention is saying what
     * should happen to future backups; deciding it also applies retroactively is not theirs to assume.
     */
    public function expiryFor(int $organisationId): ?Carbon
    {
        $days = (int) (Organisation::query()->whereKey($organisationId)->value('backup_retention_days') ?? 30);

        return $days <= 0 ? null : Carbon::now()->addDays($days);
    }

    /**
     * Where an artifact's bytes go.
     *
     * Built entirely from identifiers the platform generated. Nothing a connector sent reaches this,
     * which is why there is no path traversal to worry about — there is no input to traverse with.
     */
    public function storageKeyFor(BackupArtifact $artifact): string
    {
        $organisation = $artifact->organisation_id;
        $site = $artifact->site_id;
        $date = $artifact->taken_at->format('Y/m');

        return "org-{$organisation}/site-{$site}/{$date}/{$artifact->external_id}.artifact";
    }

    /**
     * Copy a stream to a file, hashing it and refusing to exceed a limit.
     *
     * The limit is the declared size, not the configured maximum. A connector that declared 40 MB and
     * then started sending 40 GB is stopped at 40 MB rather than at the ceiling.
     *
     * @param  resource  $input
     * @return array{sha256: string, bytes: int}
     */
    private function stage($input, string $path, int $limit): array
    {
        $output = fopen($path, 'wb');

        if ($output === false) {
            throw new RuntimeException('Could not open the staging file for writing.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, Protocol::ARTIFACT_CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);

                if ($bytes > $limit) {
                    // Stopped mid-stream rather than after. Reading the rest to find out how much
                    // somebody wanted to send is exactly the wrong response.
                    throw new BackupRejectedException('That artifact sent more than it declared.');
                }

                hash_update($hash, $chunk);

                if (fwrite($output, $chunk) === false) {
                    throw new RuntimeException('Could not write to the staging file.');
                }
            }
        } finally {
            fclose($output);
        }

        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    /**
     * A description of where artifacts go, for diagnostics.
     */
    public function describeStorage(): string
    {
        try {
            return $this->store->describe();
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
