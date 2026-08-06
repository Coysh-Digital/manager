<?php

declare(strict_types=1);

use App\Domain\Auth\TotpService;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/*
 | Two ways in that were bounded in theory and not in practice.
 |
 | **A TOTP code was reusable.** It is valid for its own 30-second step and one either side, so the
 | same six digits worked for roughly ninety seconds. Nothing recorded that a code had been spent —
 | so a code shoulder-surfed, read off a lock-screen notification, or captured in front of the login
 | form could simply be typed again. Recovery codes were already single-use; the other half of the
 | second factor was not.
 |
 | **Login throttling bounded neither axis on its own.** The limiter was keyed on address *and*
 | source together, which is tighter than either alone for the ordinary case and leaves both of the
 | ordinary attacks unbounded: spraying gets a fresh bucket per address, stuffing a fresh bucket per
 | source.
 */

beforeEach(function (): void {
    RateLimiter::clear('login-source:127.0.0.1');

    $this->organisation = Organisation::factory()->create();

    /**
     * An enrolled account, and the secret in the clear.
     *
     * Returned as a pair rather than stashed on the model: totp_secret is an encrypted cast, and an
     * extra attribute on an Eloquent model is one the next save() tries to write to a column that
     * does not exist.
     *
     * @return array{0: User, 1: string}
     */
    $this->userWithTotp = function (): array {
        $secret = app(Google2FA::class)->generateSecretKey(32);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('correct-horse-battery-staple'),
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ]);

        Membership::factory()->for($user)->for($this->organisation)->owner()->create();

        return [$user, $secret];
    };
});

/*
|--------------------------------------------------------------------------------------------------
| A code is spent once
|--------------------------------------------------------------------------------------------------
*/

it('refuses a TOTP code that has already been accepted', function (): void {
    [$user, $secret] = ($this->userWithTotp)();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    expect(app(TotpService::class)->verifyOnce($user, $code))->toBeTrue()
        // Same code, same account, well inside the ninety seconds it stays arithmetically valid.
        ->and(app(TotpService::class)->verifyOnce($user->fresh(), $code))->toBeFalse();
});

it('records the step so the refusal survives a new request', function (): void {
    // The state has to be on the row, not in the instance. A second request builds a fresh model.
    [$user, $secret] = ($this->userWithTotp)();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    app(TotpService::class)->verifyOnce($user, $code);

    expect($user->fresh()->totp_last_used_step)->not->toBeNull();
});

it('still accepts a code from a different account', function (): void {
    // The marker is per account. Two people enrolling at the same moment must not lock each other
    // out of the same step.
    [$first, $firstSecret] = ($this->userWithTotp)();
    [$second, $secondSecret] = ($this->userWithTotp)();

    $firstCode = app(Google2FA::class)->getCurrentOtp($firstSecret);
    $secondCode = app(Google2FA::class)->getCurrentOtp($secondSecret);

    expect(app(TotpService::class)->verifyOnce($first, $firstCode))->toBeTrue()
        ->and(app(TotpService::class)->verifyOnce($second, $secondCode))->toBeTrue();
});

it('refuses a replayed code at the login challenge, not only in the service', function (): void {
    [$user, $secret] = ($this->userWithTotp)();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $challenge = fn () => $this->withSession(['auth.challenge' => [
        'user_id' => $user->id,
        'remember' => false,
        'at' => now()->timestamp,
    ]])->post(route('two-factor.store'), ['code' => $code]);

    $challenge()->assertRedirect();

    // Signed out again, same code. This is the shoulder-surfing case end to end.
    auth()->logout();

    $challenge()->assertSessionHasErrors('code');
});

/*
|--------------------------------------------------------------------------------------------------
| Throttling bounds each axis
|--------------------------------------------------------------------------------------------------
*/

it('throttles password spraying across many addresses from one source', function (): void {
    // Every address is a fresh composite bucket, so before the source bucket existed this was
    // unbounded: one machine could work through a list of addresses at full speed for ever.
    //
    // The attempt limit is lowered so this needs ten requests rather than fifty. The property is the
    // same and the suite is not asked to pay twenty seconds for it.
    config(['manager.auth.max_login_attempts' => 1]);
    $max = 1;

    for ($i = 0; $i < $max * 10; $i++) {
        $this->post(route('login.store'), [
            'email' => "nobody{$i}@example.org",
            'password' => 'not-the-password',
        ]);
    }

    // A fresh address from the same source is now refused on the source bucket alone.
    $this->post(route('login.store'), ['email' => 'someone-else@example.org', 'password' => 'x'])
        ->assertSessionHasErrors('email');

    expect(RateLimiter::tooManyAttempts('login-source:127.0.0.1', $max * 10))->toBeTrue();
});

it('bounds attempts against one account however many sources they come from', function (): void {
    // The stuffing case. Each source is a fresh composite bucket, so only an account-wide bucket
    // bounds it.
    [$user] = ($this->userWithTotp)();

    // Lowered for the same reason as above: four requests rather than twenty.
    config(['manager.auth.max_login_attempts' => 1]);
    $max = 1;

    // A different source each time, which is the whole point: from one source the composite bucket
    // stops the run at $max, so the account bucket is only ever reached by spreading the attempt out.
    for ($i = 0; $i < $max * 4; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.intdiv($i, 250).'.'.($i % 250 + 1)])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }

    expect(RateLimiter::tooManyAttempts('login-account:'.$user->email, $max * 4))->toBeTrue();

    // And the account is now refused even from a source that has never been seen.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
});

it('does not clear the source bucket when somebody signs in successfully', function (): void {
    /*
     | Somebody spraying will eventually guess a password that works, and clearing the source bucket
     | on that success would hand them a reset of the limit that exists to stop them.
     |
     | The account and composite buckets *are* cleared, because a person who has just proved who they
     | are should not be carrying their own earlier typos.
    */
    [$user] = ($this->userWithTotp)();

    $this->post(route('login.store'), ['email' => 'nobody@example.org', 'password' => 'wrong']);

    expect(RateLimiter::attempts('login-source:127.0.0.1'))->toBe(1);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    expect(RateLimiter::attempts('login-source:127.0.0.1'))->toBe(1)
        ->and(RateLimiter::attempts('login-account:'.$user->email))->toBe(0);
});
