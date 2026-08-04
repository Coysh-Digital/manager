<?php

declare(strict_types=1);

use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\ArtifactEnvelope;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Database\Factories\RecoveryKeyFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The zero-knowledge backup pipeline, end to end, with real cryptography.
 *
 * The claim under test is a negative one: **this platform cannot read the artifacts it stores.** A
 * suite of doubles could not tell you whether that is true, so everything here is genuine libsodium —
 * real keypairs, real sealed boxes, real chunked streams, a real envelope - and the decisive test
 * takes a stored artifact and opens it with a recovery key the platform has never seen, having first
 * established that the platform itself refuses to.
 *
 * The other theme is what the platform *can* check about something it cannot open. It turns out to be
 * a fair amount - the manifest's checksum, its signature, its schema, whether each recipient's
 * fingerprint really belongs to its key, and whether the set matches what was served - and exactly one
 * thing it cannot: whether the sealed blobs contain the artifact key at all. That last one is the
 * definition of zero-knowledge rather than a gap, and the tests say so rather than papering over it.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);
    $this->site = Site::factory()->for($this->organisation)->connected()->create(['name' => 'Example Site']);

    $this->keypair = Keys::generateKeypair();
    $this->connector = Connector::factory()->for($this->site)->create([
        'public_key' => $this->keypair['public'],
    ]);

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    // Two recovery keys, because sealing to more than one is the arrangement that makes losing a key
    // survivable and a single-recipient test would not exercise the fan-out.
    $this->keyA = RecoveryKey::factory()->for($this->organisation)->create(['label' => 'Ops laptop']);
    $this->keyB = RecoveryKey::factory()->for($this->organisation)->create(['label' => 'Safe']);

    $this->secretA = RecoveryKeyFactory::secretFor($this->keyA->fingerprint);
    $this->secretB = RecoveryKeyFactory::secretFor($this->keyB->fingerprint);

    $this->organisation->forceFill(['backup_format_floor' => Protocol::BACKUP_FORMAT_V2])->save();

    // Claimed, and carrying the recipient set that was served with it - which is the state a real
    // claim leaves behind and the state a declaration is checked against.
    $this->job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'backup_recipient_fingerprints' => [$this->keyA->fingerprint, $this->keyB->fingerprint],
    ]);

    /**
     * Build a genuine v2 artifact and the declaration that describes it.
     *
     * @param  list<array{fingerprint: string, public_key: string, label?: string}>|null  $recipients
     * @return array{bytes: string, declaration: array<string, mixed>, key: string, plaintext: string, manifest: array<string, mixed>}
     */
    $this->makeArtifact = function (
        ?string $plaintext = null,
        ?array $recipients = null,
        ?string $signWith = null,
        ?callable $mutateManifest = null,
    ): array {
        $plaintext ??= '-- MySQL dump'.str_repeat("\nINSERT INTO entries VALUES (1);", 200);

        $key = ArtifactStream::generateKey();

        $in = fopen('php://temp', 'r+b');
        $stream = fopen('php://temp', 'r+b');
        fwrite($in, $plaintext);
        rewind($in);

        $written = ArtifactStream::encrypt($in, $stream, $key);
        rewind($stream);

        $recipients ??= [
            ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key, 'label' => 'Ops laptop'],
            ['fingerprint' => $this->keyB->fingerprint, 'public_key' => $this->keyB->public_key, 'label' => 'Safe'],
        ];

        $manifest = [
            'manifest_version' => 'backup-manifest.v2',
            'artifact_id' => strtoupper(substr(str_replace(['I', 'L', 'O', 'U'], '2', (string) Str::ulid()), 0, 26)),
            'site_id' => $this->site->external_id,
            'site_key_fingerprint' => KeyFingerprint::forSiteKey($this->keypair['public']),
            'taken_at' => time(),
            'sequence' => 1,
            'encryption' => [
                'scheme' => ArtifactStream::SCHEME,
                'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
                'stream_header' => $written['header'],
            ],
            'key_wrapping' => [
                'algorithm' => 'x25519-crypto_box_seal-v1',
                'recipients' => array_map(static fn (array $r): array => array_filter([
                    'fingerprint' => $r['fingerprint'],
                    'public_key' => $r['public_key'],
                    'wrapped_key' => Sealing::seal($key, $r['public_key']),
                    'label' => $r['label'] ?? null,
                ], static fn (mixed $v): bool => $v !== null), $recipients),
            ],
            'integrity' => [
                'plaintext_sha256' => $written['plaintext_sha256'],
                'ciphertext_sha256' => $written['ciphertext_sha256'],
                'plaintext_bytes' => $written['plaintext_bytes'],
                'ciphertext_bytes' => $written['ciphertext_bytes'],
            ],
            'source' => ['engine' => 'mysql', 'engine_version' => '8.0.36', 'compressed' => false, 'compression' => 'none'],
        ];

        if ($mutateManifest !== null) {
            $manifest = $mutateManifest($manifest);
        }

        $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = ArtifactEnvelope::signManifest($manifestBytes, $signWith ?? $this->keypair['secret']);

        $file = fopen('php://temp', 'r+b');
        ArtifactEnvelope::write($file, $manifestBytes, $signature);
        stream_copy_to_stream($stream, $file);
        rewind($file);
        $bytes = (string) stream_get_contents($file);

        fclose($in);
        fclose($stream);
        fclose($file);

        return [
            'bytes' => $bytes,
            'plaintext' => $plaintext,
            'key' => $key,
            'manifest' => $manifest,
            'declaration' => [
                'schema_version' => 'backup.v2',
                'job_id' => $this->job->external_id,
                'manifest_b64' => base64_encode($manifestBytes),
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'manifest_signature' => $signature,
                'artifact_sha256' => hash('sha256', $bytes),
                'artifact_bytes' => strlen($bytes),
                'upload_mode' => 'platform',
            ],
        ];
    };

    $this->declare = fn (array $declaration) => postSignedConnectorRequest(
        '/api/connector/v1/backups',
        $declaration,
        $this->site,
        $this->keypair['secret'],
    );

    $this->upload = fn (string $artifactId, string $bytes) => putSignedArtifact(
        "/api/connector/v1/backups/{$artifactId}/content",
        $bytes,
        $this->site,
        $this->keypair['secret'],
    );
});

