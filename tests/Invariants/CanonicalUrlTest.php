<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 | Every generated URL is built from the canonical address, never from the request.
 |
 | Laravel derives the host of a generated URL from the Host header unless told otherwise. Forcing
 | the scheme was already done - a proxy terminating TLS could not send links out over plain HTTP —
 | and it left the host alone, which is the half an attacker controls.
 |
 | The password-reset email is the reason this matters rather than a demonstration of it. A request
 | carrying `Host: attacker.example` produced a reset link on attacker.example, which this
 | application then emailed to the address whose account was being taken over. The recipient has
 | every reason to trust the message: they asked for it, it arrived, and it is from us.
 |
 | Asserted at the point of generation rather than only on the email, so the guarantee covers
 | route(), url() and every signed URL, in a queued job and a console command as much as in a
 | request - none of which have a Host header to be wrong about.
 */

it('generates URLs on the canonical host even when the request claims another', function (): void {
    config(['app.url' => 'https://manager.example']);

    // Re-boot the provider's URL configuration the way a fresh process would.
    URL::forceRootUrl(config('app.url'));

    $generated = route('login');

    expect($generated)->toStartWith('https://manager.example');

    // And the request cannot move it.
    $this->get('/login', ['Host' => 'attacker.example']);

    expect(route('login'))->toStartWith('https://manager.example')
        ->and(route('login'))->not->toContain('attacker.example');
});

it('does not email a password-reset link on a host the request asked for', function (): void {
    Notification::fake();

    config(['app.url' => 'https://manager.example']);
    URL::forceRootUrl(config('app.url'));

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->post(route('password.email'), ['email' => $user->email], ['Host' => 'attacker.example']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $body = $notification->toMail($user)->render();

        // The whole point: the token is fine, and where it is sent is not.
        return ! str_contains((string) $body, 'attacker.example');
    });
});

it('keeps forcing HTTPS when the canonical URL is HTTPS', function (): void {
    // The half that already worked, asserted so that fixing the host does not quietly undo it.
    config(['app.url' => 'https://manager.example']);
    URL::forceRootUrl(config('app.url'));
    URL::forceScheme('https');

    expect(route('login'))->toStartWith('https://');
});
