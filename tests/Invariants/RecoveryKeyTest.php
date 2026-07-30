<?php

declare(strict_types=1);

use App\Domain\Backup\RecoveryKeyRejectedException;
use App\Domain\Backup\RecoveryKeyService;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\User;
use coyshdigital\managerprotocol\KeyFingerprint;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\RecoveryProof;
use coyshdigital\managerprotocol\Sealing;
use Database\Factories\RecoveryKeyFactory;

/**
 * Recovery keys, which are where the zero-knowledge claim is either true or decorative.
 *
 * The claim is that this platform cannot read a backup. That reduces to one question — *which public
 * keys does a site seal its next backup to* — and everything in this file is about the ways that
 * question could be answered wrongly:
 *
 *  - a key nobody actually holds gets enrolled, and the failure surfaces at restore time;
 *  - a key the platform holds gets enrolled, and nothing looks wrong;
 *  - a revoked key keeps being used, or a revocation retroactively locks somebody out of history;
 *  - one organisation's key reaches another organisation's site;
 *  - a fingerprint edited in the database points a site at a key it was never shown.
 *
 * There is deliberately no test that this platform can decrypt a v2 artifact, because it cannot, and
 * the absence of any code path that could is asserted directly.
 */
beforeEach(function (): void {
    $this->service = app(RecoveryKeyService::class);
    $this->organisation = Organisation::factory()->create();
    $this->actor = User::factory()->create();
    Membership::factory()->for($this->organisation)->for($this->actor)->owner()->create();
});

/**
 * A freshly generated keypair, of the kind `manager-restore keygen` produces.
 *
 * @return array{public: string, secret: string}
 */
function candidateKey(): array
{
    return Sealing::generateBoxKeypair();
}

/**
 * Complete a proof ceremony the way the restore tool would.
 */
function answerChallenge(RecoveryKey $key, string $secretKeyBase64): string
{
    $plaintext = Sealing::unseal((string) $key->challenge, $key->public_key, $secretKeyBase64);

    return RecoveryProof::responseFor($plaintext);
}

/*
|--------------------------------------------------------------------------------------------------
| The platform holds nothing that opens a backup
|--------------------------------------------------------------------------------------------------
*/

it('has nowhere to put a recovery private key', function (): void {
    $columns = Schema::getColumnListing('recovery_keys');

    // The single most important property of this table. Not "we do not write to it" — there is no
    // column, encrypted or otherwise, and no escrow option that could quietly grow one. An
    // organisation wanting escrow enrols the escrow holder's public key, which is the same outcome
    // done explicitly and visibly in every artifact's manifest.
    expect($columns)->toContain('public_key')
        ->and($columns)->not->toContain('secret_key')
        ->and($columns)->not->toContain('private_key')
        ->and($columns)->not->toContain('escrow_key')
        ->and($columns)->not->toContain('recovery_secret');

    foreach ($columns as $column) {
        expect($column)->not->toMatch('~secret|private~');
    }
});