/*
|--------------------------------------------------------------------------------------------------
| The claim itself
|--------------------------------------------------------------------------------------------------
*/

it('stores an artifact it cannot open, and the customer opens it', function (): void {
    $artifact = ($this->makeArtifact)();

    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    ($this->upload)($row->external_id, $artifact['bytes'])->assertOk();

    $row->refresh();

    expect($row->isStored())->toBeTrue()
        ->and($row->isZeroKnowledge())->toBeTrue()

        // The column that would let this platform decrypt is null, and there is no other. Under v1 it
        // held a key wrapped by the key service; here there is nothing to wrap.
        ->and($row->wrapped_key)->toBeNull()
        ->and($row->wrapping_key_id)->toBeNull();

    // And the platform says so rather than failing obscurely.
    expect(fn () => app(BackupService::class)->readArtifactTo($row, fopen('php://memory', 'w+b')))
        ->toThrow(BackupRejectedException::class, 'cannot decrypt');

    // Now the customer's position: the stored bytes, a private key the platform has never held, and
    // nothing else. This is the test that makes the claim real.
    $stored = Storage::disk('backups')->get((string) $row->storage_key);

    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, (string) $stored);
    rewind($handle);

    $envelope = ArtifactEnvelope::readHeader($handle);
    $manifest = json_decode($envelope['manifest_bytes'], true, 512, JSON_THROW_ON_ERROR);

    $recovered = Sealing::unseal(
        $manifest['key_wrapping']['recipients'][0]['wrapped_key'],
        $this->keyA->public_key,
        $this->secretA,
    );

    $out = fopen('php://temp', 'r+b');
    ArtifactStream::decrypt($handle, $out, $recovered);
    rewind($out);

    expect(stream_get_contents($out))->toBe($artifact['plaintext']);

    fclose($handle);
    fclose($out);
});

it('seals to every active key, so losing one costs nothing', function (): void {
    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    expect($row->recipients()->count())->toBe(2);

    foreach ([[$this->keyA, $this->secretA], [$this->keyB, $this->secretB]] as [$key, $secret]) {
        $recipient = $row->recipients()->where('fingerprint', $key->fingerprint)->firstOrFail();

        expect(Sealing::unseal($recipient->wrapped_key, $key->public_key, $secret))->toBe($artifact['key']);
    }
});

it('does not encrypt a wrapped key with our own application key', function (): void {
    // The tempting mistake, and an actively harmful one. This platform cannot open a sealed box either
    // way, so an `encrypted` cast would add no confidentiality - and it would make the customer's
    // restore depend on our APP_KEY surviving, recreating the exact dependency the format removes.
    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $raw = (string) DB::table('backup_artifact_recipients')->value('wrapped_key');

    expect($raw)->not->toStartWith('eyJpdiI6')
        ->and(strlen($raw))->toBe(108)
        ->and(base64_decode($raw, true))->not->toBeFalse();
});

/*
|--------------------------------------------------------------------------------------------------
| What the platform checks about something it cannot read
|--------------------------------------------------------------------------------------------------
*/

