<?php

declare(strict_types=1);

use App\Domain\Auth\RecoveryCodeService;
use App\Domain\Auth\TotpService;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/**
 * Platform account security.
 *
 * The account is the other way into this system: everything the connector protocol prevents is
 * moot if a stolen password is enough to read the whole fleet.
 */
beforeEach(function (): void {
    RateLimiter::clear('login:owner@example.org|127.0.0.1');

    $this->organisation = Organisation::factory()->create();
    $this->password = 'correct-horse-battery-staple-42';
    $this->user = User::factory()->create([
        'email' => 'owner@example.org',
        'password' => $this->password,
        'email_verified_at' => now(),
    ]);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();
});

it('signs in with a correct password when there is no second factor', function (): void {
    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password])
        ->assertRedirect(route('sites.index'));

    expect(auth()->id())->toBe($this->user->id);
});

it('refuses a wrong password and says nothing about which part was wrong', function (): void {
    $this->post('/login', ['email' => 'owner@example.org', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $unknown = $this->post('/login', ['email' => 'nobody@example.org', 'password' => 'wrong']);

    // The same field and the same message either way, so this is not a way to find out who has an
    // account here.
    $unknown->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe('Those credentials do not match our records.');
    expect(auth()->check())->toBeFalse();
});

it('records a failed sign-in without recording the password', function (): void {
    $this->post('/login', ['email' => 'owner@example.org', 'password' => 'hunter2']);

    $event = AuditEvent::query()->where('action', 'auth.login.failed')->firstOrFail();

    expect($event->actor_label)->toBe('owner@example.org')
        ->and($event->toJson())->not->toContain('hunter2');
});

it('throttles repeated failures', function (): void {
    $max = (int) config('manager.auth.max_login_attempts');

    foreach (range(1, $max) as $ignored) {
        $this->post('/login', ['email' => 'owner@example.org', 'password' => 'wrong']);
    }

    unset($ignored);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password])
        ->assertSessionHasErrors('email');

    // Even the correct password is refused while throttled, or the limit would be trivially
    // sidestepped by guessing until the right one happened to land.
    expect(auth()->check())->toBeFalse();
});

it('does not sign anyone in on the password alone when a second factor is enrolled', function (): void {
    enrolSecondFactorFor($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password])
        ->assertRedirect(route('two-factor.challenge'));

    // This is the point: no authenticated session exists yet. Logging in first and "requiring" the
    // challenge afterwards would leave a window in which one factor was enough.
    expect(auth()->check())->toBeFalse();
});

it('completes sign-in with a valid one-time code', function (): void {
    $secret = enrolSecondFactorFor($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    $this->post('/two-factor', ['code' => currentTotpCode($secret)])
        ->assertRedirect(route('sites.index'));

    expect(auth()->id())->toBe($this->user->id);
});

it('refuses an invalid one-time code', function (): void {
    enrolSecondFactorFor($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);
    $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('accepts a recovery code once and only once', function (): void {
    enrolSecondFactorFor($this->user);
    $codes = app(RecoveryCodeService::class)->regenerate($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);
    $this->post('/two-factor', ['code' => $codes[0]])->assertRedirect(route('sites.index'));

    expect(auth()->id())->toBe($this->user->id);

    // Second use of the same code fails.
    auth()->logout();
    session()->flush();

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);
    $this->post('/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('stores recovery codes hashed', function (): void {
    $codes = app(RecoveryCodeService::class)->regenerate($this->user);

    $stored = DB::table('recovery_codes')->where('user_id', $this->user->id)->pluck('code_hash');

    // A database copy must not yield working second factors.
    foreach ($stored as $hash) {
        expect($hash)->not->toBeIn($codes);
    }

    expect(Hash::check($codes[0], $stored[0]))->toBeTrue();
});

it('regenerating recovery codes invalidates the previous set', function (): void {
    $first = app(RecoveryCodeService::class)->regenerate($this->user);
    app(RecoveryCodeService::class)->regenerate($this->user);

    expect(app(RecoveryCodeService::class)->consume($this->user, $first[0]))->toBeFalse();
});

it('expires an abandoned second-factor challenge', function (): void {
    enrolSecondFactorFor($this->user);

    $this->post('/login', ['email' => 'owner@example.org', 'password' => $this->password]);

    // A half-finished login left in a browser must not be resumable much later.
    $this->travel(6)->minutes();

    $this->get('/two-factor')->assertRedirect(route('login'));
});

it('deletes recovery codes when the second factor is turned off', function (): void {
    enrolSecondFactorFor($this->user);
    app(RecoveryCodeService::class)->regenerate($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete('/account/two-factor')
        ->assertRedirect();

    // With no second factor, recovery codes are just a second password.
    expect($this->user->fresh()->hasConfirmedTotp())->toBeFalse()
        ->and($this->user->fresh()->recoveryCodes()->count())->toBe(0);
});

it('will not turn off a second factor the organisation requires', function (): void {
    $this->organisation->forceFill(['mfa_required' => true])->save();
    enrolSecondFactorFor($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete('/account/two-factor');

    expect($this->user->fresh()->hasConfirmedTotp())->toBeTrue();
});

it('will not confirm a second factor with the wrong code', function (): void {
    $secret = app(TotpService::class)->generateSecret();

    $this->actingAs($this->user)
        ->withSession([
            'auth.password_confirmed_at' => now()->timestamp,
            'totp.pending' => $secret,
        ])
        ->post('/account/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    // Nothing is written until a valid code proves the authenticator really has the secret.
    expect($this->user->fresh()->totp_secret)->toBeNull()
        ->and($this->user->fresh()->hasConfirmedTotp())->toBeFalse();
});

it('lets a user end another session but not forge one', function (): void {
    DB::table('sessions')->insert([
        ['id' => 'mine', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'theirs', 'user_id' => User::factory()->create()->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $acting = $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $acting->delete('/account/sessions/mine')->assertRedirect();
    $acting->delete('/account/sessions/theirs')->assertRedirect();

    // Scoped to the current user, so an identifier from elsewhere signs out nobody.
    expect(DB::table('sessions')->where('id', 'mine')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();
});

it('ends every other session when a password is reset', function (): void {
    DB::table('sessions')->insert([
        'id' => 'stale', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    $token = app('auth.password.broker')->createToken($this->user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->user->email,
        'password' => 'a-completely-different-passphrase-99',
        'password_confirmation' => 'a-completely-different-passphrase-99',
    ])->assertRedirect(route('login'));

    // If the reset happened because the account was compromised, leaving the attacker's session
    // alive would undo the point of resetting.
    expect(DB::table('sessions')->where('id', 'stale')->exists())->toBeFalse();
});

it('says the same thing whether or not a reset address exists', function (): void {
    $known = $this->post('/forgot-password', ['email' => 'owner@example.org']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.org']);

    expect($known->getStatusCode())->toBe($unknown->getStatusCode());

    $this->followingRedirects();

    expect(session('status'))->toBe('If that address has an account, a reset link is on its way.');
});

/**
 * Give a user a confirmed second factor and return the secret.
 */
function enrolSecondFactorFor(User $user): string
{
    $secret = app(TotpService::class)->generateSecret();

    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

    return $secret;
}

/**
 * The code an authenticator app would be showing right now.
 */
function currentTotpCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}
