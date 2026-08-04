<?php

declare(strict_types=1);

use App\Domain\Backup\RecoveryKeyService;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\RecoveryProof;
use coyshdigital\managerprotocol\Sealing;
use Database\Factories\RecoveryKeyFactory;

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->admin)->for($this->organisation)->admin()->create();

    $this->recentAuth = ['auth.password_confirmed_at' => now()->timestamp];
});

it('tells an organisation with no key why it cannot back anything up', function (): void {
    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertSee('No backups can be taken yet')
        // The explanation rather than the bare state. Somebody seeing this for the first time should
        // learn that the refusal is deliberate, not that something is broken.
        ->assertSee('rather than send us a database', false);
});

it('shows the commands that make a key, and where to read more', function (): void {
    // "Generate the key with manager-restore keygen" assumed the reader knew how to get
    // manager-restore. Until it was published on Packagist that was not a small assumption, and it
    // is the only way to produce the thing every backup now requires.
    $html = $this->actingAs($this->owner)->get('/settings/recovery-keys')->assertOk()->getContent();

    expect($html)->toContain('composer global require coysh-digital/manager-restore')
        ->and($html)->toContain('manager-restore keygen')
        ->and($html)->toContain('https://managerforcraft.com/docs/recovery-keys')
        // The half that must never be pasted here, said where somebody is about to paste something.
        ->and($html)->toContain('recovery.secret');
});

it('sends every "add one in Settings" link to the screen that adds one', function (): void {
    /*
     | This used to assert an `id="recovery-keys"` anchor, because several screens linked to
     | settings#recovery-keys and for a long time there was no such id — so every one of them landed
     | at the top of a long page and left the reader to find it.
     |
     | Recovery keys are a screen now, so the fragment is gone and the anchor with it. The claim the
     | old test was making is still worth holding, so it is made against the links themselves: no
     | screen may go on pointing at a fragment that no longer exists.
     */
    $site = Site::factory()->for($this->organisation)->connected()->create();

    foreach (['/backups', route('sites.backups', $site)] as $url) {
        $html = (string) $this->actingAs($this->owner)->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('#recovery-keys');
    }

    $this->actingAs($this->owner)
        ->get(route('settings.recovery-keys'))
        ->assertOk()
        ->assertSee('Recovery keys');
});

it('warns about a single point of failure while there is one key', function (): void {
    RecoveryKey::factory()->for($this->organisation)->create();

    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertSee('One recovery key')
        ->assertSee('permanently unreadable')
        // Said plainly, in the place somebody would look for reassurance. Softening it here is how a
        // customer ends up believing there is a support process that does not exist.
        ->assertSee('We cannot recover it', false);
});

it('does not warn once there are two', function (): void {
    RecoveryKey::factory()->count(2)->for($this->organisation)->create();

    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertDontSee('One recovery key');
});

it('never offers to generate a key', function (): void {
    $html = (string) $this->actingAs($this->owner)->get('/settings/recovery-keys')->assertOk()->getContent();

    // A private key produced on this server would exist in a response body. The screen must send
    // people to the offline tool instead, and there must be nothing here that looks like a shortcut.
    expect($html)->toContain('manager-restore keygen')
        ->and($html)->not->toContain('Generate a key')
        ->and($html)->not->toContain('Generate key');
});

it('tells the operator to pin the fingerprint on their own server', function (): void {
    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        // The control that matters most, and the one that is easiest to skip. It belongs beside the
        // field rather than in a document.
        ->assertSee('config/manager-connector.php')
        ->assertSee('stops us handing your sites a key of', false);
});

it('shows a revoked key rather than hiding it', function (): void {
    $revoked = RecoveryKey::factory()->for($this->organisation)->revoked('Laptop replaced')->create();

    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertSee($revoked->fingerprint)
        // Because a fingerprint in an old artifact's manifest has to stay explicable, and because
        // "which keys used to open our backups" is a question this screen should answer.
        ->assertSee('still open with it', false);
});

/*
|--------------------------------------------------------------------------------------------------
| Who may do this
|--------------------------------------------------------------------------------------------------
*/

it('lets only an owner register a key', function (): void {
    $key = Sealing::generateBoxKeypair()['public'];

    $this->actingAs($this->admin)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys', ['public_key' => $key])
        ->assertForbidden();

    expect(RecoveryKey::query()->count())->toBe(0);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys', ['public_key' => $key, 'label' => 'Ops laptop'])
        ->assertRedirect();

    expect(RecoveryKey::query()->count())->toBe(1);
});

it('needs recent authentication to register a key', function (): void {
    // Adding a recipient decides who can read every future backup. A session somebody walked away from
    // should not be able to do it.
    $this->actingAs($this->owner)
        ->post('/settings/recovery-keys', ['public_key' => Sealing::generateBoxKeypair()['public']])
        ->assertRedirect(route('password.confirm'));

    expect(RecoveryKey::query()->count())->toBe(0);
});

it('needs recent authentication to revoke one', function (): void {
    RecoveryKey::factory()->for($this->organisation)->create();
    $key = RecoveryKey::factory()->for($this->organisation)->create();

    $this->actingAs($this->owner)
        ->delete('/settings/recovery-keys/'.$key->external_id, ['reason' => 'Rotated'])
        ->assertRedirect(route('password.confirm'));

    expect($key->fresh()->isActive())->toBeTrue();
});

/*
|--------------------------------------------------------------------------------------------------
| Tenant isolation
|--------------------------------------------------------------------------------------------------
*/

