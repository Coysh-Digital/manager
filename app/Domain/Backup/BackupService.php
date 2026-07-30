<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\ObjectStore;
use App\Contracts\StorageQuota;
use App\Domain\Audit\AuditRecorder;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\ArtifactEnvelope;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\ProtocolException;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Receiving, storing and reading back backup artifacts.
 *
 * The pipeline has three steps and they are separate on purpose:
 *
 *  1. **Declare.** The connector says what it is about to send — sizes, checksums, and the keys the
 *     artifact was sealed to. Validated against a schema that is an allowlist.
 *
 *     There are two formats and the difference between them is the whole point. Under `backup.v1` the
 *     artifact key arrived sealed to *this platform*, and this platform opened it: a stolen database
 *     plus stolen object storage yielded readable backups. Under `backup.v2` the key is sealed only to
 *     the organisation's own recovery keys, so what is stored here cannot be opened here. Both are
 *     handled, because artifacts written under v1 exist and must stay readable.
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
        private readonly StorageQuota $quota,
        private readonly BackupKeypair $keypair,
        private readonly AuditRecorder $audit,
        private readonly BackupTimeline $timeline,
        private readonly DirectUploadGrants $grants,
    ) {}

    /**
     * Record what a connector is about to upload.
     *
     * Dispatches on the declared format. The two branches share nothing but the quota check and the
     * idempotency rule, and that separation is deliberate: the v1 branch unseals a key with this
     * platform's own secret, and the v2 branch must never be able to reach that code by accident.
     *
     * @param  array<string, mixed>  $declaration
     *
     * @throws BackupRejectedException
     */
    public function declareArtifact(Site $site, RemoteJob $job, array $declaration): BackupArtifact
    {
        $version = $declaration['schema_version'] ?? null;

        return match ($version) {
            'backup.v2' => $this->declareV2($site, $job, $declaration),
            'backup.v1' => $this->declareV1($site, $job, $declaration),

            // Named rather than guessed at. A declaration in a format this build does not implement is
            // refused, because storing bytes under a disagreement about their format is how an
            // unreadable backup gets created.
            default => throw new BackupRejectedException(
                'That artifact declaration is not in a format this platform accepts.'
            ),
        };
    }

    /**
     * The legacy path: a key sealed to this platform, which this platform opens.
     *
     * Kept because artifacts written under it exist and must stay readable, and refused for any
     * organisation that has moved to recovery keys. Nothing writes a new one once the floor has been
     * raised, and the floor only moves one way.
     *
     * @param  array<string, mixed>  $declaration
     *
     * @throws BackupRejectedException
     */
    private function declareV1(Site $site, RemoteJob $job, array $declaration): BackupArtifact
    {
        if ($site->organisation->backup_format_floor === Protocol::BACKUP_FORMAT_V2) {
            /*
             | This organisation has recovery keys, so a v1 declaration means a connector too old to
             | use them — or a downgrade attempt.
             |
             | It is worth being clear about what this defends against. Not a compromised platform:
             | that is the same party enforcing the rule. The control that works against that lives on
             | the Craft server, where a connector refuses to seal to anything but its pinned
             | fingerprints. This defends against the likelier failure, which is somebody rolling a
             | connector fleet back a version and quietly producing backups we can read.
             */
            throw new BackupRejectedException(
                'This organisation encrypts backups to its own recovery keys, and that connector is '
                .'too old to do so. Upgrade the Manager Connector plugin on this site.'
            );
        }

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

            /*
             | The aggregate limit, checked after the idempotency return and never before it.
             |
             | Order matters more than it looks. A connector that has already taken a backup and is
             | retrying a declare must get its artifact back even when the organisation is now at
             | its limit: rejecting the retry would make it abandon a dump it has already made, and
             | invariant 16 says a retry finds what the first attempt created.
             |
             | Inside the transaction, and behind a lock on the organisation, because two sites in
             | the same organisation declaring at once would otherwise both read the same total and
             | both be allowed through.
             */
            Organisation::query()->whereKey($site->organisation_id)->lockForUpdate()->first();

            $remaining = $this->quota->remainingBytes($site->organisation);

            if ($remaining !== null && $artifact['ciphertext_bytes'] > $remaining) {
                sodium_memzero($plaintextKey);

                throw new BackupRejectedException(
                    'This organisation has no room left for another backup.'
                );
            }

            $created = BackupArtifact::query()->create([
                'site_id' => $site->id,
                'organisation_id' => $site->organisation_id,
                'remote_job_id' => $job->id,
                'state' => BackupArtifact::STATE_PENDING,
                'format_version' => BackupArtifact::FORMAT_V1,

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
     * The zero-knowledge path: a key sealed to the organisation's own recovery keys.
     *
     * Nothing in this method can open the artifact it is recording, and nothing in it touches
     * {@see BackupKeypair}. What it does instead is check every claim that *can* be checked without a
     * key, and there are more of them than there were under v1:
     *
     *  - the manifest bytes hash to what was declared;
     *  - the manifest was signed by this site's own connector key, so a manifest cannot be moved
     *    between sites;
     *  - the manifest satisfies its own schema, which is an allowlist;
     *  - each recipient's declared fingerprint really is its public key's fingerprint;
     *  - the recipient set is **exactly** the set served when this job was claimed.
     *
     * That last one is the interesting one and also the limit of what this side can do. The platform
     * cannot verify a seal it cannot open — that is what zero-knowledge means, not a gap — so a
     * connector that reports the right fingerprints and seals to something else is not detectable from
     * here. The honest control for that is the customer running `manager-restore verify`, and the
     * documentation says so rather than implying this check covers it.
     *
     * @param  array<string, mixed>  $declaration
     *
     * @throws BackupRejectedException
     */
    private function declareV2(Site $site, RemoteJob $job, array $declaration): BackupArtifact
    {
        $problems = SchemaValidator::forSchema('backup.v2')->validate($declaration);

        if ($problems !== []) {
            throw new BackupRejectedException('That artifact declaration did not satisfy backup.v2.');
        }

        $manifestBytes = base64_decode((string) $declaration['manifest_b64'], true);

        if ($manifestBytes === false || $manifestBytes === '') {
            throw new BackupRejectedException('That artifact manifest was not readable.');
        }

        if (strlen($manifestBytes) > Protocol::MAX_BACKUP_MANIFEST_BYTES) {
            throw new BackupRejectedException('That artifact manifest is larger than this platform accepts.');
        }

        if (! hash_equals((string) $declaration['manifest_sha256'], hash('sha256', $manifestBytes))) {
            throw new BackupRejectedException('That artifact manifest does not match its declared checksum.');
        }

        $connector = $site->activeConnector;

        if ($connector === null) {
            throw new BackupRejectedException('This site has no active connector.');
        }

        if (! ArtifactEnvelope::verifyManifest(
            $manifestBytes,
            (string) $declaration['manifest_signature'],
            $connector->public_key,
        )) {
            // The signature binds the manifest to the site that produced it. Without this check a
            // manifest could be lifted from one site's artifact and presented by another.
            throw new BackupRejectedException('That artifact manifest was not signed by this site.');
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BackupRejectedException('That artifact manifest is not valid JSON.');
        }

        $manifestProblems = SchemaValidator::forSchema('backup-manifest.v2')->validate($manifest);

        if ($manifestProblems !== []) {
            throw new BackupRejectedException('That artifact manifest did not satisfy backup-manifest.v2.');
        }

        /** @var array<string, mixed> $encryption */
        $encryption = $manifest['encryption'];
        /** @var array<string, mixed> $integrity */
        $integrity = $manifest['integrity'];
        /** @var array<string, mixed> $wrapping */
        $wrapping = $manifest['key_wrapping'];
        /** @var array<string, mixed> $source */
        $source = $manifest['source'] ?? [];

        if ($encryption['scheme'] !== ArtifactStream::SCHEME) {
            throw new BackupRejectedException('This platform does not implement that artifact encryption scheme.');
        }

        if ($encryption['chunk_bytes'] !== Protocol::ARTIFACT_CHUNK_BYTES) {
            throw new BackupRejectedException('That artifact uses a chunk size this platform cannot read.');
        }

        if ($manifest['site_id'] !== $site->external_id) {
            // The signature already proves which site signed it, so this catches a site signing a
            // manifest that names a different one — a bug rather than an attack, and one that would
            // otherwise produce an artifact nobody could attribute.
            throw new BackupRejectedException('That artifact manifest names a different site.');
        }

        $declaredBytes = (int) $declaration['artifact_bytes'];

        if ($declaredBytes > (int) config('manager.backups.max_bytes')) {
            throw new BackupRejectedException('That artifact is larger than this platform accepts.');
        }

        $recipients = $this->readRecipients($wrapping);
        $this->assertRecipientsMatchTheJob($job, $recipients);

        return DB::transaction(function () use (
            $site,
            $job,
            $declaration,
            $manifest,
            $manifestBytes,
            $encryption,
            $integrity,
            $source,
            $recipients,
            $declaredBytes,
        ): BackupArtifact {
            // Invariant 16, exactly as for v1: a retried declaration finds the artifact the first
            // attempt created rather than making a second one.
            $existing = BackupArtifact::query()
                ->where('remote_job_id', $job->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            Organisation::query()->whereKey($site->organisation_id)->lockForUpdate()->first();

            $remaining = $this->quota->remainingBytes($site->organisation);

            // Measured against the whole file, which is what storage will hold — the encrypted stream
            // plus its envelope. Under v1 those were the same number; here they are not.
            if ($remaining !== null && $declaredBytes > $remaining) {
                throw new BackupRejectedException('This organisation has no room left for another backup.');
            }

            $created = BackupArtifact::query()->create([
                'site_id' => $site->id,
                'organisation_id' => $site->organisation_id,
                'remote_job_id' => $job->id,
                'state' => BackupArtifact::STATE_PENDING,
                'format_version' => BackupArtifact::FORMAT_V2,

                'scheme' => $encryption['scheme'],
                'stream_header' => $encryption['stream_header'],

                // Never populated for a v2 artifact. There is no key here this platform could wrap,
                // which is the entire point, and leaving the column null is what makes that visible in
                // a database dump rather than only in the code.
                'wrapped_key' => null,
                'wrapping_key_id' => null,

                'manifest' => $manifestBytes,
                'manifest_sha256' => $declaration['manifest_sha256'],
                'manifest_signature' => $declaration['manifest_signature'],

                'artifact_id' => $manifest['artifact_id'],
                'sequence' => $manifest['sequence'],
                'artifact_sha256' => $declaration['artifact_sha256'],
                'artifact_bytes' => $declaredBytes,
                /*
                 | What *this platform* did, never what the connector said it intended.
                 |
                 | The declaration carries an `upload_mode`, and taking it from there was a real bug
                 | caught by a test: `confirmDirectUpload()` refuses to settle an artifact that did not
                 | go direct, so a connector could declare `direct`, receive no grant, and still reach
                 | a path that marks an artifact stored with nothing behind it. The column is set to
                 | 'direct' by issueGrant() and only when a grant was actually issued.
                 */
                'upload_mode' => 'platform',

                'ciphertext_sha256' => $integrity['ciphertext_sha256'],
                'plaintext_sha256' => $integrity['plaintext_sha256'],
                'ciphertext_bytes' => $integrity['ciphertext_bytes'],
                'plaintext_bytes' => $integrity['plaintext_bytes'],
                'chunk_bytes' => $encryption['chunk_bytes'],

                'engine' => $source['engine'] ?? null,
                'engine_version' => $source['engine_version'] ?? null,
                'compressed' => (bool) ($source['compressed'] ?? false),

                'taken_at' => Carbon::createFromTimestamp((int) $manifest['taken_at']),
            ]);

            foreach ($recipients as $recipient) {
                $created->recipients()->create([
                    'fingerprint' => $recipient['fingerprint'],
                    'public_key' => $recipient['public_key'],
                    'wrapped_key' => $recipient['wrapped_key'],
                    'label' => $recipient['label'],

                    // Linked where the enrolled key still exists, so the interface can say which key
                    // this was. The fingerprint on the row is what explains it if the link is gone.
                    'recovery_key_id' => RecoveryKey::query()
                        ->where('organisation_id', $site->organisation_id)
                        ->where('fingerprint', $recipient['fingerprint'])
                        ->value('id'),
                ]);
            }

            $this->audit->record(
                action: 'backup.declared',
                site: $site,
                actorType: 'connector',
                actorLabel: 'Connector',
                targetType: 'backup_artifact',
                targetId: $created->external_id,
                // Sizes, checksums and the fingerprints this artifact was sealed to. Never a wrapped
                // key: SecretGuard's pattern does not match `wrapped_key`, so nothing structural would
                // stop one being written here, and there is an invariant test that looks for it.
                after: [
                    'format_version' => BackupArtifact::FORMAT_V2,
                    'artifact_bytes' => $created->artifact_bytes,
                    'plaintext_bytes' => $created->plaintext_bytes,
                    'plaintext_sha256' => $created->plaintext_sha256,
                    'sealed_to' => array_column($recipients, 'fingerprint'),
                ],
            );

            $this->timeline->platform(
                event: BackupEvent::DECLARED,
                site: $site,
                artifact: $created,
                job: $job,
                bytes: $created->artifact_bytes,
            );

            return $created;
        });
    }

    /**
     * Read and check the recipient list out of a manifest.
     *
     * @param  array<string, mixed>  $wrapping
     * @return list<array{fingerprint: string, public_key: string, wrapped_key: string, label: string|null}>
     *
     * @throws BackupRejectedException
     */
    private function readRecipients(array $wrapping): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $wrapping['recipients'];

        if ($entries === []) {
            // Checked here rather than in the schema because this validator implements no minItems, and
            // a schema that appeared to guarantee it would be the same trap as v1's unenforced
            // minLength. An artifact sealed to nobody is an artifact nobody can ever open.
            throw new BackupRejectedException('That artifact is not sealed to any recovery key.');
        }

        $recipients = [];
        $seen = [];

        foreach ($entries as $entry) {
            $publicKey = (string) $entry['public_key'];
            $claimed = (string) $entry['fingerprint'];

            try {
                $actual = KeyFingerprint::forRecoveryKey($publicKey);
            } catch (ProtocolException) {
                throw new BackupRejectedException('That artifact names a recipient whose key is malformed.');
            }

            if (! KeyFingerprint::matches($actual, $claimed)) {
                // A recipient labelled with somebody else's fingerprint. Whether that is a bug or an
                // attempt to disguise who can read the backup, storing it would make the manifest lie
                // about itself for the life of the artifact.
                throw new BackupRejectedException(
                    'That artifact names a recipient whose fingerprint does not match its key.'
                );
            }

            if (isset($seen[$actual])) {
                throw new BackupRejectedException('That artifact names the same recovery key twice.');
            }

            $seen[$actual] = true;

            $recipients[] = [
                'fingerprint' => $actual,
                'public_key' => $publicKey,
                'wrapped_key' => (string) $entry['wrapped_key'],
                'label' => isset($entry['label']) ? (string) $entry['label'] : null,
            ];
        }

        return $recipients;
    }

    /**
     * The artifact must be sealed to exactly the keys this job was told about.
     *
     * Not a superset and not a subset. A missing recipient means somebody who should be able to open
     * this backup cannot; an extra one means somebody who should not, can. Both are worth a rejected
     * declaration rather than a stored artifact and a note in a log.
     *
     * @param  list<array{fingerprint: string, public_key: string, wrapped_key: string, label: string|null}>  $recipients
     *
     * @throws BackupRejectedException
     */
    private function assertRecipientsMatchTheJob(RemoteJob $job, array $recipients): void
    {
        $served = $job->backup_recipient_fingerprints;

        if (! is_array($served) || $served === []) {
            // A backup job issued before this platform recorded what it served. Refused rather than
            // waved through: without the served set there is nothing to compare against, and accepting
            // an unverifiable recipient list is exactly the case this check exists for.
            throw new BackupRejectedException(
                'This platform has no record of which recovery keys that job was issued for.'
            );
        }

        $normalise = static fn (array $values): array => array_values(array_unique(array_map(
            static fn (string $value): string => KeyFingerprint::normalise($value),
            $values,
        )));

        $expected = $normalise(array_map(strval(...), $served));
        $actual = $normalise(array_column($recipients, 'fingerprint'));

        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            throw new BackupRejectedException(
                'That artifact is sealed to a different set of recovery keys from the ones this job was issued for.'
            );
        }
    }

    /**
     * Issue permission for a site to write this artifact straight into storage.
     *
     * Called after the declaration rather than when the job was handed out, and the timing is the
     * point: by now the whole-file checksum is known, so the grant can be bound to it and the storage
     * service will refuse a body that does not match. A grant issued at claim time could only say
     * "write something here", which is a materially weaker thing to hand out.
     *
     * Returns null on any edition or configuration that does not issue grants, and the caller then
     * uploads through the platform as before.
     */
    public function issueGrant(BackupArtifact $artifact): ?UploadGrant
    {
        if (! $artifact->isPending() || ! $artifact->isZeroKnowledge()) {
            // v1 artifacts are not offered this path. Their key passes through this platform anyway, so
            // removing one hop would buy nothing, and the format is closed to new writes.
            return null;
        }

        $expected = $artifact->expectedUploadSha256();

        $grant = $this->grants->grantFor(
            $artifact->site,
            $artifact->job,
            // Base64 of the raw digest, which is the form S3 checksum headers take. Converted here so
            // that every implementation of the contract receives the same thing.
            base64_encode((string) hex2bin($expected)),
            $artifact->expectedUploadBytes(),
        );

        if ($grant === null) {
            return null;
        }

        // Recorded now so that a confirmation later has a key to ask about, and so an abandoned grant
        // is something the retention sweep can find. Never taken from anything the connector sent.
        $artifact->forceFill([
            'storage_key' => $grant->storageKey,
            'storage_disk' => (string) config('manager.backups.disk'),
            'upload_mode' => 'direct',
        ])->save();

        return $grant;
    }

    /**
     * Confirm an artifact that went straight to storage.
     *
     * The platform never saw these bytes, so it asks the storage service instead of taking the site's
     * word for it. That is what keeps `uploaded` and `stored` different states rather than two names
     * for the same event: a connector reports that it finished, and nothing is called stored until
     * something other than the connector agrees about the size and the checksum.
     *
     * Idempotent. A duplicate callback finds an artifact that is already stored and returns it, which
     * matters because a connector that timed out waiting for this response will send it again.
     */
    public function confirmDirectUpload(BackupArtifact $artifact): BackupArtifact
    {
        if ($artifact->isStored()) {
            return $artifact;
        }

        if ($artifact->storage_key === null || $artifact->upload_mode !== 'direct') {
            throw new BackupRejectedException('That artifact was not sent straight to storage.');
        }

        $expected = $artifact->expectedUploadSha256();

        $bytes = $this->grants->confirm(
            $artifact->storage_key,
            base64_encode((string) hex2bin($expected)),
        );

        if ($bytes === null) {
            // The object is not there, or does not carry the checksum that was demanded of it. Failing
            // rather than retrying here: the connector is waiting on this answer and a site that
            // uploaded successfully will not be helped by being told to wait.
            return $this->fail($artifact, 'The uploaded artifact could not be confirmed in storage.');
        }

        if ($bytes !== $artifact->expectedUploadBytes()) {
            return $this->fail($artifact, 'The uploaded artifact was not the declared size.');
        }

        $artifact->forceFill([
            'state' => BackupArtifact::STATE_STORED,
            'stored_at' => Carbon::now(),
            'verified_at' => Carbon::now(),
            'expires_at' => $this->expiryFor($artifact->organisation_id),
        ])->save();

        $this->timeline->platform(
            event: BackupEvent::INTEGRITY_VERIFIED,
            site: $artifact->site,
            artifact: $artifact,
            detail: 'storage confirmed the checksum and size',
            bytes: $bytes,
        );

        $this->audit->record(
            action: 'backup.stored',
            site: $artifact->site,
            actorType: 'connector',
            actorLabel: 'Connector',
            targetType: 'backup_artifact',
            targetId: $artifact->external_id,
            after: [
                'upload_mode' => 'direct',
                'artifact_bytes' => $bytes,
                'plaintext_sha256' => $artifact->plaintext_sha256,
                'expires_at' => $artifact->expires_at?->toIso8601String(),
            ],
        );

        return $artifact;
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
            // The two formats upload different things — a bare stream under v1, an envelope wrapped
            // around one under v2 — so what the bytes must hash to is asked of the artifact rather
            // than assumed here.
            $received = $this->stage($input, $staging, $artifact->expectedUploadBytes());

            if (! hash_equals($artifact->expectedUploadSha256(), $received['sha256'])) {
                return $this->fail($artifact, 'The uploaded bytes did not match the declared checksum.');
            }

            if ($received['bytes'] !== $artifact->expectedUploadBytes()) {
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

            $this->timeline->platform(
                event: BackupEvent::UPLOAD_COMPLETED,
                site: $artifact->site,
                artifact: $artifact,
                bytes: $received['bytes'],
            );

            // Recorded as a separate observation from the upload finishing, even though on this path
            // they happen in the same instant. On the direct-to-storage path they do not, and a
            // timeline that only had one of them would make the two paths look identical when they are
            // not: one confirms integrity for itself, the other takes a connector's word for the
            // upload and confirms afterwards.
            $this->timeline->platform(
                event: BackupEvent::INTEGRITY_VERIFIED,
                site: $artifact->site,
                artifact: $artifact,
                detail: 'checksum matched the declaration',
            );

            $this->timeline->platform(
                event: BackupEvent::RETENTION_SET,
                site: $artifact->site,
                artifact: $artifact,
                detail: $artifact->expires_at?->toIso8601String(),
            );

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

        if ($artifact->isZeroKnowledge()) {
            /*
             | Not a failure. This is the feature working.
             |
             | A v2 artifact's key was sealed to the organisation's own recovery keys and to nothing
             | else, so there is no path from here to its plaintext and there is not meant to be. The
             | message names the tool rather than saying "no key", because "that artifact has no key
             | and cannot be opened" is technically true and entirely misleading — it reads like data
             | loss rather than like encryption.
             */
            throw new BackupRejectedException(
                'This platform cannot decrypt that artifact. It was encrypted to this organisation\'s '
                .'own recovery keys, so it opens only with one of those: download the ciphertext and '
                .'run `manager-restore decrypt` where the secret key is.'
            );
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
