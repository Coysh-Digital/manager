<?php

declare(strict_types=1);

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

/**
 * An artifact arriving a piece at a time.
 *
 * The same bytes {@see BackupPipelineTest} sends in one request, cut up - because a request carrying a
 * whole database is a request long enough for something in front of this application to end, and when
 * one does the site is handed an HTML error page with nothing in this platform's log to match it
 * against. That happened: a console answered a backup with a 502 carrying no correlation id, and every
 * runbook in these repositories was written for a 413.
 *
 * What must not be possible is the point here, as it is next door:
 *
 *  - nothing reaches the object store until the *whole* artifact matches the checksum inside the
 *    signed manifest, however many parts it arrived in;
 *  - a part cannot be written at an offset its signature did not cover;
 *  - a part that fails does not fail the backup, because one dropped connection should cost that part
 *    and not a customer's nightly copy of their database;
 *  - and a connector that loses its place is told where to resume rather than starting again.
 *
 * Real libsodium throughout, for the reason the pipeline test gives: a mock here would let a broken
 * format pass, and a broken artifact format is discovered when somebody needs a backup.
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

    $backupKeypair = Sealing::generateBoxKeypair();

    config([
        'manager.backups.public_key' => $backupKeypair['public'],
        'manager.backups.secret_key' => $backupKeypair['secret'],

        /*
         | Small enough that an ordinary test fixture becomes several parts.
         |
         | The same argument `manager.backups.part_bytes` makes for itself in config: a path exercised
         | only by a twenty-gigabyte artifact is a path whose first real exercise is somebody's
         | production backup. Here it also buys the case that catches off-by-ones - a size that does
         | not divide the artifact evenly, so the last part is short.
        */
        'manager.backups.ingest_part_bytes' => 1024,
    ]);

    $this->job = RemoteJob::factory()->for($this->site)->create([
        'type' => Jobs::BACKUP_CREATE,
        'state' => Jobs::STATE_CLAIMED,
    ]);

    $plaintext = '-- MySQL dump'.str_repeat("\nINSERT INTO entries VALUES (1);", 300);

    $key = ArtifactStream::generateKey();

    $in = fopen('php://temp', 'r+b');
    $out = fopen('php://temp', 'r+b');
    fwrite($in, $plaintext);
    rewind($in);

    $written = ArtifactStream::encrypt($in, $out, $key);

    rewind($out);
    $this->bytes = (string) stream_get_contents($out);

    fclose($in);
    fclose($out);

    $this->declaration = [
        'schema_version' => 'backup.v1',
        'job_id' => $this->job->external_id,
        'artifact' => [
            'scheme' => ArtifactStream::SCHEME,
            'header' => $written['header'],
            'sealed_key' => Sealing::seal($key, $backupKeypair['public']),
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
    ];

    $this->declare = fn () => postSignedConnectorRequest(
        '/api/connector/v1/backups',
        $this->declaration,
        $this->site,
        $this->keypair['secret'],
    );

    /** Send one part, sliced exactly as the platform decides it should be. */
    $this->sendPart = function (string $artifactId, int $part, ?string $body = null, array $overrides = []) {
        $partBytes = (int) config('manager.backups.ingest_part_bytes');
        $body ??= substr($this->bytes, ($part - 1) * $partBytes, $partBytes);

        return putSignedArtifact(
            "/api/connector/v1/backups/{$artifactId}/content/{$part}",
            $body,
            $this->site,
            $this->keypair['secret'],
            $overrides,
        );
    };

    $this->assemble = fn (string $artifactId) => postSignedConnectorRequest(
        "/api/connector/v1/backups/{$artifactId}/assembled",
        [],
        $this->site,
        $this->keypair['secret'],
    );

    $this->partCount = fn (): int => (int) ceil(strlen($this->bytes) / (int) config('manager.backups.ingest_part_bytes'));
});

// --------------------------------------------------------------------------------------------------
// The ordinary path
// --------------------------------------------------------------------------------------------------

