<?php

declare(strict_types=1);

use App\Contracts\StorageQuota;
use App\Domain\Backup\BackupRejectedException;
use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Organisation;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\ArtifactStream;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;
use Illuminate\Support\Facades\Storage;

/*
 * The aggregate storage limit.
 *
 * max_bytes stops one enormous artifact. This stops many ordinary ones filling the volume, after
 * which every site's backups fail at once, including the sites that were behaving.
 *
 * The order of the check inside declareArtifact is the part worth protecting: it runs after the
 * idempotency return, never before, so a connector retrying a declare for a backup it has already
 * taken gets its artifact back even when the organisation is now full.
 */

beforeEach(function (): void {
    Storage::fake('backups');

    $this->organisation = Organisation::factory()->create();
    $this->site = Site::factory()->for($this->organisation)->connected()->create();

    $keypair = Keys::generateKeypair();
    Connector::factory()->for($this->site)->create(['public_key' => $keypair['public']]);
    CapabilityGrant::factory()->for($this->site)->capability('backups:create')->create();

    $backupKeypair = Sealing::generateBoxKeypair();
    config([
        'manager.backups.public_key' => $backupKeypair['public'],
        'manager.backups.secret_key' => $backupKeypair['secret'],
    ]);

    $this->platformKey = $backupKeypair['public'];

    $this->declarationFor = function (RemoteJob $job): array {
        $key = ArtifactStream::generateKey();

        $in = fopen('php://temp', 'r+b');
        $out = fopen('php://temp', 'r+b');
        fwrite($in, str_repeat('x', 4096));
        rewind($in);
        $written = ArtifactStream::encrypt($in, $out, $key);
        fclose($in);
        fclose($out);

        return [
            'schema_version' => 'backup.v1',
            'job_id' => $job->external_id,
            'artifact' => [
                'scheme' => ArtifactStream::SCHEME,
                'header' => $written['header'],
                'sealed_key' => Sealing::seal($key, $this->platformKey),
                'ciphertext_sha256' => $written['ciphertext_sha256'],
                'plaintext_sha256' => $written['plaintext_sha256'],
                'ciphertext_bytes' => $written['ciphertext_bytes'],
                'plaintext_bytes' => $written['plaintext_bytes'],
                'chunk_bytes' => Protocol::ARTIFACT_CHUNK_BYTES,
                'taken_at' => time(),
                'compressed' => false,
            ],
        ];
    };

    $this->job = fn (): RemoteJob => RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
    ]);
});

it('accepts artifacts when no quota is configured', function (): void {
    config(['manager.backups.quota_bytes' => null]);

    $job = ($this->job)();

    $artifact = app(BackupService::class)->declareArtifact($this->site, $job, ($this->declarationFor)($job));

    expect($artifact->state)->toBe(BackupArtifact::STATE_PENDING);
});

it('refuses a declaration that would take the organisation past its quota', function (): void {
    config(['manager.backups.quota_bytes' => 1024]);

    $job = ($this->job)();

    expect(fn () => app(BackupService::class)->declareArtifact($this->site, $job, ($this->declarationFor)($job)))
        ->toThrow(BackupRejectedException::class);
});

it('counts pending artifacts, so an organisation cannot declare its way past the limit', function (): void {
    // The bytes have not arrived yet, but they have been promised. Not counting them would let a
    // site declare ten artifacts in a row and blow the quota before any of them uploaded.
    config(['manager.backups.quota_bytes' => 1_000_000]);

    $first = ($this->job)();
    $declared = app(BackupService::class)->declareArtifact($this->site, $first, ($this->declarationFor)($first));

    expect($declared->state)->toBe(BackupArtifact::STATE_PENDING);

    config(['manager.backups.quota_bytes' => $declared->ciphertext_bytes + 10]);

    $second = ($this->job)();

    expect(fn () => app(BackupService::class)->declareArtifact($this->site, $second, ($this->declarationFor)($second)))
        ->toThrow(BackupRejectedException::class);
});

it('still returns the existing artifact when a full organisation retries a declaration', function (): void {
    /*
     * Invariant 16 against the quota, and the reason the check sits where it does.
     *
     * The connector has already taken this backup. Rejecting its retry would make it throw away a
     * dump it has on disk, for a limit that has nothing to do with the request it is making.
     */
    config(['manager.backups.quota_bytes' => 1_000_000]);

    $job = ($this->job)();
    $declaration = ($this->declarationFor)($job);

    $first = app(BackupService::class)->declareArtifact($this->site, $job, $declaration);

    config(['manager.backups.quota_bytes' => 1]);

    $retry = app(BackupService::class)->declareArtifact($this->site, $job, $declaration);

    expect($retry->id)->toBe($first->id)
        ->and(BackupArtifact::query()->where('remote_job_id', $job->id)->count())->toBe(1);
});

it('does not let one organisation consume another organisation quota', function (): void {
    config(['manager.backups.quota_bytes' => 1_000_000]);

    $job = ($this->job)();
    $mine = app(BackupService::class)->declareArtifact($this->site, $job, ($this->declarationFor)($job));

    $otherOrg = Organisation::factory()->create();
    $otherSite = Site::factory()->for($otherOrg)->connected()->create();

    // A neighbour's usage must not appear in this organisation's remaining allowance.
    $remaining = app(StorageQuota::class)->remainingBytes($otherOrg);

    expect($remaining)->toBe(1_000_000)
        ->and(app(StorageQuota::class)->remainingBytes($this->organisation))
        // The rule, rather than one of the two columns that used to disagree about it.
        ->toBe(1_000_000 - $mine->expectedUploadBytes());
});

it('measures the bytes storage holds, not the ciphertext the connector declared', function (): void {
    /*
     | The two numbers are different, and only one of them is checked.
     |
     | `artifact_bytes` is the whole file - the encrypted stream inside its envelope - and it is
     | settled to the real size when the upload completes. `ciphertext_bytes` is the stream alone, is
     | declared by the connector, and nothing ever compares it to anything.
     |
     | Admission control already used artifact_bytes. Every meter summed ciphertext_bytes, so the
     | quota admitted on one number and reported on a different, unverified one - and a connector
     | under-declaring it would have had its storage counted as almost nothing.
    */
    config(['manager.backups.quota_bytes' => 1_000_000]);

    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'format_version' => BackupArtifact::FORMAT_V2,
        'ciphertext_bytes' => 1_000,
        'artifact_bytes' => 400_000,
    ]);

    // 600_000, not 999_000.
    expect(app(StorageQuota::class)->remainingBytes($this->organisation))->toBe(600_000);
});

it('falls back to the ciphertext size for a v1 artifact, which has no envelope', function (): void {
    // A v1 artifact is a bare encrypted stream, so there is no second number and ciphertext_bytes is
    // the whole file. Artifacts written under v1 still exist and still count against the quota.
    config(['manager.backups.quota_bytes' => 1_000_000]);

    BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'format_version' => BackupArtifact::FORMAT_V1,
        'ciphertext_bytes' => 250_000,
        'artifact_bytes' => null,
    ]);

    expect(app(StorageQuota::class)->remainingBytes($this->organisation))->toBe(750_000);
});
