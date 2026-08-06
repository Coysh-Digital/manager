<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Signing a device out has to actually sign it out.
 *
 * Deleting the `sessions` row was all this did, and that is not what "Sign out" means to the person
 * pressing it. "Stay signed in on this device" issues a recaller cookie which is checked against
 * `users.remember_token` and has nothing to do with the session table - so the device came straight
 * back on its next request, skipping the second factor on the way, while the screen reported that it
 * had been signed out.
 *
 * The assertions below go through Laravel's own user provider rather than through a cookie, because
 * `retrieveByToken()` is the exact call `SessionGuard::userFromRecaller()` makes. If that returns a
 * user, the device is still signed in, whatever the sessions table says.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();

    $this->confirmed = ['auth.password_confirmed_at' => now()->timestamp];

    $this->provider = Auth::createUserProvider(config('auth.guards.web.provider'));

    $this->givenARememberedDevice = function (): string {
        $token = 'a-token-a-remembered-device-is-holding';

        $this->owner->forceFill(['remember_token' => $token])->save();

        DB::table('sessions')->insert([[
            'id' => 'the-other-device',
            'user_id' => $this->owner->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Some other laptop',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]]);

        return $token;
    };
});

it('stops the remembered device signing itself back in', function (): void {
    $token = ($this->givenARememberedDevice)();

    // The state the bug produced: the row is gone and the device is still authenticated.
    expect($this->provider->retrieveByToken($this->owner->id, $token))->not->toBeNull();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('account.sessions.revoke', 'the-other-device'))
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'the-other-device')->exists())->toBeFalse()
        // The half that was missing. This is the call the guard makes when a recaller cookie
        // arrives, and it must now find nobody.
        ->and($this->provider->retrieveByToken($this->owner->id, $token))->toBeNull()
        ->and($this->owner->fresh()->remember_token)->not->toBe($token)
        ->and($this->owner->fresh()->remember_token)->not->toBeEmpty();
});

it('records the revocation on the audit trail', function (): void {
    ($this->givenARememberedDevice)();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('account.sessions.revoke', 'the-other-device'))
        ->assertRedirect();

    expect(AuditEvent::query()->where('action', 'user.session.revoked')->exists())->toBeTrue();
});

it('says that staying signed in ends everywhere, because it does', function (): void {
    // One token per account, not one per device. The message has to describe what happened rather
    // than what was clicked - a security control that quietly does more than its label says is as
    // much of a problem as one that quietly does less.
    ($this->givenARememberedDevice)();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('account.sessions.revoke', 'the-other-device'))
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'stay signed in'));
});

it('leaves another account alone', function (): void {
    $stranger = User::factory()->create(['remember_token' => 'not-yours-to-rotate']);

    DB::table('sessions')->insert([[
        'id' => 'a-stranger-session',
        'user_id' => $stranger->id,
        'ip_address' => '10.0.0.8',
        'user_agent' => 'x',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]]);

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('account.sessions.revoke', 'a-stranger-session'))
        ->assertRedirect();

    // Scoped by user_id, so an identifier from elsewhere reaches nothing - and rotating on a
    // no-op would let anybody sign every one of their own devices out by guessing.
    expect(DB::table('sessions')->where('id', 'a-stranger-session')->exists())->toBeTrue()
        ->and($stranger->fresh()->remember_token)->toBe('not-yours-to-rotate');
});

it('does not rotate anything when the session had already gone', function (): void {
    $token = ($this->givenARememberedDevice)();

    DB::table('sessions')->where('id', 'the-other-device')->delete();

    $this->actingAs($this->owner)
        ->withSession($this->confirmed)
        ->delete(route('account.sessions.revoke', 'the-other-device'))
        ->assertRedirect();

    expect($this->owner->fresh()->remember_token)->toBe($token);
});
