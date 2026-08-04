<?php

declare(strict_types=1);

use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Support\Facades\Storage;

/**
 * The backup pipeline, exercised with real cryptography rather than doubles.
 *
 * A backup is the most sensitive thing this system handles, so the tests here are about what must not
 * be possible rather than what should work. In particular: an artifact must not be accepted unless it
 * arrived intact from the site that was asked for it, the platform must never read a body it has not
 * authenticated, and a retry must not produce a second copy of a customer database.
 *
 * Everything below uses genuine libsodium - real keys, real sealed boxes, real chunked streams. A mock
 * here would let a broken format pass, and a broken artifact format is only discovered when somebody
 * needs a backup.
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

    // Generated here rather than read from the environment. A configured installation has a backup
    // keypair, but a fresh checkout does not - and a suite that only passes on a machine where
    // somebody has run manager:keys:generate is a suite that stops running on CI.
    $backupKeypair = Sealing::generateBoxKeypair();

    config([
        'manager.backups.public_key' => $backupKeypair['public'],
        'manager.backups.secret_key' => $backupKeypair['secret'],
    ]);

    $this->platformKey = $backupKeypair['public'];

    // A claimed backup job, which is the only state an artifact may be declared against.
    $this->job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
    ]);

    /**
     * Produce a real encrypted artifact and the declaration that describes it.
     *
     * @return array{bytes: string, declaration: array<string, mixed>, key: string, plaintext: string}
     */
    $this->makeArtifact = function (?string $plaintext = null, ?string $sealTo = null): array {
        $plaintext ??= '-- MySQL dump'.str_repeat("\nINSERT INTO entries VALUES (1);", 200);

        $key = ArtifactStream::generateKey();

        $in = fopen('php://temp', 'r+b');
        $out = fopen('php://temp', 'r+b');
        fwrite($in, $plaintext);
        rewind($in);

        $written = ArtifactStream::encrypt($in, $out, $key);

        rewind($out);
        $bytes = (string) stream_get_contents($out);

        fclose($in);
        fclose($out);

        return [
            'bytes' => $bytes,
            'plaintext' => $plaintext,
            'key' => $key,
            'declaration' => [
                'schema_version' => 'backup.v1',
                'job_id' => $this->job->external_id,
                'artifact' => [
                    'scheme' => ArtifactStream::SCHEME,
                    'header' => $written['header'],
                    'sealed_key' => Sealing::seal($key, $sealTo ?? $this->platformKey),
                    'ciphertext_sha256' => $written['ciphertext_sha256'],
                    'plaintext_sha256' => $written['plaintext_sha256'],
                    'ciphertext_bytes' => $written['ciphertext_bytes'],
                    'plaintext_bytes' => $written['plaintext_bytes'],
                    'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
                    'taken_at' => time(),
                    'engine' => 'mysql',
                    'engine_version' => '8.0.36',
                    'compressed' => false,
                ],
            ],
        ];
    };

    $this->declare = fn (array $declaration) => postSignedConnectorRequest(
        '/api/connector/v1/backups',
        $declaration,
        $this->site,
        $this->keypair['secret'],
    );
});

// --------------------------------------------------------------------------------------------------
// Permission
// --------------------------------------------------------------------------------------------------

it('refuses an artifact from a site without the capability', function (): void {
    CapabilityGrant::query()->where('site_id', $this->site->id)->delete();

    $artifact = ($this->makeArtifact)();

    // Invariant 7 at the wire. Granting the permission in the interface and checking it at the endpoint
    // are two different things, and only the second one stops an upload.
    ($this->declare)($artifact['declaration'])->assertForbidden();

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses an artifact when the capability was revoked after the job was claimed', function (): void {
    $artifact = ($this->makeArtifact)();

    CapabilityGrant::query()
        ->where('site_id', $this->site->id)
        ->update(['state' => CapabilityGrant::STATE_REVOKED]);

    // The permission is checked when the artifact arrives, not only when the job was issued. A
    // revocation has to take effect on work already in flight or it is not a revocation.
    ($this->declare)($artifact['declaration'])->assertForbidden();
});

// --------------------------------------------------------------------------------------------------
// Declaration
// --------------------------------------------------------------------------------------------------