it('refuses a manifest signed by a different site', function (): void {
    $stranger = Keys::generateKeypair();

    // Without this check a manifest could be lifted from one site's artifact and presented by another,
    // and the signature is the only thing binding a manifest to the site that produced it.
    ($this->declare)(($this->makeArtifact)(signWith: $stranger['secret'])['declaration'])
        ->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses a manifest altered after it was signed', function (): void {
    $artifact = ($this->makeArtifact)();

    $tampered = json_decode(base64_decode($artifact['declaration']['manifest_b64'], true), true);
    $tampered['sequence'] = 999;
    $bytes = json_encode($tampered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $declaration = $artifact['declaration'];
    $declaration['manifest_b64'] = base64_encode($bytes);
    $declaration['manifest_sha256'] = hash('sha256', $bytes);

    ($this->declare)($declaration)->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses a manifest whose checksum disagrees with its bytes', function (): void {
    $artifact = ($this->makeArtifact)();
    $declaration = $artifact['declaration'];
    $declaration['manifest_sha256'] = hash('sha256', 'something else');

    ($this->declare)($declaration)->assertStatus(422);
});

it('refuses a recipient labelled with somebody else\'s fingerprint', function (): void {
    // Whether a mistake or an attempt to disguise who can read the backup, storing it would make the
    // manifest lie about itself for the life of the artifact.
    $artifact = ($this->makeArtifact)(mutateManifest: function (array $manifest): array {
        $manifest['key_wrapping']['recipients'][0]['fingerprint'] = $this->keyB->fingerprint;

        return $manifest;
    });

    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses an artifact sealed to nobody', function (): void {
    // The schema cannot express this - the validator implements no minItems - so it is a check in the
    // service, and an artifact sealed to nobody is one nobody could ever open.
    $artifact = ($this->makeArtifact)(mutateManifest: function (array $manifest): array {
        $manifest['key_wrapping']['recipients'] = [];

        return $manifest;
    });

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses an artifact naming the same key twice', function (): void {
    $artifact = ($this->makeArtifact)(recipients: [
        ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key],
        ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key],
    ]);

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses an artifact sealed to a key the job was not issued for', function (): void {
    $stranger = Sealing::generateBoxKeypair();

    // The finding this check exists for: somebody added a recipient of their own between the job being
    // handed out and the artifact being declared.
    $artifact = ($this->makeArtifact)(recipients: [
        ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key],
        ['fingerprint' => $this->keyB->fingerprint, 'public_key' => $this->keyB->public_key],
        ['fingerprint' => KeyFingerprint::forRecoveryKey($stranger['public']), 'public_key' => $stranger['public']],
    ]);

    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses an artifact missing a key the job was issued for', function (): void {
    // A subset is refused as firmly as a superset. Somebody who should be able to open this backup
    // would not be able to, and they would find out at the worst possible moment.
    $artifact = ($this->makeArtifact)(recipients: [
        ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key],
    ]);

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses a declaration against a job that predates the recipient record', function (): void {
    $this->job->forceFill(['backup_recipient_fingerprints' => null])->save();

    // Refused rather than waved through. Without the served set there is nothing to compare against,
    // and accepting an unverifiable recipient list is exactly the case the check exists for.
    ($this->declare)(($this->makeArtifact)()['declaration'])->assertStatus(422);
});

