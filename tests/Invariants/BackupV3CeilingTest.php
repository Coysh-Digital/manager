<?php

declare(strict_types=1);

use App\Contracts\BackupSizeLimit;
use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where the ceiling on a backup lives.
 *
 * It used to live in the wire contract. `backup.v2` declared `artifact_bytes` with a 2 GiB maximum
 * and described it as "the platform's artifact limit", which it was not — it was the protocol's, and
 * no platform could change it. Manager Cloud had already decided the opposite for its own customers:
 * storage it sells is billed for rather than capped, and it tells connectors there is no limit. The
 * wire contract then refused anyway, after a site had dumped and encrypted its whole database.
 *
 * So `backup.v3` carries no maximum and this file is where the consequence is pinned down: an
 * artifact is bounded by `manager.backups.max_bytes`, an operator can move that, and the refusal says
 * so. Everything else about a v3 artifact is a v2 artifact — same envelope, same signing prefix, same
 * encryption — and the tests that prove *that* live in BackupV2PipelineTest, which still passes
 * unchanged.
 */
beforeEach(function (): void {
    Storage::fake('backups');

    /*
     | Generated here rather than read from the environment.
     |
     | The claim response is signed, so the three negotiation tests at the foot of this file need a
     | platform keypair. A configured installation has one and a fresh checkout does not — which is
     | exactly the trap BackupPipelineTest names: a suite that only passes on a machine where somebody
     | has run `manager:keys:generate` is a suite that stops running on CI. It did, and this is why.
     */
    config([
        'manager.signing.public_key' => ($platform = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $platform['secret'],
    ]);

    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create();

    $this->keypair = Keys::generateKeypair();
    Connector::factory()->for($this->site)->create(['public_key' => $this->keypair['public']]);

    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $this->key = RecoveryKey::factory()->for($this->organisation)->create(['label' => 'Ops laptop']);
    RecoveryKeyFactory::secretFor($this->key->fingerprint);

    $this->organisation->forceFill(['backup_format_floor' => Protocol::BACKUP_FORMAT_V2])->save();

    $this->job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
        'backup_recipient_fingerprints' => [$this->key->fingerprint],
    ]);

    /**
     * A genuine artifact, and the declaration describing it, under either version.
     *
     * Real cryptography rather than doubles, for the same reason the v2 pipeline uses it: the
     * declaration path verifies a signature over the manifest bytes, and a fake would only prove that
     * the fake agreed with itself.
     *
     * `$claimBytes` lets a declaration describe an artifact far larger than the one actually built.
     * That is not a cheat — declaring is a separate step from uploading, and the size is checked
     * against the ceiling here and against the arriving stream later. It is the only way to exercise
     * a twenty-gigabyte declaration without writing twenty gigabytes.
     *
     * @return array{bytes: string, declaration: array<string, mixed>}
     */
    $this->makeArtifact = function (
        string $version = 'backup.v3',
        ?int $claimBytes = null,
        ?callable $mutateManifest = null,
        ?string $manifestVersion = null,
    ): array {
        $plaintext = '-- MySQL dump'.str_repeat("\nINSERT INTO entries VALUES (1);", 50);

        $key = ArtifactStream::generateKey();

        $in = fopen('php://temp', 'r+b');
        $stream = fopen('php://temp', 'r+b');
        fwrite($in, $plaintext);
        rewind($in);

        $written = ArtifactStream::encrypt($in, $stream, $key);
        rewind($stream);

        $manifest = [
            'manifest_version' => $manifestVersion ?? ($version === 'backup.v3' ? 'backup-manifest.v3' : 'backup-manifest.v2'),
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
                    'label' => 'Ops laptop',
                ]],
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
        $signature = ArtifactEnvelope::signManifest($manifestBytes, $this->keypair['secret']);

        $file = fopen('php://temp', 'r+b');
        ArtifactEnvelope::write($file, $manifestBytes, $signature);
        stream_copy_to_stream($stream, $file);
        rewind($file);
        $bytes = (string) stream_get_contents($file);

        fclose($in);
        fclose($stream);
        fclose($file);

        $declaration = [
            'schema_version' => $version,
            'job_id' => $this->job->external_id,
            'manifest_b64' => base64_encode($manifestBytes),
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'manifest_signature' => $signature,
            'artifact_sha256' => hash('sha256', $bytes),
            'artifact_bytes' => $claimBytes ?? strlen($bytes),
            'upload_mode' => 'platform',
        ];

        if ($version === 'backup.v3') {
            $declaration['artifact_crc32c'] = hash('crc32c', $bytes);
        }

        return ['bytes' => $bytes, 'declaration' => $declaration];
    };

    $this->declare = fn (array $declaration) => postSignedConnectorRequest(
        '/api/connector/v1/backups',
        $declaration,
        $this->site,
        $this->keypair['secret'],
    );
});