it('stores an artifact that arrived in several parts', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    // Several, and the last one short. An artifact that divided evenly would never exercise the
    // arithmetic that decides how much the final request carries.
    expect(($this->partCount)())->toBeGreaterThan(2)
        ->and(strlen($this->bytes) % (int) config('manager.backups.ingest_part_bytes'))->not->toBe(0);

    for ($part = 1; $part <= ($this->partCount)(); $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    ($this->assemble)($artifactId)->assertOk()->assertJson(['stored' => true]);

    $stored = BackupArtifact::query()->sole();

    expect($stored->state)->toBe(BackupArtifact::STATE_STORED)
        ->and($stored->storage_key)->not->toBeNull()
        ->and(Storage::disk('backups')->get($stored->storage_key))->toBe($this->bytes);
});

it('tells a connector which part to send next, and says when there is no next one', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');
    $last = ($this->partCount)();

    // Said plainly rather than left to be computed from two numbers, because computing it is where an
    // off-by-one lives - and an off-by-one here is a hole in somebody's database.
    ($this->sendPart)($artifactId, 1)->assertOk()->assertJson(['next_part' => 2]);

    for ($part = 2; $part < $last; $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    ($this->sendPart)($artifactId, $last)->assertOk()->assertJson(['next_part' => null]);
});

it('answers a repeated assembly with the same result rather than a failure', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    for ($part = 1; $part <= ($this->partCount)(); $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    $first = ($this->assemble)($artifactId)->assertOk()->json();

    // A connector that timed out waiting for the answer sends this again, and must not be told its
    // backup failed. Same reasoning as the direct-upload confirmation next door.
    expect(($this->assemble)($artifactId)->assertOk()->json())->toBe($first);
});

// --------------------------------------------------------------------------------------------------
// Retries and lost places
// --------------------------------------------------------------------------------------------------

it('accepts the same part twice, so a dropped connection costs one part', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    ($this->sendPart)($artifactId, 1)->assertOk();
    ($this->sendPart)($artifactId, 2)->assertOk();

    // Re-sent because the connector never saw the response. The staging file is cut back to where this
    // part starts and rewritten, which is what makes retrying free rather than corrupting.
    ($this->sendPart)($artifactId, 2)->assertOk()->assertJson(['received_bytes' => 2048]);

    for ($part = 3; $part <= ($this->partCount)(); $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    ($this->assemble)($artifactId)->assertOk();

    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_STORED);
});

it('refuses a part that would leave a hole, and says where to resume', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    ($this->sendPart)($artifactId, 1)->assertOk();

    // Skipping straight to part four would write past a gap, and nothing would notice until the
    // whole-file checksum failed - which is to say, after the site had uploaded everything.
    ($this->sendPart)($artifactId, 4)
        ->assertStatus(409)
        ->assertJson(['error' => 'part_out_of_order', 'resume_from_part' => 2, 'received_bytes' => 1024]);

    // The artifact is untouched. A connector losing its place is not a reason to lose a backup.
    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_PENDING);
});

it('refuses an assembly asked for before the last part, and says where to resume', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    ($this->sendPart)($artifactId, 1)->assertOk();

    ($this->assemble)($artifactId)
        ->assertStatus(409)
        ->assertJson(['error' => 'part_out_of_order', 'resume_from_part' => 2]);

    // Everything that arrived verified against its own hash, so this upload is still perfectly capable
    // of finishing. Throwing it away because a connector's arithmetic was off would cost a backup for
    // no reason at all.
    expect(BackupArtifact::query()->sole()->state)->toBe(BackupArtifact::STATE_PENDING);

    for ($part = 2; $part <= ($this->partCount)(); $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    ($this->assemble)($artifactId)->assertOk();
});

// --------------------------------------------------------------------------------------------------
// What must not be possible
// --------------------------------------------------------------------------------------------------

