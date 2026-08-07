<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PasswordReset;
use App\Providers\AppServiceProvider;
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
 | These re-run AppServiceProvider::boot() rather than calling URL::forceRootUrl() themselves, and
 | the distinction is not tidiness. The first version of this file restated what the provider does —
 | and restated it wrongly, calling forceRootUrl() without forceScheme(). It passed locally, where
 | .env carries an https APP_URL and the real boot had already forced the scheme, and failed in CI,
 | where .env.example carries http://localhost and nothing had. A test that reimplements the thing it
 | is testing asserts its own reimplementation.
 |
 | It is also why forceRootUrl alone is not the fix: UrlGenerator::formatRoot takes the *host* from
 | the forced root and the *scheme* from formatScheme(), which falls back to the request. Both calls
 | are needed, and the provider makes both.
 */

/**
 * Set the canonical URL and re-run the boot that reads it, as a fresh process would.
 *
 * The forced scheme and root are cleared first, and that is what makes this a fair test rather than
 * a test of whatever ran before it. Both are sticky for the life of the UrlGenerator: the real boot
 * has already run once against this installation's own .env, so without the reset an https local
 * environment would quietly satisfy the https cases and make the http one impossible. A real process
 * boots once; this is how a test gets the same starting position twice.
 */
function bootWithCanonicalUrl(string $url): void
{
    URL::forceScheme(null);
    URL::forceRootUrl(null);

    config(['app.url' => $url]);

    (new AppServiceProvider(app()))->boot();
}

it('generates URLs on the canonical host even when the request claims another', function (): void {
    bootWithCanonicalUrl('https://manager.example');

    expect(route('login'))->toStartWith('https://manager.example');

    // And the request cannot move it.
    $this->get('/login', ['Host' => 'attacker.example']);

    expect(route('login'))->toStartWith('https://manager.example')
        ->and(route('login'))->not->toContain('attacker.example');
});

it('does not email a password-reset link on a host the request asked for', function (): void {
    Notification::fake();

    bootWithCanonicalUrl('https://manager.example');

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->post(route('password.email'), ['email' => $user->email], ['Host' => 'attacker.example']);

    // App\Notifications\PasswordReset rather than the framework's, since User overrides
    // sendPasswordResetNotification. The property under test is unchanged: the broker still issues
    // the token, and the link still has to be built on the canonical host.
    Notification::assertSentTo($user, PasswordReset::class, function (PasswordReset $notification) use ($user): bool {
        $body = $notification->toMail($user)->render();

        // The whole point: the token is fine, and where it is sent is not.
        return ! str_contains((string) $body, 'attacker.example');
    });
});

it('keeps forcing HTTPS when the canonical URL is HTTPS', function (): void {
    // The half that already worked, asserted so that fixing the host does not quietly undo it.
    bootWithCanonicalUrl('https://manager.example');

    expect(route('login'))->toStartWith('https://');
});

it('forces the host on an installation served over HTTP too', function (): void {
    /*
     | The host is the half this change is about, and it is forced whatever the scheme.
     |
     | Only the host is asserted. The scheme on an http installation comes from the request, by
     | design - the provider forces https only when the canonical URL is https - and pinning that
     | here would mean asserting the framework's request-scheme resolution inside a test process
     | where the scheme has already been forced once by the real boot. That is the trap the header of
     | this file describes, and it is not worth re-entering to restate a behaviour the test above
     | already covers from the other direction.
    */
    bootWithCanonicalUrl('http://manager.example');

    expect(route('login'))->toContain('manager.example')
        ->and(route('login'))->not->toContain('localhost');
});
