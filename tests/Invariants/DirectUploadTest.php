<?php

declare(strict_types=1);

use App\Contracts\DirectUploadGrants;
use App\Domain\Backup\UploadGrant;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Support\SelfHosted\NoDirectUploads;
use coyshdigital\managerprotocol\ArtifactEnvelope;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Artifacts that go straight to storage without passing through this application.
 *
 * The reason to build this is not speed. A gigabyte of ciphertext moving through a web server is a
 * gigabyte written to that server's temporary directory, held open by a PHP process and counted
 * against a request timeout — for no benefit, because the platform cannot read it either way. Removing
 * that leg removes a place the bytes exist.
 *
 * Two things then become true that were not true before, and both are what this file is about.
 *
 * The platform no longer witnesses the upload, so `stored` cannot mean what it used to. A connector
 * saying "done" is a claim; the platform has to ask the storage service, and until something other
 * than the connector agrees the artifact sits in `uploaded`. That is why the state exists.
 *
 * And the platform now issues something that names a destination, which is exactly the kind of value
 * the connector has always refused. It is safe only because the grant carries no host: the connector
 * assembles the URL from its own configuration file, so the platform can vary a path inside a bucket
 * the operator already approved and can do nothing else. A `host` key on a grant would quietly undo
 * the whole arrangement, so there is a test for its absence rather than a comment about it.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create();

    $this->keypair = Keys::generateKeypair();
    Connector::factory()->for($this->site)->create(['public_key' => $this->keypair['public']]);
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->key = RecoveryKey::factory()->for($this->organisation)->create(['label' => 'Ops laptop']);
    $this->organisation->forceFill(['backup_format_floor' => Protocol::BACKUP_FORMAT_V2])->save();

    $this->job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'backup_recipient_fingerprints' => [$this->key->fingerprint],
    ]);

    /**
     * A storage service that behaves like S3 without being it.
     *
     * Written rather than mocked, because the properties under test are the service's own: that it
     * enforces a checksum, that it reports a size, and that it can simply not have the object. A
     * Mockery double would return whatever it was told to and prove none of them.
     */
    $this->fakeGrants = new class implements DirectUploadGrants
    {
        /** @var array<string, array{bytes: int, checksum: string}> */
        public array $objects = [];

        public array $granted = [];

        public bool $issue = true;

        public function grantFor(Site $site, RemoteJob $job, string $expectedSha256Base64, int $maxBytes): ?UploadGrant
        {
            if (! $this->issue) {
                return null;
            }

            $key = "org-{$site->organisation_id}/site-{$site->id}/2026/08/{$job->external_id}.artifact";

            $this->granted[] = ['key' => $key, 'checksum' => $expectedSha256Base64, 'max' => $maxBytes];

            return new UploadGrant(
                path: '/'.$key,
                query: 'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=deadbeefdeadbeef',
                headers: [
                    'x-amz-checksum-sha256' => $expectedSha256Base64,
                    'x-amz-server-side-encryption' => 'AES256',
                    'If-None-Match' => '*',
                ],
                expiresAt: Carbon::now()->addHour(),
                maxBytes: $maxBytes,
                storageKey: $key,
            );
        }

        public function confirm(string $storageKey, string $expectedSha256Base64): ?int
        {
            $object = $this->objects[$storageKey] ?? null;

            if ($object === null || ! hash_equals($expectedSha256Base64, $object['checksum'])) {
                return null;
            }

            return $object['bytes'];
        }

        /** Stand in for the site having written the object. */
        public function receive(string $key, int $bytes, string $checksum): void
        {
            $this->objects[$key] = ['bytes' => $bytes, 'checksum' => $checksum];
        }
    };

    $this->makeArtifact = function (): array {
        $plaintext = '-- MySQL dump'.str_repeat("\nINSERT INTO entries VALUES (1);", 50);
        $key = ArtifactStream::generateKey();

        $in = fopen('php://temp', 'r+b');
        $stream = fopen('php://temp', 'r+b');
        fwrite($in, $plaintext);
        rewind($in);
        $written = ArtifactStream::encrypt($in, $stream, $key);
        rewind($stream);

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
                'recipients' => [[
                    'fingerprint' => $this->key->fingerprint,
                    'public_key' => $this->key->public_key,
                    'wrapped_key' => Sealing::seal($key, $this->key->public_key),
                ]],
            ],
            'integrity' => [
                'plaintext_sha256' => $written['plaintext_sha256'],
                'ciphertext_sha256' => $written['ciphertext_sha256'],
                'plaintext_bytes' => $written['plaintext_bytes'],
                'ciphertext_bytes' => $written['ciphertext_bytes'],
            ],
        ];

        $manifestBytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = ArtifactEnvelope::signManifest($manifestBytes, $this->keypair['secret']);

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
            'declaration' => [
                'schema_version' => 'backup.v2',
                'job_id' => $this->job->external_id,
                'manifest_b64' => base64_encode($manifestBytes),
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'manifest_signature' => $signature,
                'artifact_sha256' => hash('sha256', $bytes),
                'artifact_bytes' => strlen($bytes),
                'upload_mode' => 'direct',
            ],
        ];
    };

    $this->declare = fn (array $declaration) => postSignedConnectorRequest(
        '/api/connector/v1/backups',
        $declaration,
        $this->site,
        $this->keypair['secret'],
    );

    $this->reportUploaded = fn (string $artifactId) => postSignedConnectorRequest(
        "/api/connector/v1/backups/{$artifactId}/uploaded",
        [],
        $this->site,
        $this->keypair['secret'],
    );
});