it('refuses a manifest that names a different site', function (): void {
    $other = Site::factory()->for($this->organisation)->create();

    $artifact = ($this->makeArtifact)(mutateManifest: function (array $manifest) use ($other): array {
        $manifest['site_id'] = $other->external_id;

        return $manifest;
    });

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses a chunk size it cannot frame', function (): void {
    $artifact = ($this->makeArtifact)(mutateManifest: function (array $manifest): array {
        $manifest['encryption']['chunk_bytes'] = 4096;

        return $manifest;
    });

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------------------------------
| Upload
|--------------------------------------------------------------------------------------------------
*/

it('checks the whole file, not just the encrypted part', function (): void {
    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    // A v2 artifact is an envelope wrapped around a stream, so the ciphertext hash covers only part of
    // the file. Comparing against it would accept a file whose manifest had been replaced wholesale.
    expect($row->expectedUploadSha256())->toBe($row->artifact_sha256)
        ->and($row->expectedUploadSha256())->not->toBe($row->ciphertext_sha256)
        ->and($row->expectedUploadBytes())->toBe($row->artifact_bytes)
        ->and($row->artifact_bytes)->toBeGreaterThan($row->ciphertext_bytes);
});

it('refuses bytes that do not match what was declared', function (): void {
    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    $altered = $artifact['bytes'];
    $altered[strlen($altered) - 5] = chr(ord($altered[strlen($altered) - 5]) ^ 0xFF);

    ($this->upload)($row->external_id, $altered)->assertStatus(422);

    expect($row->fresh()->isStored())->toBeFalse()
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('returns the same artifact when a connector declares twice', function (): void {
    $artifact = ($this->makeArtifact)();

    $first = ($this->declare)($artifact['declaration'])->assertOk();
    $second = ($this->declare)($artifact['declaration'])->assertOk();

    // Invariant 16. A connector retrying after a timeout must not dump the database again, and must
    // not leave two rows behind.
    expect($second->json('artifact'))->toBe($first->json('artifact'))
        ->and(BackupArtifact::query()->count())->toBe(1)
        ->and(DB::table('backup_artifact_recipients')->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------------------------------
| The two formats side by side
|--------------------------------------------------------------------------------------------------
*/

it('refuses the old format once an organisation has recovery keys', function (): void {
    $platformKey = Sealing::generateBoxKeypair();
    config(['manager.backups.public_key' => $platformKey['public'], 'manager.backups.secret_key' => $platformKey['secret']]);

    $key = ArtifactStream::generateKey();
    $in = fopen('php://temp', 'r+b');
    $out = fopen('php://temp', 'r+b');
    fwrite($in, 'a dump');
    rewind($in);
    $written = ArtifactStream::encrypt($in, $out, $key);
    fclose($in);
    fclose($out);

    // The likelier failure this guards against is not a compromised platform - it is somebody rolling
    // a connector fleet back a version and quietly producing backups we can read.
    ($this->declare)([
        'schema_version' => 'backup.v1',
        'job_id' => $this->job->external_id,
        'artifact' => [
            'scheme' => ArtifactStream::SCHEME,
            'header' => $written['header'],
            'sealed_key' => Sealing::seal($key, $platformKey['public']),
            'ciphertext_sha256' => $written['ciphertext_sha256'],
            'plaintext_sha256' => $written['plaintext_sha256'],
            'ciphertext_bytes' => $written['ciphertext_bytes'],
            'plaintext_bytes' => $written['plaintext_bytes'],
            'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
            'taken_at' => time(),
        ],
    ])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses a format it does not implement rather than guessing', function (): void {
    ($this->declare)(['schema_version' => 'backup.v3', 'job_id' => $this->job->external_id])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------------------------------
| What is recorded
|--------------------------------------------------------------------------------------------------
*/

it('records the fingerprints an artifact was sealed to, and nothing that opens it', function (): void {
    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $trail = AuditEvent::query()->get()->toJson();

    // SecretGuard's pattern does not match `wrapped_key`, so nothing structural would stop a sealed
    // blob being written into an audit payload. This is the check.
    expect($trail)->toContain($this->keyA->fingerprint)
        ->and($trail)->toContain($this->keyB->fingerprint);

    foreach (BackupArtifact::query()->firstOrFail()->recipients as $recipient) {
        expect($trail)->not->toContain($recipient->wrapped_key);
    }
});

it('narrates a backup without putting telemetry in the audit chain', function (): void {
    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();
    ($this->upload)($row->external_id, $artifact['bytes'])->assertOk();

    $events = BackupEvent::query()->pluck('event')->all();

    expect($events)->toContain(BackupEvent::DECLARED)
        ->and($events)->toContain(BackupEvent::INTEGRITY_VERIFIED);

    // Observations stay out of the hash-chained log. Every append there takes an advisory lock on the
    // organisation, and a nightly fleet of backups would serialise against every sign-in.
    $audited = AuditEvent::query()->pluck('action')->all();

    expect($audited)->not->toContain(BackupEvent::INTEGRITY_VERIFIED)
        ->and($audited)->toContain('backup.declared');
});

it('keeps both clocks so a wrong one cannot reorder a timeline', function (): void {
    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $event = BackupEvent::query()->firstOrFail();

    expect($event->recorded_at)->not->toBeNull()
        ->and($event->occurred_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------------------------------
*/

it('will not seal one organisation\'s backup to another\'s key', function (): void {
    $other = Organisation::factory()->create();
    $theirs = RecoveryKey::factory()->for($other)->create();

    $artifact = ($this->makeArtifact)(recipients: [
        ['fingerprint' => $this->keyA->fingerprint, 'public_key' => $this->keyA->public_key],
        ['fingerprint' => $theirs->fingerprint, 'public_key' => $theirs->public_key],
    ]);

    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('links a recipient only to a key belonging to the same organisation', function (): void {
    $other = Organisation::factory()->create();

    // The same key material enrolled by a different organisation. The link must not cross the
    // boundary, or one tenant's screen would name another tenant's key record.
    RecoveryKey::factory()->for($other)->create([
        'public_key' => $this->keyA->public_key,
        'fingerprint' => $this->keyA->fingerprint,
    ]);

    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $recipient = BackupArtifact::query()->firstOrFail()
        ->recipients()->where('fingerprint', $this->keyA->fingerprint)->firstOrFail();

    expect($recipient->recovery_key_id)->toBe($this->keyA->id);
});