it('accepts a v3 declaration', function (): void {
    $artifact = ($this->makeArtifact)();

    ($this->declare)($artifact['declaration'])->assertOk();

    expect(BackupArtifact::query()->count())->toBe(1)
        ->and(BackupArtifact::query()->first()->artifact_crc32c)
        ->toBe($artifact['declaration']['artifact_crc32c']);
});

it('accepts an artifact far larger than v2 permitted', function (): void {
    // Twenty gigabytes: the size that was being refused nightly on a live site.
    $twentyGigabytes = 20 * 1024 ** 3;

    config()->set('manager.backups.max_bytes', 64 * 1024 ** 3);

    $artifact = ($this->makeArtifact)(
        claimBytes: $twentyGigabytes,
        mutateManifest: static function (array $manifest) use ($twentyGigabytes): array {
            // Both halves of the old wall. A declaration let through on size would still have been
            // refused a step later, because the manifest is validated too.
            $manifest['integrity']['plaintext_bytes'] = $twentyGigabytes;
            $manifest['integrity']['ciphertext_bytes'] = $twentyGigabytes;

            return $manifest;
        },
    );

    ($this->declare)($artifact['declaration'])->assertOk();

    expect((int) BackupArtifact::query()->first()->artifact_bytes)->toBe($twentyGigabytes);
});

it('refuses the same artifact when the ceiling is lowered, and only then', function (): void {
    /*
     | The whole point of the change, as one assertion.
     |
     | The identical declaration is accepted and refused depending on nothing but a config value. That
     | is what "the ceiling moved to the platform" means: before, no value of this setting could have
     | let the first case through.
     */
    $tenGigabytes = 10 * 1024 ** 3;

    $artifact = ($this->makeArtifact)(claimBytes: $tenGigabytes, mutateManifest: static function (array $manifest) use ($tenGigabytes): array {
        $manifest['integrity']['plaintext_bytes'] = $tenGigabytes;
        $manifest['integrity']['ciphertext_bytes'] = $tenGigabytes;

        return $manifest;
    });

    config()->set('manager.backups.max_bytes', $tenGigabytes - 1);

    $refused = ($this->declare)($artifact['declaration']);

    $refused->assertStatus(422);

    // Naming the number and the setting, because a configurable ceiling nobody is told about is not
    // configurable. Sizes only — the connector already knows both of these.
    expect($refused->json('reason'))->toContain((string) $tenGigabytes)
        ->and($refused->json('reason'))->toContain('MANAGER_BACKUP_MAX_BYTES');

    config()->set('manager.backups.max_bytes', $tenGigabytes);

    ($this->declare)($artifact['declaration'])->assertOk();
});

it('still holds a v2 declaration to v2 limits', function (): void {
    // Add-only, asserted from the platform's side. A connector that has not been upgraded gets the
    // answer its own version has always given, including the ceiling.
    config()->set('manager.backups.max_bytes', 64 * 1024 ** 3);

    $artifact = ($this->makeArtifact)('backup.v2', claimBytes: Protocol::MAX_ARTIFACT_BYTES + 1);

    $refused = ($this->declare)($artifact['declaration']);

    $refused->assertStatus(422);
    expect($refused->json('reason'))->toContain('backup.v2')
        ->and($refused->json('reason'))->toContain('artifact_bytes');
});

it('requires a v3 declaration to carry a v3 manifest', function (): void {
    // Strict pairing rather than a matrix of four. Without it a v2 manifest could ride inside a v3
    // declaration, or the reverse — and the reverse is the dangerous direction, because it would put
    // a twenty-gigabyte manifest behind a schema whose job was to bound it.
    $artifact = ($this->makeArtifact)('backup.v3', manifestVersion: 'backup-manifest.v2');

    $refused = ($this->declare)($artifact['declaration']);

    $refused->assertStatus(422);
    expect($refused->json('reason'))->toContain('backup-manifest.v3');
});

it('names the manifest field that failed, rather than nothing at all', function (): void {
    /*
     | This was a bug, and the shape of it is worth keeping.
     |
     | The manifest refusal interpolated `$problems` — the *declaration's* problems, necessarily empty
     | by the time execution reaches there — instead of `$manifestProblems`. So the one message
     | written to name the failing field produced "...did not satisfy backup-manifest.v3: ." and named
     | nothing. Exactly the diagnosis cost that adding field paths was meant to remove, surviving in
     | the half of the pair that had no test.
     */
    $artifact = ($this->makeArtifact)('backup.v3', mutateManifest: static function (array $manifest): array {
        $manifest['encryption']['chunk_bytes'] = 4096;

        return $manifest;
    });

    $refused = ($this->declare)($artifact['declaration']);

    $refused->assertStatus(422);
    expect($refused->json('reason'))->toContain('chunk_bytes')
        ->and($refused->json('reason'))->not->toContain('backup-manifest.v3: .');
});

