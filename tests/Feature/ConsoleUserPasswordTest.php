<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Console password recovery.
 *
 * This command exists because a self-hosted installation could otherwise lock its owner out for good:
 * the setup route closes once an account exists, and the reset flow needs working mail, which a fresh
 * install may not have.
 *
 * The property worth defending is the one it would be easiest to get wrong. Resetting a password must
 * not remove the second factor, because a command that did both would be a one-step way to strip
 * multi-factor authentication from any account on the installation.
 */
beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create();
    $this->user = User::factory()->create(['email' => 'owner@example.org']);
    Membership::factory()->for($this->user)->for($this->organisation)->owner()->create();
});

it('sets a password the account can log in with', function (): void {
    $this->artisan('manager:user:password owner@example.org --generate')
        ->assertSuccessful();

    $this->user->refresh();

    // Hashed, and not the value that was there before.
    expect(Hash::check('whatever-it-was', $this->user->password))->toBeFalse()
        ->and($this->user->password)->toStartWith('$');
});

it('accepts a typed password when both entries agree', function (): void {
    $this->artisan('manager:user:password owner@example.org')
        ->expectsQuestion('New password (not echoed)', 'a-perfectly-fine-password')
        ->expectsQuestion('Confirm it', 'a-perfectly-fine-password')
        ->assertSuccessful();

    expect(Hash::check('a-perfectly-fine-password', $this->user->fresh()->password))->toBeTrue();
});

it('changes nothing when the two entries disagree', function (): void {
    $before = $this->user->password;

    $this->artisan('manager:user:password owner@example.org')
        ->expectsQuestion('New password (not echoed)', 'a-perfectly-fine-password')
        ->expectsQuestion('Confirm it', 'a-different-password-entirely')
        ->assertFailed();

    expect($this->user->fresh()->password)->toBe($before);
});

it('refuses a short password', function (): void {
    $before = $this->user->password;

    $this->artisan('manager:user:password owner@example.org')
        ->expectsQuestion('New password (not echoed)', 'short')
        ->assertFailed();

    expect($this->user->fresh()->password)->toBe($before);
});

it('leaves the second factor alone', function (): void {
    $this->user->forceFill([
        'totp_secret' => 'JBSWY3DPEHPK3PXP',
        'totp_confirmed_at' => now(),
    ])->save();

    $this->artisan('manager:user:password owner@example.org --generate')->assertSuccessful();

    $this->user->refresh();

    // The assertion this file exists for. A password reset that also cleared TOTP would be a way to
    // remove multi-factor authentication from any account, in one command, without saying so.
    expect($this->user->hasConfirmedTotp())->toBeTrue()
        ->and($this->user->totp_secret)->toBe('JBSWY3DPEHPK3PXP');
});

it('removes the second factor only when asked, and says so', function (): void {
    $this->user->forceFill([
        'totp_secret' => 'JBSWY3DPEHPK3PXP',
        'totp_confirmed_at' => now(),
    ])->save();

    $this->artisan('manager:user:password owner@example.org --generate --reset-second-factor')
        ->expectsOutputToContain('second factor was removed')
        ->assertSuccessful();

    $this->user->refresh();

    expect($this->user->hasConfirmedTotp())->toBeFalse()
        ->and($this->user->totp_secret)->toBeNull();
});

it('records the change without recording the password', function (): void {
    $this->artisan('manager:user:password owner@example.org --generate')->assertSuccessful();

    $event = AuditEvent::query()->where('action', 'user.password_set_from_console')->sole();

    expect($event->actor_label)->toBe('Console')
        ->and($event->after['second_factor_cleared'])->toBeFalse()
        // Neither the password nor the hash. The hash is a credential-equivalent for offline attack,
        // and the audit log is read by more people than the users table.
        ->and($event->toJson())->not->toContain($this->user->fresh()->password);
});

it('distinguishes clearing a second factor from there never having been one', function (): void {
    // No TOTP enrolled, and the flag passed anyway. Nothing to clear, so the log should not claim a
    // second factor was removed — otherwise the record implies a downgrade that never happened.
    $this->artisan('manager:user:password owner@example.org --generate --reset-second-factor')
        ->assertSuccessful();

    $event = AuditEvent::query()->where('action', 'user.password_set_from_console')->sole();

    expect($event->after['second_factor_cleared'])->toBeFalse()
        ->and($event->after['had_second_factor'])->toBeFalse();
});

it('fails helpfully on an address that does not exist', function (): void {
    $this->artisan('manager:user:password nobody@example.org --generate')
        ->expectsOutputToContain('No account with the address')
        // Lists what does exist. This is a console command for a locked-out operator, not a login form
        // — being coy would only obstruct the person it is for.
        ->expectsOutputToContain('owner@example.org')
        ->assertFailed();
});
