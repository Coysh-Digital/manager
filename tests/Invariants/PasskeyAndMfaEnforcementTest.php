<?php

declare(strict_types=1);

use App\Domain\Auth\TotpService;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Laragear\WebAuthn\Models\WebAuthnCredential;

/**
 * Passkeys as a second factor, and organisation-level MFA enforcement.
 *
 * The design decision under test throughout: a passkey satisfies the *second* factor, it does not
 * replace the password. A single passkey on an already-signed-in laptop would be one factor, and this
 * system can read every installation it manages.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->password = 'correct-horse-battery-staple-42';
    $this->user = User::factory()->create([
        'email' => 'owner@example.org',
        'password' => $this->password,
        'email_verified_at' => now(),
    ]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();
});

/**
 * Attach a passkey to a user without going through a WebAuthn ceremony.
 */
function givePasskey(User $user, string $alias = 'Work laptop'): WebAuthnCredential
{
    return WebAuthnCredential::forceCreate([
        'id' => bin2hex(random_bytes(16)),
        'authenticatable_type' => $user->getMorphClass(),
        'authenticatable_id' => $user->getKey(),
        'user_id' => (string) Str::uuid(),
        'alias' => $alias,
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_format' => 'none',
        'public_key' => 'test-key',
    ]);
}

it('counts a passkey as a second factor', function (): void {
    expect($this->user->hasSecondFactor())->toBeFalse();

    givePasskey($this->user);

    // A passkey is phishing-resistant and bound to this origin, so requiring TOTP specifically would
    // be insisting on the weaker of the two.
    expect($this->user->fresh()->hasSecondFactor())->toBeTrue()
        ->and($this->user->fresh()->hasConfirmedTotp())->toBeFalse();
});

it('sends a passkey holder to the challenge rather than signing them in', function (): void {
    givePasskey($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password])
        ->assertRedirect(route('two-factor.challenge'));

    // The whole point: the password alone produces no session, whichever second factor is enrolled.
    expect(auth()->check())->toBeFalse();
});

it('opens no challenge at all for an account with no second factor', function (): void {
    // Straight in, and there is no challenge to reach afterwards.
    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password])
        ->assertRedirect(route('sites.index'));

    expect(auth()->id())->toBe($this->user->id);
});

it('offers the passkey button only to somebody who has one', function (): void {
    givePasskey($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    // A button that can only fail is worse than no button.
    $this->get('/two-factor')->assertOk()->assertSee('Use a passkey');
});

it('does not offer the passkey button to somebody with only an authenticator app', function (): void {
    $this->user->forceFill([
        'totp_secret' => app(TotpService::class)->generateSecret(),
        'totp_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    $this->get('/two-factor')->assertOk()->assertDontSee('Use a passkey');
});

it('still offers a code to a passkey holder', function (): void {
    givePasskey($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    // A recovery code has to work when the passkey is on a phone that is not to hand.
    $this->get('/two-factor')->assertOk()->assertSee('name="code"', false);
});

it('refuses a passkey ceremony with no pending challenge', function (): void {
    givePasskey($this->user);

    // Nobody has proved a password, so there is nothing to second-factor.
    $this->postJson(route('two-factor.passkey.options'))->assertStatus(409);
    $this->postJson(route('two-factor.passkey.store'), [
        'id' => 'x',
        'rawId' => 'x',
        'type' => 'public-key',
        'response' => ['authenticatorData' => 'x', 'clientDataJSON' => 'x', 'signature' => 'x'],
    ])->assertStatus(409);

    expect(auth()->check())->toBeFalse();
});

it('scopes the passkey ceremony to the pending user', function (): void {
    givePasskey($this->user, 'Mine');

    $other = User::factory()->create(['email' => 'other@example.org', 'password' => $this->password]);
    givePasskey($other, 'Theirs');

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    $options = $this->postJson(route('two-factor.passkey.options'))->assertOk()->json();

    // Only this user's credentials are offered. An unscoped ceremony would let the browser present a
    // credential belonging to a different account.
    $allowed = collect($options['allowCredentials'] ?? [])->pluck('id');

    expect($allowed)->toHaveCount(1);
});

it('requires recent authentication to register a passkey', function (): void {
    // 423 rather than a redirect, because this is a JSON request: Laravel's password-confirmation
    // middleware answers an API caller with a status it can act on instead of a login page.
    $this->actingAs($this->user)
        ->postJson(route('passkeys.options'))
        ->assertStatus(423);

    // And with a recent confirmation it proceeds.
    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson(route('passkeys.options'))
        ->assertOk()
        ->assertJsonStructure(['challenge', 'rp', 'user']);
});

it('lists and removes a passkey', function (): void {
    $credential = givePasskey($this->user, 'Work laptop');

    $this->actingAs($this->user)->get('/account')->assertOk()->assertSee('Work laptop');

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('passkeys.destroy', $credential->id))
        ->assertRedirect();

    expect($this->user->fresh()->enabledPasskeyCount())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'user.passkey.removed')->exists())->toBeTrue();
});

it('will not remove a passkey belonging to somebody else', function (): void {
    $other = User::factory()->create();
    $theirs = givePasskey($other, 'Theirs');

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('passkeys.destroy', $theirs->id))
        ->assertRedirect();

    // Scoped to the caller's own credentials, so an identifier from elsewhere removes nothing.
    expect($other->fresh()->enabledPasskeyCount())->toBe(1);
});