it('accepts a well-formed declaration and issues an artifact identifier', function (): void {
    $artifact = ($this->makeArtifact)();

    $response = ($this->declare)($artifact['declaration'])->assertOk();

    $stored = BackupArtifact::query()->sole();

    expect($response->json('artifact'))->toBe($stored->external_id)
        ->and($response->json('already_declared'))->toBeFalse()
        ->and($stored->state)->toBe(BackupArtifact::STATE_PENDING)
        ->and($stored->organisation_id)->toBe($this->organisation->id)
        // No bytes yet, so nowhere for them to be.
        ->and($stored->storage_key)->toBeNull();
});

it('tells the connector nothing about where the artifact will be stored', function (): void {
    $artifact = ($this->makeArtifact)();

    $body = ($this->declare)($artifact['declaration'])->assertOk()->json();

    // The connector uploads to the platform it is paired with. Handing it a storage location - even a
    // harmless-looking one - would mean it could be handed a different one.
    expect(array_keys($body))->toBe(['artifact', 'already_declared', 'chunk_bytes'])
        ->and(json_encode($body))->not->toContain('backups/')
        ->and(json_encode($body))->not->toContain('http');
});

it('refuses a declaration against a job belonging to another site', function (): void {
    $other = Site::factory()->for(Organisation::factory())->connected()->create();
    $theirJob = RemoteJob::factory()->for($other)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
    ]);

    $artifact = ($this->makeArtifact)();
    $artifact['declaration']['job_id'] = $theirJob->external_id;

    // Invariant 11. A site may only ever produce artifacts for work this platform asked *it* to do.
    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses a declaration against a job that was never claimed', function (string $state): void {
    $this->job->forceFill(['state' => $state])->save();

    ($this->declare)(($this->makeArtifact)()['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
})->with([
    'queued' => Jobs::STATE_QUEUED,
    'already succeeded' => Jobs::STATE_SUCCEEDED,
    'cancelled' => Jobs::STATE_CANCELLED,
    'expired' => Jobs::STATE_EXPIRED,
]);

it('refuses a declaration whose key was sealed to somebody else', function (): void {
    $attacker = Sealing::generateBoxKeypair();

    $artifact = ($this->makeArtifact)(sealTo: $attacker['public']);

    // Caught at the boundary rather than when somebody needs the backup. An artifact this platform
    // cannot open is worse than no artifact: it satisfies a retention policy and protects nobody.
    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

it('refuses a declaration with an unknown encryption scheme', function (): void {
    $artifact = ($this->makeArtifact)();
    $artifact['declaration']['artifact']['scheme'] = 'aes-256-cbc-homegrown-v2';

    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses a declaration with a chunk size it cannot read back', function (): void {
    $artifact = ($this->makeArtifact)();
    $artifact['declaration']['artifact']['chunk_bytes'] = 4096;

    // A reader has to frame the stream the way the writer did. Accepting a different size would store
    // something this build cannot decrypt.
    ($this->declare)($artifact['declaration'])->assertStatus(422);
});

it('refuses a declaration carrying anything outside the allowlist', function (): void {
    $artifact = ($this->makeArtifact)();
    $artifact['declaration']['artifact']['database_password'] = 'hunter2';

    // Rejected, not stripped. A connector sending this has misunderstood the boundary and silently
    // dropping the field would hide that until somebody added another one.
    ($this->declare)($artifact['declaration'])->assertStatus(422);

    expect(BackupArtifact::query()->count())->toBe(0);
});

// --------------------------------------------------------------------------------------------------
// Invariant 16: a retry must not run the action twice
// --------------------------------------------------------------------------------------------------

it('returns the same artifact when a declaration is retried', function (): void {
    $artifact = ($this->makeArtifact)();

    $first = ($this->declare)($artifact['declaration'])->assertOk();
    $second = ($this->declare)($artifact['declaration'])->assertOk();

    // One artifact, not two. A connector whose first attempt timed out after the platform recorded it
    // has to be able to find out, or it dumps the database again.
    expect(BackupArtifact::query()->count())->toBe(1)
        ->and($second->json('artifact'))->toBe($first->json('artifact'));
});

it('refuses a second upload for an artifact that already verified', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertStatus(422);

    // Still one stored artifact, and still the one that verified. A replayed upload must not be able to
    // overwrite a good backup with anything.
    expect(BackupArtifact::query()->stored()->count())->toBe(1);
});

// --------------------------------------------------------------------------------------------------
// The upload itself
// --------------------------------------------------------------------------------------------------

it('stores an artifact whose bytes match what was declared', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk()
        ->assertJson(['stored' => true]);

    $stored = BackupArtifact::query()->sole();

    expect($stored->state)->toBe(BackupArtifact::STATE_STORED)
        ->and($stored->storage_key)->toContain("org-{$this->organisation->id}/site-{$this->site->id}")
        ->and($stored->verified_at)->not->toBeNull()
        ->and($stored->expires_at)->not->toBeNull();

    Storage::disk('backups')->assertExists((string) $stored->storage_key);
});