it('refuses a part whose bytes are not the ones its signature covered', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    $real = substr($this->bytes, 0, 1024);

    // Promising one thing and sending another. The signature covers the promise, so this is what a
    // request tampered with in flight looks like from here.
    ($this->sendPart)($artifactId, 1, str_repeat('x', 1024), [
        'declared_hash' => hash('sha256', $real),
        'sent_hash' => hash('sha256', $real),
    ])->assertStatus(422);

    $artifact = BackupArtifact::query()->sole();

    // Refused without advancing, and without failing the backup. One corrupted stream costs that part
    // and nothing else - a deliberate difference from the whole-file path, where a mismatch is the end
    // of the only request there was.
    expect($artifact->staged_bytes)->toBeNull()
        ->and($artifact->state)->toBe(BackupArtifact::STATE_PENDING);

    for ($part = 1; $part <= ($this->partCount)(); $part++) {
        ($this->sendPart)($artifactId, $part)->assertOk();
    }

    ($this->assemble)($artifactId)->assertOk();
});

it('refuses a part that is not the length that part must be', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    // Checked from Content-Length before the body is read at all. The whole-file route can compare the
    // signed hash against the declaration before reading; a part has no pre-declared hash, so the
    // length is the guard that runs first.
    ($this->sendPart)($artifactId, 1, str_repeat('x', 512))->assertStatus(422);

    expect(BackupArtifact::query()->sole()->staged_bytes)->toBeNull();
});

it('stores nothing when the parts assemble into something the manifest did not describe', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');
    $last = ($this->partCount)();

    for ($part = 1; $part <= $last; $part++) {
        // A middle part replaced wholesale, and signed for - which is what a site sending the wrong
        // bytes looks like, as opposed to a request corrupted on the way. Every part verifies; the
        // whole does not.
        $body = $part === 2
            ? str_repeat('x', 1024)
            : substr($this->bytes, ($part - 1) * 1024, 1024);

        ($this->sendPart)($artifactId, $part, $body)->assertOk();
    }

    ($this->assemble)($artifactId)->assertStatus(422);

    $artifact = BackupArtifact::query()->sole();

    expect($artifact->state)->toBe(BackupArtifact::STATE_FAILED)
        ->and($artifact->failure_reason)->toContain('did not match the declared checksum')
        // The whole point of staging locally and hashing before storing. An unverified artifact must
        // never exist in the object store, not even briefly.
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('leaves nothing on disk once an artifact has been settled either way', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    ($this->sendPart)($artifactId, 1)->assertOk();

    $staging = storage_path('app/private/backup-staging/'.$artifactId.'.part');

    expect(is_file($staging))->toBeTrue();

    // What is sitting there is a partial encrypted copy of a customer's database. It has no business
    // outliving the upload, and failing an artifact is the one funnel every abandoned upload goes
    // through - which is why the cleanup lives there rather than at each call site.
    app(BackupService::class)->fail(
        BackupArtifact::query()->sole(),
        'test',
        notify: false,
    );

    expect(is_file($staging))->toBeFalse()
        ->and(BackupArtifact::query()->sole()->staged_bytes)->toBeNull();
});

it('will not let one site send parts of another site\'s artifact', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    $other = Site::factory()->for(Organisation::factory())->connected()->create();
    $otherKeypair = Keys::generateKeypair();
    Connector::factory()->for($other)->create(['public_key' => $otherKeypair['public']]);
    CapabilityGrant::factory()->for($other)->capability('backups:create')->create();

    // A uniform 404 rather than a distinction between "no such artifact" and "not yours", which would
    // answer the question of which identifiers exist.
    putSignedArtifact(
        "/api/connector/v1/backups/{$artifactId}/content/1",
        substr($this->bytes, 0, 1024),
        $other,
        $otherKeypair['secret'],
    )->assertNotFound();

    expect(BackupArtifact::query()->sole()->staged_bytes)->toBeNull();
});

it('refuses parts for an artifact that was never offered them', function (): void {
    $artifactId = ($this->declare)()->assertOk()->json('artifact');

    // What an artifact declared before this platform accepted parts looks like. It was promised the
    // single-request path and must keep it, rather than being offered a second one halfway through.
    BackupArtifact::query()->sole()->forceFill(['ingest_part_bytes' => null])->save();

    ($this->sendPart)($artifactId, 1)->assertStatus(422);
});