it('offers no route that would generate a key on this server', function (): void {
    // A private key produced here would exist in a response body and possibly a proxy buffer, and the
    // claim that we cannot read backups would become a claim about our own discipline.
    $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri())->all();

    foreach ($uris as $uri) {
        expect($uri)->not->toContain('recovery-keys/generate')
            ->and($uri)->not->toContain('recovery-keys/create');
    }

    expect(method_exists(RecoveryKeyService::class, 'generate'))->toBeFalse()
        ->and(method_exists(RecoveryKeyService::class, 'generateKeypair'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------------------------------
| What may be enrolled
|--------------------------------------------------------------------------------------------------
*/

it('will not activate a key until somebody proves they hold the other half', function (): void {
    $candidate = candidateKey();

    $key = $this->service->submit($this->organisation, $candidate['public'], 'Ops laptop', $this->actor);

    // Structural validation establishes almost nothing about an X25519 key — any 32 bytes is a valid
    // one — so an unproven key is never a recipient of anything.
    expect($key->isAwaitingProof())->toBeTrue()
        ->and($key->activated_at)->toBeNull()
        ->and($this->service->activeFor($this->organisation))->toBeEmpty()
        ->and($this->service->recipientsFor($this->organisation))->toBe([]);

    $this->service->prove($key, answerChallenge($key, $candidate['secret']), $this->actor);

    expect($key->fresh()->isActive())->toBeTrue()
        ->and($this->service->activeFor($this->organisation))->toHaveCount(1);
});

it('refuses a proof from somebody who does not hold the key', function (): void {
    $key = $this->service->submit($this->organisation, candidateKey()['public'], null, $this->actor);

    expect(fn () => $this->service->prove($key, 'MGRP-0000-0000-0000-0000-0000-0000', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class);

    expect($key->fresh()->isAwaitingProof())->toBeTrue();
});

it('burns a challenge after too many wrong answers', function (): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);
    $correct = answerChallenge($key, $candidate['secret']);

    for ($i = 0; $i < RecoveryKey::MAX_CHALLENGE_ATTEMPTS; $i++) {
        try {
            $this->service->prove($key->fresh(), 'MGRP-0000-0000-0000-0000-0000-0000', $this->actor);
        } catch (RecoveryKeyRejectedException) {
            // Expected.
        }
    }

    // Even the right answer now. A challenge is 120 bits, so this is not about guessing — it is that
    // a challenge somebody has been fumbling for a while should be replaced rather than kept alive.
    expect(fn () => $this->service->prove($key->fresh(), $correct, $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'Too many wrong answers');
});

it('refuses a challenge that has expired', function (): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);
    $answer = answerChallenge($key, $candidate['secret']);

    $key->forceFill(['challenge_expires_at' => now()->subMinute()])->save();

    expect(fn () => $this->service->prove($key->fresh(), $answer, $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'expired');
});

it('accepts a proof however the operator retyped it', function (callable $mangle): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);

    $this->service->prove($key, $mangle(answerChallenge($key, $candidate['secret'])), $this->actor);

    expect($key->fresh()->isActive())->toBeTrue();
})->with([
    'canonical' => [fn (string $s): string => $s],
    'lowercase' => [fn (string $s): string => strtolower($s)],
    'run together' => [fn (string $s): string => str_replace('-', '', $s)],
    'pasted with whitespace' => [fn (string $s): string => "  {$s}\n"],
]);