it('refuses bytes that do not match the declared checksum', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    $tampered = $artifact['bytes'];
    $tampered[100] = $tampered[100] === "\x00" ? "\x01" : "\x00";

    // Signed over the promised hash, so the request authenticates - and is then rejected because the
    // bytes disagree with the promise. That division is the point: authentication says who sent it,
    // the checksum says whether it survived the journey.
    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $tampered, $this->site, $this->keypair['secret'], [
        'declared_hash' => hash('sha256', $artifact['bytes']),
    ])->assertStatus(422);

    $stored = BackupArtifact::query()->sole();

    expect($stored->state)->toBe(BackupArtifact::STATE_FAILED)
        // Nothing left behind, and no key kept for something that does not exist.
        ->and($stored->storage_key)->toBeNull()
        ->and($stored->wrapped_key)->toBeNull();

    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('refuses an upload whose signed hash is not the one that was declared', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    $substitute = str_repeat('x', 4096);

    // A properly signed request for entirely different content. Caught by comparing the signed hash
    // against the declaration before a byte is read, not by hashing the body and hoping.
    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $substitute, $this->site, $this->keypair['secret'])
        ->assertStatus(422);

    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_PENDING);
});

it('refuses an upload with no declared length', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    // 411, and refused before the body is touched. Without a declared length there is no way to refuse
    // an oversized upload except by receiving all of it.
    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'], [
        'content_length' => 0,
    ])->assertStatus(411);
});

it('refuses an upload larger than the configured ceiling', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    // Lowered after the declaration, so this exercises the check on the upload route rather than the
    // one on the declaration. Both exist; only one of them sees a body.
    config(['manager.backups.max_bytes' => 1024]);

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertStatus(413);
});

it('refuses nothing on size when no ceiling is configured', function (): void {
    /*
     | The other half of the test above, and the default.
     |
     | This middleware runs before anything that knows what an artifact is, so an unconditional
     | comparison here is the one that produces a 413 with no explanation attached. A blank
     | environment line made the ceiling zero and every upload was refused this way on a live
     | console - 2.1 MB included, for four nights. There is no longer a configuration that does it.
    */
    config(['manager.backups.max_bytes' => null]);

    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('still requires a declared length when there is no ceiling', function (): void {
    /*
     | Skipping the comparison must not skip the requirement.
     |
     | Content-Length is what makes refusing-before-reading possible at all, and it stays mandatory
     | whether or not there is a number to compare it against - an edition with no ceiling today may
     | have a quota tomorrow, and a route that accepts an undeclared length has no way to grow one.
    */
    config(['manager.backups.max_bytes' => null]);

    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'], [
        'content_length' => 0,
    ])->assertStatus(411);
});

it('refuses an unsigned upload', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'], [
        'drop_headers' => [Protocol::HEADER_SIGNATURE],
    ])->assertUnauthorized();
});

it('refuses an upload signed by a different site', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    $other = Site::factory()->for(Organisation::factory())->connected()->create();
    $theirKeys = Keys::generateKeypair();
    Connector::factory()->for($other)->create(['public_key' => $theirKeys['public']]);
    CapabilityGrant::factory()->for($other)->capability('backups:create')->create();

    // Invariant 11. Another site holds valid credentials of its own, and they must not reach this
    // artifact. A 404 rather than a 403: whether that identifier exists is not their business.
    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $other, $theirKeys['secret'])
        ->assertNotFound();

    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_PENDING);
});