/*
|--------------------------------------------------------------------------------------------------
| Self-hosted issues nothing
|--------------------------------------------------------------------------------------------------
*/

it('offers no grant on an edition that does not issue them', function (): void {
    $response = ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    // The self-hosted binding returns null and the response is the same three keys it has always
    // carried. An operator running Manager on one box has nothing to presign and nothing to gain.
    expect(app(DirectUploadGrants::class))->toBeInstanceOf(NoDirectUploads::class)
        ->and(array_keys($response->json()))->toBe(['artifact', 'already_declared', 'chunk_bytes']);
});

/*
|--------------------------------------------------------------------------------------------------
| What a grant may contain
|--------------------------------------------------------------------------------------------------
*/

it('never names a host in a grant', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $response = ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $grant = $response->json('upload');

    // The property the whole arrangement rests on. The connector builds the URL from a host in its own
    // configuration file, so a compromised platform can vary a path within a bucket the operator
    // already approved and can do nothing else. A `host` key here would silently undo that, which is
    // why this is a test rather than a comment.
    expect(array_keys($grant))->toBe(['job_id', 'path', 'query', 'headers', 'expires_at', 'max_bytes'])
        ->and($grant)->not->toHaveKey('host')
        ->and($grant)->not->toHaveKey('url')
        ->and($grant)->not->toHaveKey('endpoint')
        ->and($grant)->not->toHaveKey('bucket')
        ->and($grant['path'])->toStartWith('/')
        ->and(json_encode($grant))->not->toContain('http');
});

it('binds a grant to the checksum and size that were declared', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $granted = $this->fakeGrants->granted[0];

    // Issued after the declaration rather than when the job was handed out, which is the whole reason
    // it can be bound at all: only by now is the checksum known. The storage service then refuses a
    // body that hashes to anything else, so integrity is enforced where the bytes actually land.
    expect($granted['checksum'])->toBe(base64_encode((string) hex2bin($artifact['declaration']['artifact_sha256'])))
        ->and($granted['max'])->toBe($artifact['declaration']['artifact_bytes']);
});

it('derives the object key from platform identifiers alone', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    // Nothing a connector sent reaches the key, which is why there is no path traversal to worry
    // about here — there is no input to traverse with.
    expect($row->storage_key)->toBe($this->fakeGrants->granted[0]['key'])
        ->and($row->storage_key)->toContain("org-{$this->organisation->id}/")
        ->and($row->upload_mode)->toBe('direct');
});

it('keeps the presigned query string out of everything that persists', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);
    Log::spy();

    $response = ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $query = $response->json('upload.query');

    // A bearer credential. Anybody holding it can write that object until it expires, and SecretGuard's
    // pattern does not match `query` or `signature`, so nothing structural would stop it reaching an
    // audit row. This is the check.
    expect($query)->toContain('X-Amz-Signature');

    $persisted = AuditEvent::query()->get()->toJson()
        .BackupEvent::query()->get()->toJson()
        .BackupArtifact::query()->get()->toJson()
        .RemoteJob::query()->get()->toJson();

    expect($persisted)->not->toContain($query)
        ->and($persisted)->not->toContain('X-Amz-Signature');

    Log::shouldNotHaveReceived('info');
});

/*
|--------------------------------------------------------------------------------------------------
| Uploaded is not stored
|--------------------------------------------------------------------------------------------------
*/

