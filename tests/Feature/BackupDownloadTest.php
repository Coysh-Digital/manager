<?php

declare(strict_types=1);

use App\Contracts\ObjectStore;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\Site;
use App\Models\User;

/*
 | Downloading an artifact's ciphertext.
 |
 | The whole design is in one distinction: this route hands over the bytes exactly as they are stored
 | and never decrypts anything. That is what lets a download button exist at all, after years of a
 | reasoned argument against one - the argument was about decrypting inside a web request, and it is
 | still enforced everywhere.
 |
 | It exists because without it the zero-knowledge format was unusable on Cloud. The console correctly
 | refuses to decrypt a v2 artifact and tells the customer to run `manager-restore decrypt`; the bucket
 | is ours, so there was no way for them to obtain the file to run it on.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->member)->for($this->organisation)->create();

    $this->site = Site::factory()->for($this->organisation)->connected()->create();
    RecoveryKey::factory()->for($this->organisation)->create();

    $this->artifact = BackupArtifact::factory()->for($this->site)->create([
        'organisation_id' => $this->organisation->id,
        'state' => BackupArtifact::STATE_STORED,
        'storage_key' => 'org-1/site-1/2026/08/artifact-key.artifact',
    ]);

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

/**
 * A store that answers the way a given deployment would, so these tests are about the route's
 * decisions rather than about Flysystem's.
 */
function storeAnswering(?string $url, string $body = 'ciphertext'): void
{
    app()->instance(ObjectStore::class, new class($url, $body) implements ObjectStore
    {
        public function __construct(private readonly ?string $url, private readonly string $body) {}

        public function put(string $key, $stream): int
        {
            return 0;
        }

        public function readStream(string $key)
        {
            $handle = fopen('php://temp', 'r+b');
            fwrite($handle, $this->body);
            rewind($handle);

            return $handle;
        }

        public function exists(string $key): bool
        {
            return true;
        }

        public function delete(string $key): bool
        {
            return true;
        }

        public function bytes(string $key): int
        {
            return strlen($this->body);
        }

        public function temporaryUrl(string $key, int $seconds): ?string
        {
            return $this->url;
        }

        public function describe(): string
        {
            return 'test';
        }
    });
}

it('redirects to the store when the store can sign a URL', function (): void {
    // The Cloud path. A multi-gigabyte artifact must not travel through a worker: the browser fetches
    // it from the bucket, the transfer is resumable, and nothing of ours carries a customer's database
    // on the way past.
    storeAnswering('https://bucket.example.org/signed');

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact))
        ->assertRedirect('https://bucket.example.org/signed');
});

it('streams the ciphertext when the store has no URL to give', function (): void {
    // The self-hosted path on a local volume. Correct, and it holds a worker for the length of the
    // transfer - which is why the store is asked first rather than this being the only path.
    storeAnswering(null, 'encrypted-bytes');

    $response = $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact))
        ->assertOk();

    expect($response->streamedContent())->toBe('encrypted-bytes')
        ->and($response->headers->get('content-type'))->toBe('application/octet-stream')
        // Named after the artifact, because that is what `manager-restore` expects to be handed and
        // what the command printed on the screen refers to.
        ->and($response->headers->get('content-disposition'))
        ->toContain($this->artifact->external_id.'.artifact');
});

it('hands over ciphertext and never plaintext', function (): void {
    /*
     | The assertion this whole route rests on. If anything ever makes this decrypt on the way out,
     | the timeout argument that kept a download button away for years applies again - and worse, a
     | v2 artifact would have to be openable here, which would mean the platform holding a key it is
     | the entire point of the format for it not to hold.
    */
    storeAnswering(null, "stored-ciphertext-not-sql\x00\x01");

    $response = $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact));

    expect($response->streamedContent())->toBe("stored-ciphertext-not-sql\x00\x01")
        ->and($response->streamedContent())->not->toContain('CREATE TABLE');
});