it('refuses a replayed upload', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    $nonce = Nonce::generate();

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'], [
        'nonce' => $nonce,
    ])->assertOk();

    // Invariant 16 again, at the transport level this time. The identical request a second time is
    // refused by the nonce store before it reaches anything that could act on it.
    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'], [
        'nonce' => $nonce,
    ])->assertUnauthorized();
});

// --------------------------------------------------------------------------------------------------
// Reading it back
// --------------------------------------------------------------------------------------------------

it('reads back exactly what the site dumped', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    $stored = BackupArtifact::query()->sole();

    $output = fopen('php://temp', 'r+b');
    $result = app(BackupService::class)->readArtifactTo($stored, $output);
    rewind($output);

    // The whole pipeline, in reverse: unwrap the key, decrypt the stream, verify against the checksum
    // taken on the site. Byte-for-byte, or the backup is not a backup.
    expect(stream_get_contents($output))->toBe($artifact['plaintext'])
        ->and($result['plaintext_sha256'])->toBe($stored->plaintext_sha256);

    fclose($output);
});

it('refuses to hand back an artifact whose stored bytes were altered', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    $stored = BackupArtifact::query()->sole();
    $key = (string) $stored->storage_key;

    // Somebody with access to the bucket, but not to the keys.
    $bytes = (string) Storage::disk('backups')->get($key);
    $bytes[strlen($bytes) - 5] = $bytes[strlen($bytes) - 5] === "\x00" ? "\x01" : "\x00";
    Storage::disk('backups')->put($key, $bytes);

    $output = fopen('php://temp', 'r+b');

    expect(fn () => app(BackupService::class)->readArtifactTo($stored, $output))
        ->toThrow(BackupRejectedException::class);

    fclose($output);
});

it('cannot open an artifact from the database alone', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    $raw = (string) DB::table('backup_artifacts')->value('wrapped_key');

    // Wrapped by the key service and encrypted again at the model boundary. Somebody holding a database
    // dump and the bucket still needs APP_KEY, which is the point of wrapping it twice.
    expect($raw)->not->toContain($artifact['key'])
        ->and($raw)->not->toBe(base64_encode($artifact['key']))
        ->and(strlen($raw))->toBeGreaterThan(64);
});

// --------------------------------------------------------------------------------------------------
// Invariant 13: no secrets, and no backup content, in the audit log
// --------------------------------------------------------------------------------------------------

it('records the artifact without recording anything from inside it', function (): void {
    $artifact = ($this->makeArtifact)();
    $id = ($this->declare)($artifact['declaration'])->json('artifact');

    putSignedArtifact("/api/connector/v1/backups/{$id}/content", $artifact['bytes'], $this->site, $this->keypair['secret'])
        ->assertOk();

    $events = AuditEvent::query()->whereIn('action', ['backup.declared', 'backup.stored'])->get();

    expect($events)->toHaveCount(2);

    $serialised = $events->toJson();

    // Sizes and checksums are recorded; the key, the sealed key and the artifact itself are not.
    expect($serialised)->toContain($artifact['declaration']['artifact']['plaintext_sha256'])
        ->and($serialised)->not->toContain($artifact['declaration']['artifact']['sealed_key'])
        ->and($serialised)->not->toContain(base64_encode($artifact['key']))
        ->and($serialised)->not->toContain('INSERT INTO');
});

it('derives the storage key from platform identifiers only', function (): void {
    $artifact = BackupArtifact::factory()->for($this->site)->create();

    $key = app(BackupService::class)->storageKeyFor($artifact);

    // Nothing a connector sent reaches a path, which is why there is no traversal to defend against —
    // there is no input to traverse with.
    expect($key)->toBe(
        "org-{$this->organisation->id}/site-{$this->site->id}/"
        .$artifact->taken_at->format('Y/m')."/{$artifact->external_id}.artifact"
    )
        ->and($key)->not->toContain('..')
        ->and($key)->not->toContain($this->site->name);
});