it('will not call an artifact stored on a connector\'s word alone', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    // The site says it finished; storage has nothing. The platform did not witness the upload, so
    // "done" is a claim rather than an event, and this is the case where believing it would leave an
    // organisation holding a backup that does not exist.
    ($this->reportUploaded)($row->external_id)->assertStatus(422);

    expect($row->fresh()->isStored())->toBeFalse();
});

it('stores an artifact once storage confirms the checksum', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    $this->fakeGrants->receive(
        (string) $row->storage_key,
        $artifact['declaration']['artifact_bytes'],
        base64_encode((string) hex2bin($artifact['declaration']['artifact_sha256'])),
    );

    ($this->reportUploaded)($row->external_id)->assertOk();

    $row->refresh();

    expect($row->isStored())->toBeTrue()
        ->and($row->verified_at)->not->toBeNull()
        ->and($row->expires_at)->not->toBeNull();

    // The confirmation is recorded as the platform's own observation, because it is — it came from
    // storage, not from the site.
    $events = BackupEvent::query()->get();

    expect($events->where('event', BackupEvent::INTEGRITY_VERIFIED)->first()?->source)
        ->toBe(BackupEvent::SOURCE_PLATFORM)
        ->and($events->where('event', BackupEvent::UPLOAD_COMPLETED)->first()?->source)
        ->toBe(BackupEvent::SOURCE_CONNECTOR);
});

it('refuses an artifact whose stored checksum disagrees', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    // Something else entirely arrived at that key.
    $this->fakeGrants->receive(
        (string) $row->storage_key,
        $artifact['declaration']['artifact_bytes'],
        base64_encode(random_bytes(32)),
    );

    ($this->reportUploaded)($row->external_id)->assertStatus(422);

    expect($row->fresh()->state)->toBe(BackupArtifact::STATE_FAILED);
});

it('refuses an artifact stored at the wrong size', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    $this->fakeGrants->receive(
        (string) $row->storage_key,
        $artifact['declaration']['artifact_bytes'] - 100,
        base64_encode((string) hex2bin($artifact['declaration']['artifact_sha256'])),
    );

    ($this->reportUploaded)($row->external_id)->assertStatus(422);

    expect($row->fresh()->state)->toBe(BackupArtifact::STATE_FAILED);
});

it('treats a duplicate confirmation as the same confirmation', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();
    $this->fakeGrants->receive(
        (string) $row->storage_key,
        $artifact['declaration']['artifact_bytes'],
        base64_encode((string) hex2bin($artifact['declaration']['artifact_sha256'])),
    );

    $first = ($this->reportUploaded)($row->external_id)->assertOk();
    $second = ($this->reportUploaded)($row->external_id)->assertOk();

    // A connector that timed out waiting for the first answer will ask again, and must not be told its
    // backup failed. Same artifact, same expiry, one row.
    expect($second->json('artifact'))->toBe($first->json('artifact'))
        ->and($second->json('expires_at'))->toBe($first->json('expires_at'))
        ->and(BackupArtifact::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------------------------------
*/

it('will not let one site settle another site\'s artifact', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);

    $artifact = ($this->makeArtifact)();
    ($this->declare)($artifact['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();
    $this->fakeGrants->receive(
        (string) $row->storage_key,
        $artifact['declaration']['artifact_bytes'],
        base64_encode((string) hex2bin($artifact['declaration']['artifact_sha256'])),
    );

    $other = Site::factory()->for(Organisation::factory())->connected()->create();
    $otherKeys = Keys::generateKeypair();
    Connector::factory()->for($other)->create(['public_key' => $otherKeys['public']]);
    CapabilityGrant::factory()->for($other)->capability('backups:create')->create();

    // 404 rather than 403, uniform with everything else: a refusal that distinguished "not yours" from
    // "no such artifact" would tell a caller which identifiers exist.
    postSignedConnectorRequest(
        "/api/connector/v1/backups/{$row->external_id}/uploaded",
        [],
        $other,
        $otherKeys['secret'],
    )->assertNotFound();

    expect($row->fresh()->isStored())->toBeFalse();
});

it('refuses to confirm an artifact that was never sent direct', function (): void {
    app()->instance(DirectUploadGrants::class, $this->fakeGrants);
    $this->fakeGrants->issue = false;

    ($this->declare)(($this->makeArtifact)()['declaration'])->assertOk();

    $row = BackupArtifact::query()->firstOrFail();

    // No grant was issued, so there is no object anywhere to ask about, and settling it on a callback
    // would mark an artifact stored with nothing behind it.
    expect($row->upload_mode)->not->toBe('direct');

    ($this->reportUploaded)($row->external_id)->assertStatus(422);

    expect($row->fresh()->isStored())->toBeFalse();
});