it('opens a zero-knowledge artifact for download even though it cannot be read here', function (): void {
    // The case the route exists for. `manager:backups:fetch` refuses this one by design; if the
    // download refused it too there would be no way to reach a v2 backup on Cloud at all.
    storeAnswering('https://bucket.example.org/signed');

    $this->artifact->update(['format_version' => BackupArtifact::FORMAT_V2]);

    expect($this->artifact->isZeroKnowledge())->toBeTrue();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact))
        ->assertRedirect();
});

it('records who took a copy', function (): void {
    storeAnswering(null);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact));

    $event = AuditEvent::query()->where('action', 'backup.ciphertext_downloaded')->sole();

    expect($event->target_id)->toBe($this->artifact->external_id)
        ->and($event->actor_id)->toBe($this->owner->id)
        ->and($event->after['zero_knowledge'])->toBeFalse();
});

it('records how the bytes were delivered', function (?string $url, string $expected): void {
    // A Cloud installation quietly falling back to streaming is a fault, and the two deliveries have
    // different blast radii if anything is ever questioned: one means a customer's database passed
    // through this platform, the other means it did not.
    storeAnswering($url);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact));

    expect(AuditEvent::query()->where('action', 'backup.ciphertext_downloaded')->sole()->after['delivery'])
        ->toBe($expected);
})->with([
    'signed URL' => ['https://bucket.example.org/signed', 'redirect'],
    'no URL' => [null, 'stream'],
]);

it('refuses an artifact belonging to another organisation', function (): void {
    storeAnswering('https://bucket.example.org/signed');

    $other = Organisation::factory()->create();
    $otherSite = Site::factory()->for($other)->create();
    $theirs = BackupArtifact::factory()->for($otherSite)->create([
        'organisation_id' => $other->id,
        'state' => BackupArtifact::STATE_STORED,
        'storage_key' => 'org-2/site-2/2026/08/theirs.artifact',
    ]);

    // A 404 rather than a 403: whether an identifier exists is not something to confirm to somebody
    // with no business knowing.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $theirs))
        ->assertNotFound();
});

it('refuses a member who cannot administer', function (): void {
    storeAnswering('https://bucket.example.org/signed');

    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact))
        ->assertForbidden();
});

it('downloads without asking for the password again', function (): void {
    /*
     | This asserted the opposite, on the argument that a complete copy of a customer's database is
     | not something a session left open on an unlocked machine should be enough for. That argument
     | was not wrong, and it was weighed against the moment this button is actually pressed.
     |
     | Downloading is the one thing somebody does under pressure, at the moment a site is already
     | broken, and a password prompt between them and the backup is worst exactly when it matters
     | most. The bytes remain ciphertext this platform cannot open, sealed to recovery keys that
     | exist only where the customer put them, and the caller is still an administrator.
     |
     | Kept as an assertion rather than deleted because it is the one item on that list where the
     | trade is real. If it is ever re-gated, that should be a decision somebody makes here.
     */
    storeAnswering('https://bucket.example.org/signed');

    $this->actingAs($this->owner)
        ->get(route('backups.download', $this->artifact))
        ->assertRedirect('https://bucket.example.org/signed');
});

it('refuses an artifact whose bytes are not there', function (string $state): void {
    storeAnswering('https://bucket.example.org/signed');

    $this->artifact->update(['state' => $state]);

    // Deleted, still uploading, and failed all answer the same way. Which it was is on the screen the
    // caller came from; offering a download for bytes that are not there would produce a truncated
    // file with nothing to say so.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact))
        ->assertNotFound();
})->with([BackupArtifact::STATE_PENDING, BackupArtifact::STATE_FAILED, BackupArtifact::STATE_DELETED]);

it('writes no audit event when it refuses', function (): void {
    // Otherwise the log fills with rows saying a backup was downloaded by people who were turned
    // away, and the one row that matters stops standing out.
    storeAnswering('https://bucket.example.org/signed');

    $this->actingAs($this->member)->withSession($this->recentAuth)
        ->get(route('backups.download', $this->artifact));

    expect(AuditEvent::query()->where('action', 'backup.ciphertext_downloaded')->count())->toBe(0);
});