it('refuses a v3 declaration with no crc, because a large one could not be assembled without it', function (): void {
    $artifact = ($this->makeArtifact)();
    unset($artifact['declaration']['artifact_crc32c']);

    $refused = ($this->declare)($artifact['declaration']);

    $refused->assertStatus(422);
    expect($refused->json('reason'))->toContain('artifact_crc32c');
});

/*
|--------------------------------------------------------------------------------------------------
| Negotiation
|--------------------------------------------------------------------------------------------------
|
| A connector learns which declarations this platform reads from the signed claim response. That is
| what lets the ceiling move without a cutover: neither side has to be upgraded before the other, and
| no backup is lost while a fleet catches up.
*/

it('advertises which declarations it accepts, newest first', function (): void {
    $response = postSignedConnectorRequest('/api/connector/v1/jobs/claim', [], $this->site, $this->keypair['secret']);

    $response->assertOk();

    expect($response->json('backup.declarations'))->toBe(['backup.v3', 'backup.v2'])
        ->and($response->json('backup.declarations.0'))->toBe('backup.v3');
});

it('advertises the ceiling, so a site is refused before it dumps', function (): void {
    config()->set('manager.backups.max_bytes', 12345678);

    $response = postSignedConnectorRequest('/api/connector/v1/jobs/claim', [], $this->site, $this->keypair['secret']);

    // Sent so a database larger than this fails before a dump exists, rather than after the site has
    // dumped, encrypted and offered twenty gigabytes. A size, never a destination.
    expect($response->json('backup.max_artifact_bytes'))->toBe(12345678);
});

it('advertises zero when there is no ceiling, rather than omitting the field', function (): void {
    /*
     * Zero is the wire's "no ceiling", and the connector reads it as one — it skips the check on
     * zero exactly as it does on an absent field. The field is still sent, because *absent* is how
     * a platform too old to have an opinion looks, and this platform has one.
     *
     * This is now the default. A ceiling of 2 GiB that nobody chose refused a 3.2 GB database on a
     * platform being paid to hold it, and refused it before the dump, which is the one place the
     * connector cannot be argued with.
     */
    config()->set('manager.backups.max_bytes', null);

    $response = postSignedConnectorRequest('/api/connector/v1/jobs/claim', [], $this->site, $this->keypair['secret']);

    expect($response->json('backup.max_artifact_bytes'))->toBe(0)
        ->and($response->json('backup'))->toHaveKey('max_artifact_bytes');
});

it('accepts an artifact of any size when nothing caps it', function (): void {
    /*
     | The default, now, and the test that would have caught the failure that started this.
     |
     | The case above proves a *raised* ceiling works. This one proves the absence of a ceiling
     | works, which is a different code path: every enforcement point has to skip rather than
     | compare against something enormous. Twenty gigabytes because that is the size backup.v2 could
     | not even describe.
     */
    config()->set('manager.backups.max_bytes', null);

    expect(app(BackupSizeLimit::class)->ceilingBytes())->toBeNull();

    $twentyGigabytes = 20 * 1024 ** 3;

    $artifact = ($this->makeArtifact)(
        claimBytes: $twentyGigabytes,
        mutateManifest: static function (array $manifest) use ($twentyGigabytes): array {
            $manifest['integrity']['plaintext_bytes'] = $twentyGigabytes;
            $manifest['integrity']['ciphertext_bytes'] = $twentyGigabytes;

            return $manifest;
        },
    );

    ($this->declare)($artifact['declaration'])->assertOk();

    expect((int) BackupArtifact::query()->first()->artifact_bytes)->toBe($twentyGigabytes);
});

it('keeps v2 on the accepted list, because connectors in the field still send it', function (): void {
    // Dropping it would be a decision to stop accepting backups from every connector not yet
    // upgraded, which is a different kind of change from adding v3.
    expect(BackupService::ACCEPTED_DECLARATIONS)->toContain('backup.v2');
});

it('carries no destination on the claim response', function (): void {
    $response = postSignedConnectorRequest('/api/connector/v1/jobs/claim', [], $this->site, $this->keypair['secret']);

    /** @var array<string, mixed> $backup */
    $backup = $response->json('backup');

    // The block that now carries a schema list and a size must not start carrying anything else. A
    // connector's own build check refuses a destination read from here; this refuses one being sent.
    foreach (['host', 'bucket', 'endpoint', 'url', 'destination', 'region'] as $forbidden) {
        expect(array_keys($backup))->not->toContain($forbidden);
    }
});