it('refuses this platform\'s own artifact key', function (): void {
    $platform = Sealing::generateBoxKeypair();
    config(['manager.backups.public_key' => $platform['public'], 'manager.backups.secret_key' => $platform['secret']]);

    // Somebody has pasted the value out of an environment file. Accepting it would silently restore
    // the very arrangement recovery keys exist to end, and nothing on the screen would look wrong.
    expect(fn () => app(RecoveryKeyService::class)->submit($this->organisation, $platform['public'], null, $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'this platform\'s own encryption key');
});

it('refuses a key that would produce a backup anybody could read', function (string $hex): void {
    // Small-order Curve25519 points. libsodium would refuse to seal to one anyway — but it would
    // refuse on the night a site had already dumped its database to disk.
    expect(fn () => $this->service->submit($this->organisation, base64_encode((string) hex2bin($hex)), null, $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'anybody could read');
})->with([
    'identity' => '0000000000000000000000000000000000000000000000000000000000000000',
    'order one' => '0100000000000000000000000000000000000000000000000000000000000000',
    'order eight' => 'e0eb7a7c3b41b8ae1656e3faf19fc46ada098deb9c32b1fd866205165f49b800',
    'p minus one' => 'ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f',
]);

it('refuses something that is not a key at all', function (string $value): void {
    expect(fn () => $this->service->submit($this->organisation, $value, null, $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class);
})->with([
    'empty' => '',
    'prose' => 'my recovery key',
    'too short' => base64_encode('short'),
    'too long' => base64_encode(str_repeat("\x01", 64)),
    'an Ed25519 public key is the wrong length for this' => base64_encode(str_repeat("\x01", 33)),
]);

it('refuses the same key twice, and says so without answering a question about anybody else', function (): void {
    $candidate = candidateKey();
    $other = Organisation::factory()->create();

    $this->service->submit($this->organisation, $candidate['public'], 'First', $this->actor);

    expect(fn () => $this->service->submit($this->organisation, $candidate['public'], 'Again', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'already registered here');

    // The same key in a different organisation is fine, and must be. Refusing it would let one tenant
    // discover whether another had registered a given key by watching an insert fail.
    $elsewhere = $this->service->submit($other, $candidate['public'], 'Theirs', null);

    expect($elsewhere->exists)->toBeTrue();
});

it('bounds how many keys a backup can be sealed to', function (): void {
    for ($i = 0; $i < Protocol::MAX_BACKUP_RECIPIENTS; $i++) {
        $this->service->submit($this->organisation, candidateKey()['public'], "Key {$i}", $this->actor);
    }

    // Every recipient is another copy of the key that opens every backup, so the ceiling is the wire
    // format's rather than a matter of taste.
    expect(fn () => $this->service->submit($this->organisation, candidateKey()['public'], 'One more', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'recovery keys');
});

/*
|--------------------------------------------------------------------------------------------------
| Revocation
|--------------------------------------------------------------------------------------------------
*/

it('excludes a revoked key from new backups without touching old ones', function (): void {
    $keep = RecoveryKey::factory()->for($this->organisation)->create();
    $retire = RecoveryKey::factory()->for($this->organisation)->create();

    $before = $this->service->recipientsFor($this->organisation);

    $this->service->revoke($retire, 'Laptop replaced', $this->actor);

    $after = $this->service->recipientsFor($this->organisation);

    expect($before)->toHaveCount(2)
        ->and($after)->toHaveCount(1)
        ->and($after[0]['fingerprint'])->toBe($keep->fingerprint);

    // The row survives, with everything needed to explain a fingerprint that still appears in the
    // manifest of every backup taken before today. Revoking is not a way to make an old backup
    // unreadable and the model must not imply that it is.
    $retired = $retire->fresh();

    expect($retired)->not->toBeNull()
        ->and($retired->isRevoked())->toBeTrue()
        ->and($retired->public_key)->toBe($retire->public_key)
        ->and($retired->fingerprint)->toBe($retire->fingerprint);
});

it('will not silently revoke the last key that makes backups possible', function (): void {
    $only = RecoveryKey::factory()->for($this->organisation)->create();

    expect(fn () => $this->service->revoke($only, 'Tidying up', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'only active recovery key');

    expect($only->fresh()->isActive())->toBeTrue();

    // It is allowed, but the caller has to say it meant it.
    $this->service->revoke($only, 'Winding the organisation down', $this->actor, acceptLastKey: true);

    expect($only->fresh()->isRevoked())->toBeTrue()
        ->and($this->service->hasActiveKey($this->organisation))->toBeFalse();
});

it('offers no way back from revoked', function (): void {
    $key = RecoveryKey::factory()->for($this->organisation)->create();
    RecoveryKey::factory()->for($this->organisation)->create();

    $this->service->revoke($key, 'Rotated', $this->actor);

    // Not through prove(), which is the only other thing that sets a state.
    expect(fn () => $this->service->prove($key->fresh(), 'MGRP-0000-0000-0000-0000-0000-0000', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'revoked');

    // And not by re-enrolling the same key, which would produce two rows claiming the same fingerprint
    // and make an artifact's manifest ambiguous.
    expect(fn () => $this->service->submit($this->organisation, $key->public_key, 'Back again', $this->actor))
        ->toThrow(RecoveryKeyRejectedException::class, 'cannot be re-enrolled');
});

it('is idempotent when revoking twice', function (): void {
    RecoveryKey::factory()->for($this->organisation)->create();
    $key = RecoveryKey::factory()->for($this->organisation)->create();

    $this->service->revoke($key, 'First', $this->actor);
    $revokedAt = $key->fresh()->revoked_at;

    $this->service->revoke($key->fresh(), 'Second', $this->actor);

    expect($key->fresh()->revoked_reason)->toBe('First')
        ->and($key->fresh()->revoked_at?->toIso8601String())->toBe($revokedAt?->toIso8601String());
});

/*
|--------------------------------------------------------------------------------------------------
| Serving keys to sites
|--------------------------------------------------------------------------------------------------
*/

it('recomputes a fingerprint from the key before serving it', function (): void {
    $key = RecoveryKey::factory()->for($this->organisation)->create();

    // The stored fingerprint is an index, not evidence. A site pins against these values, so serving
    // one that had been edited in the database would let somebody with database access make a site
    // accept a key it was never shown.
    $key->forceFill(['fingerprint' => 'MGRK-0000-0000-0000-0000-0000-0000'])->save();

    expect($this->service->activeFor($this->organisation))->toBeEmpty()
        ->and($this->service->recipientsFor($this->organisation))->toBe([]);
});

it('excludes a tampered row rather than repairing it', function (): void {
    $good = RecoveryKey::factory()->for($this->organisation)->create();
    $tampered = RecoveryKey::factory()->for($this->organisation)->create();

    $tampered->forceFill(['public_key' => Sealing::generateBoxKeypair()['public']])->save();

    // Something has happened to the database. Quietly correcting it in a read path would hide that,
    // and the corrected value would be a key nobody enrolled.
    $recipients = $this->service->recipientsFor($this->organisation);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]['fingerprint'])->toBe($good->fingerprint)
        ->and($tampered->fresh()->fingerprint)->toBe($tampered->fingerprint);
});

it('never serves one organisation the keys of another', function (): void {
    $other = Organisation::factory()->create();

    $mine = RecoveryKey::factory()->for($this->organisation)->create();
    $theirs = RecoveryKey::factory()->for($other)->create();

    $recipients = $this->service->recipientsFor($this->organisation);
    $fingerprints = array_column($recipients, 'fingerprint');

    expect($fingerprints)->toContain($mine->fingerprint)
        ->and($fingerprints)->not->toContain($theirs->fingerprint);
});

it('serves a recipient list a connector can actually use', function (): void {
    RecoveryKey::factory()->for($this->organisation)->create(['label' => 'Ops laptop']);

    $recipients = $this->service->recipientsFor($this->organisation);

    // Fingerprint, public key, label. Nothing else — in particular nothing naming a destination, which
    // is the field somebody would eventually try to add here.
    expect(array_keys($recipients[0]))->toBe(['fingerprint', 'public_key', 'label'])
        ->and(KeyFingerprint::forRecoveryKey($recipients[0]['public_key']))->toBe($recipients[0]['fingerprint']);
});

/*
|--------------------------------------------------------------------------------------------------
| The format ratchet
|--------------------------------------------------------------------------------------------------
*/

it('moves an organisation to the zero-knowledge format on first activation', function (): void {
    expect($this->organisation->backup_format_floor)->toBe(Protocol::BACKUP_FORMAT_V1);

    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);

    // Submitting is not enough. An unproven key cannot encrypt anything, so raising the floor on
    // submission would stop backups with nothing yet able to take their place.
    expect($this->organisation->fresh()->backup_format_floor)->toBe(Protocol::BACKUP_FORMAT_V1);

    $this->service->prove($key, answerChallenge($key, $candidate['secret']), $this->actor);

    expect($this->organisation->fresh()->backup_format_floor)->toBe(Protocol::BACKUP_FORMAT_V2);
});

it('offers no way to lower the format floor', function (): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);
    $this->service->prove($key, answerChallenge($key, $candidate['secret']), $this->actor);

    // Revoking every key does not put an organisation back on a format this platform can read. Going
    // back means going back to backups we can decrypt, and that should not be a side effect of
    // anything.
    $this->service->revoke($key->fresh(), 'All of them', $this->actor, acceptLastKey: true);

    expect($this->organisation->fresh()->backup_format_floor)->toBe(Protocol::BACKUP_FORMAT_V2)
        ->and(method_exists(RecoveryKeyService::class, 'lowerFormatFloor'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------------------------------
| The record
|--------------------------------------------------------------------------------------------------
*/

it('audits every decision about who can read a backup', function (): void {
    $candidate = candidateKey();

    $key = $this->service->submit($this->organisation, $candidate['public'], 'Ops laptop', $this->actor);
    $this->service->prove($key, answerChallenge($key, $candidate['secret']), $this->actor);
    RecoveryKey::factory()->for($this->organisation)->create();
    $this->service->revoke($key->fresh(), 'Rotated', $this->actor);

    $actions = AuditEvent::query()
        ->where('organisation_id', $this->organisation->id)
        ->pluck('action')
        ->all();

    expect($actions)->toContain('backup.recovery_key.submitted')
        ->toContain('backup.recovery_key.activated')
        ->toContain('backup.recovery_key.revoked')
        ->toContain('backup.format_floor.raised');
});

it('audits a failed proof, because repeated ones are worth noticing', function (): void {
    $key = $this->service->submit($this->organisation, candidateKey()['public'], null, $this->actor);

    try {
        $this->service->prove($key, 'MGRP-0000-0000-0000-0000-0000-0000', $this->actor);
    } catch (RecoveryKeyRejectedException) {
        // Expected.
    }

    $event = AuditEvent::query()->where('action', 'backup.recovery_key.proof_failed')->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe(AuditEvent::OUTCOME_FAILURE);
});

it('never writes key material or a challenge into the audit trail', function (): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], 'Ops laptop', $this->actor);
    $challenge = (string) $key->challenge;
    $answer = answerChallenge($key, $candidate['secret']);

    $this->service->prove($key, $answer, $this->actor);

    $trail = AuditEvent::query()->get()->toJson();

    // The public key is harmless but adds nothing a fingerprint does not; the challenge and the answer
    // are neither. SecretGuard would not catch any of them — its pattern does not match `challenge` —
    // so this is the only thing standing between them and a log.
    expect($trail)->not->toContain($candidate['public'])
        ->and($trail)->not->toContain($candidate['secret'])
        ->and($trail)->not->toContain($challenge)
        ->and($trail)->not->toContain($answer)
        ->and($trail)->toContain($key->fingerprint);
});

it('clears a challenge once it has been answered', function (): void {
    $candidate = candidateKey();
    $key = $this->service->submit($this->organisation, $candidate['public'], null, $this->actor);

    $this->service->prove($key, answerChallenge($key, $candidate['secret']), $this->actor);

    $proved = $key->fresh();

    expect($proved->challenge)->toBeNull()
        ->and($proved->challenge_response_hash)->toBeNull()
        ->and($proved->challenge_expires_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------------------------------
| Re-proving
|--------------------------------------------------------------------------------------------------
*/

it('asks for a key to be demonstrated again after long enough', function (): void {
    $fresh = RecoveryKey::factory()->for($this->organisation)->create();
    $stale = RecoveryKey::factory()->for($this->organisation)->dueReproof()->create();

    expect($fresh->isDueReproof())->toBeFalse()
        ->and($stale->isDueReproof())->toBeTrue()

        // But nothing is disabled. A key that stopped working because nobody signed in for six months
        // would be a worse failure than the one being guarded against.
        ->and($this->service->activeFor($this->organisation))->toHaveCount(2);
});

it('records a re-proof distinctly from a first activation', function (): void {
    $key = RecoveryKey::factory()->for($this->organisation)->create();
    $secret = RecoveryKeyFactory::secretFor($key->fingerprint);

    $this->service->issueChallenge($key);
    $this->service->prove($key->fresh(), answerChallenge($key->fresh(), $secret), $this->actor);

    expect(AuditEvent::query()->where('action', 'backup.recovery_key.reproved')->exists())->toBeTrue()
        ->and($key->fresh()->last_proved_at?->isToday())->toBeTrue();
});