it('will not let one organisation see another\'s keys', function (): void {
    $other = Organisation::factory()->create();
    $theirs = RecoveryKey::factory()->for($other)->create(['label' => 'Their laptop']);

    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertDontSee($theirs->fingerprint)
        ->assertDontSee('Their laptop');
});

it('will not let one organisation revoke another\'s key', function (): void {
    $other = Organisation::factory()->create();
    RecoveryKey::factory()->for($other)->create();
    $theirs = RecoveryKey::factory()->for($other)->create();

    // 404 rather than 403. A refusal that distinguishes "not yours" from "not here" tells the caller
    // that the identifier is real, which is an enumeration oracle.
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/settings/recovery-keys/'.$theirs->external_id, ['reason' => 'Not mine'])
        ->assertNotFound();

    expect($theirs->fresh()->isActive())->toBeTrue();
});

it('will not let one organisation answer another\'s challenge', function (): void {
    $other = Organisation::factory()->create();
    $theirs = RecoveryKey::factory()->for($other)->awaitingProof()->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys/'.$theirs->external_id.'/prove', ['proof' => 'MGRP-0000-0000-0000-0000-0000-0000'])
        ->assertNotFound();

    expect($theirs->fresh()->isAwaitingProof())->toBeTrue();
});

it('will not let one organisation reissue another\'s challenge', function (): void {
    $other = Organisation::factory()->create();
    $theirs = RecoveryKey::factory()->for($other)->awaitingProof()->create();
    $before = $theirs->challenge;

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys/'.$theirs->external_id.'/challenge')
        ->assertNotFound();

    expect($theirs->fresh()->challenge)->toBe($before);
});

/*
|--------------------------------------------------------------------------------------------------
| The ceremony, through the interface
|--------------------------------------------------------------------------------------------------
*/

it('walks an operator from a pasted key to an active one', function (): void {
    $keypair = Sealing::generateBoxKeypair();

    // What `manager-restore keygen` actually writes, pasted whole. Refusing it and asking for "just the
    // key part" would be refusing to read the file we told them to produce.
    $armoured = implode("\n", [
        '-----BEGIN MANAGER RECOVERY KEY-----',
        'Version: 1',
        'Label: Ops laptop',
        '',
        $keypair['public'],
        '-----END MANAGER RECOVERY KEY-----',
    ]);

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys', ['public_key' => $armoured, 'label' => 'Ops laptop'])
        ->assertRedirect(route('settings.recovery-keys'));

    $key = RecoveryKey::query()->firstOrFail();

    expect($key->public_key)->toBe($keypair['public'])
        ->and($key->isAwaitingProof())->toBeTrue();

    // The screen shows the command, with the real challenge in it.
    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertSee('manager-restore prove')
        ->assertSee((string) $key->challenge, false);

    $answer = RecoveryProof::responseFor(
        Sealing::unseal((string) $key->challenge, $key->public_key, $keypair['secret']),
    );

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys/'.$key->external_id.'/prove', ['proof' => $answer])
        ->assertRedirect(route('settings.recovery-keys'));

    expect($key->fresh()->isActive())->toBeTrue()
        ->and($this->organisation->fresh()->backup_format_floor)->toBe('v2');
});

it('reports a rejected key against the field it was typed into', function (): void {
    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->post('/settings/recovery-keys', ['public_key' => 'not a key'])
        ->assertRedirect()
        ->assertSessionHasErrors('public_key');

    expect(RecoveryKey::query()->count())->toBe(0);
});

it('demands an explicit confirmation before stopping every backup', function (): void {
    $only = RecoveryKey::factory()->for($this->organisation)->create();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/settings/recovery-keys/'.$only->external_id, ['reason' => 'Tidying up'])
        ->assertSessionHasErrors('accept_last_key');

    expect($only->fresh()->isActive())->toBeTrue();

    $this->actingAs($this->owner)->withSession($this->recentAuth)
        ->delete('/settings/recovery-keys/'.$only->external_id, [
            'reason' => 'Winding down',
            'accept_last_key' => 'STOP BACKUPS',
        ])
        ->assertRedirect(route('settings.recovery-keys'));

    expect($only->fresh()->isRevoked())->toBeTrue();
});

/*
|--------------------------------------------------------------------------------------------------
| What the screen must not contain
|--------------------------------------------------------------------------------------------------
*/

it('never renders anything that could open a backup', function (): void {
    $key = RecoveryKey::factory()->for($this->organisation)->create();
    $secret = RecoveryKeyFactory::secretFor($key->fingerprint);

    app(RecoveryKeyService::class)->issueChallenge($key);

    $html = (string) $this->actingAs($this->owner)->get('/settings/recovery-keys')->assertOk()->getContent();

    // The challenge itself is on the screen, and has to be — it is what the operator pastes into the
    // tool. Everything else must not be: the secret half was never ours, and the expected answer would
    // let anybody complete the ceremony without holding a key at all.
    expect($html)->not->toContain($secret)
        ->and($html)->not->toContain((string) $key->fresh()->challenge_response_hash);
});

it('is honest that revoking does not close old backups', function (): void {
    RecoveryKey::factory()->for($this->organisation)->create();
    RecoveryKey::factory()->for($this->organisation)->revoked()->create();

    // A screen that said "revoked" and nothing else would leave somebody believing a revocation had
    // made historical artifacts unreadable. It has not, and it cannot.
    $this->actingAs($this->owner)->get('/settings/recovery-keys')
        ->assertOk()
        ->assertSee('backups taken before then still open with it', false);
});