it('refuses to remove the last second factor while the organisation requires one', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();
    $credential = givePasskey($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('passkeys.destroy', $credential->id))
        ->assertRedirect();

    // Leaving an account unable to satisfy a requirement it is subject to would lock the person out
    // on their next sign-in, which is worse than refusing here.
    expect($this->user->fresh()->enabledPasskeyCount())->toBe(1)
        ->and(session('warning'))->toContain('requires two-factor authentication');
});

it('allows removing one passkey when another second factor remains', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();

    $first = givePasskey($this->user, 'Laptop');
    givePasskey($this->user, 'Phone');

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('passkeys.destroy', $first->id))
        ->assertRedirect();

    expect($this->user->fresh()->enabledPasskeyCount())->toBe(1);
});

// --------------------------------------------------------------------------------------------------
// Organisation MFA enforcement
// --------------------------------------------------------------------------------------------------

it('redirects an unenrolled member to enrol rather than locking them out', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();
    Site::factory()->for($this->organisation)->create();

    $this->actingAs($this->user)
        ->get('/sites')
        ->assertRedirect(route('account.show'));

    // Locking somebody out of a control plane to improve its security is a trade made once and
    // regretted at 2am — and turning the requirement on could otherwise strand everybody at once,
    // including whoever turned it on.
    expect(session('warning'))->toContain('requires two-factor authentication');
});

it('leaves the enrolment path itself reachable', function (string $route): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();

    $this->actingAs($this->user)->get(route($route))->assertOk();
})->with(['account.show']);

it('lets an unenrolled member sign out', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();

    // Without this the enforcement would trap somebody in a redirect with no way past it.
    $this->actingAs($this->user)->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('stops redirecting once a second factor is enrolled', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();
    Site::factory()->for($this->organisation)->create();

    $this->actingAs($this->user)->get('/sites')->assertRedirect(route('account.show'));

    givePasskey($this->user);

    $this->actingAs($this->user->fresh())->get('/sites')->assertOk();
});

it('accepts an authenticator app as satisfying the requirement too', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();
    Site::factory()->for($this->organisation)->create();

    $this->user->forceFill([
        'totp_secret' => app(TotpService::class)->generateSecret(),
        'totp_confirmed_at' => now(),
    ])->save();

    $this->actingAs($this->user->fresh())->get('/sites')->assertOk();
});

it('does not enforce anything when the organisation has not asked for it', function (): void {
    Site::factory()->for($this->organisation)->create();

    expect($this->organisation->mfa_required)->toBeFalse();

    $this->actingAs($this->user)->get('/sites')->assertOk();
});

it('records which factor closed a login', function (): void {
    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    $event = AuditEvent::query()->where('action', 'auth.login.succeeded')->firstOrFail();

    // "Signed in with a passkey" and "signed in with a recovery code" call for very different
    // responses when reviewing an incident.
    expect($event->after['factor'])->toBe('password');
});
