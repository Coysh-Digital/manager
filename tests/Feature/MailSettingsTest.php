<?php

declare(strict_types=1);

use App\Domain\Health\Diagnostics;
use App\Domain\Mail\ConfiguredMailManager;
use App\Domain\Mail\MailConfiguration;
use App\Models\AuditEvent;
use App\Models\MailSetting;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
 | Mail configured from the interface, on an installation that holds its own relay.
 |
 | For a long time the only place mail could be configured was MAIL_* in .env, which meant a shell on
 | the server. The environment is still the fallback and still the floor; what changed is that an
 | owner can override it without one - and that the one thing you cannot use to tell somebody their
 | mail is broken is email.
 */

beforeEach(function (): void {
    $this->organisation = Organisation::factory()->create(['name' => 'Coysh Digital']);

    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($this->owner)->for($this->organisation)->owner()->create();
});

function storeMail(array $overrides = []): MailSetting
{
    return MailSetting::query()->create([
        'transport' => MailSetting::TRANSPORT_SMTP,
        'host' => 'smtp.relay.example',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'apikey',
        'password' => 'hunter2',
        'from_address' => 'manager@example.org',
        'from_name' => 'Manager',
        ...$overrides,
    ]);
}

/*
|--------------------------------------------------------------------------------------------------
| The override itself
|--------------------------------------------------------------------------------------------------
*/

it('wraps the framework own mail manager rather than being overwritten by it', function (): void {
    /*
     | The guard against the one failure in this design that has no symptom.
     |
     | MailServiceProvider is deferred, so binding singleton('mail.manager', ...) would be overwritten
     | the moment the container loaded it - mail would go on quietly using the environment and every
     | test that does not send mail would stay green. See MailConfigurationServiceProvider.
     */
    expect(app('mail.manager'))->toBeInstanceOf(ConfiguredMailManager::class);
});

it('sends through the stored transport rather than the environment', function (): void {
    config()->set('mail.default', 'log');
    config()->set('mail.mailers.smtp.host', 'from-the-environment.example');

    storeMail();

    // Resolving a mailer is what applies it - not booting, and not a scheduled task.
    app('mail.manager')->mailer();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.relay.example')
        ->and(config('mail.mailers.smtp.password'))->toBe('hunter2')
        ->and(config('mail.from.address'))->toBe('manager@example.org')
        // "TLS" in a mail client means STARTTLS, which Laravel 11 onward spells as the plain scheme.
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp');
});

it('leaves the environment alone when nothing is saved', function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.smtp.host', 'from-the-environment.example');

    app('mail.manager')->mailer();

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.mailers.smtp.host'))->toBe('from-the-environment.example');
});

it('does not leave a host standing behind an API transport', function (): void {
    // Every key toConfig() touches is written, nulls included. Otherwise switching to Postmark would
    // leave the last SMTP host somebody typed sitting invisibly behind it, ready to come back.
    //
    // Asserted against the configuration rather than by resolving a mailer, because building a
    // Postmark transport needs a package this repository deliberately does not require - which is
    // the subject of the two tests below.
    storeMail(['transport' => MailSetting::TRANSPORT_POSTMARK, 'password' => 'postmark-token']);

    app(MailConfiguration::class)->apply();

    expect(config('mail.default'))->toBe('postmark')
        ->and(config('mail.mailers.smtp.host'))->toBeNull()
        ->and(config('mail.mailers.smtp.password'))->toBeNull()
        ->and(config('mail.mailers.postmark.token'))->toBe('postmark-token');
});

it('knows which transports this installation could actually send through', function (): void {
    /*
     | config/mail.php has listed postmark and resend since it was generated, so MAIL_MAILER=postmark
     | has always been settable and has always died at send time with a class-not-found. A dropdown
     | makes that worse by looking like an offer, so the screen asks first.
     |
     | Written against class_exists rather than against a fixed list, so requiring either package
     | later turns the option on without anybody having to remember this test.
     */
    $available = MailSetting::availableTransports();

    expect($available)->toContain(MailSetting::TRANSPORT_SMTP)
        ->and($available)->toContain(MailSetting::TRANSPORT_SES)
        ->and($available)->toContain(MailSetting::TRANSPORT_LOG);

    foreach ([MailSetting::TRANSPORT_POSTMARK, MailSetting::TRANSPORT_RESEND] as $transport) {
        $missing = MailSetting::missingPackage($transport);

        expect(in_array($transport, $available, true))->toBe($missing === null);
    }
});

it('names the package rather than leaving somebody to read a stack trace', function (): void {
    $requirement = MailSetting::TRANSPORT_REQUIREMENTS[MailSetting::TRANSPORT_POSTMARK];

    expect(MailSetting::missingPackage(MailSetting::TRANSPORT_POSTMARK))
        ->toBe(class_exists($requirement['class']) ? null : 'symfony/postmark-mailer');

    // Nothing stands between smtp and sending, which is why it is the default and the first option.
    expect(MailSetting::missingPackage(MailSetting::TRANSPORT_SMTP))->toBeNull();
});

it('puts the environment back when the saved configuration is discarded', function (): void {
    /*
     | The snapshot's reason for existing. Deleting the row is not enough on its own: without it the
     | last saved values stay in the config repository for the rest of the process, and in a queue
     | worker the rest of the process is the rest of the hour.
     */
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.smtp.host', 'from-the-environment.example');

    $settings = storeMail();
    app('mail.manager')->mailer();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.relay.example');

    $settings->delete();
    app(MailConfiguration::class)->markStale();
    app('mail.manager')->mailer();

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.mailers.smtp.host'))->toBe('from-the-environment.example');
});

it('does nothing at all before the table exists', function (): void {
    // `key:generate`, `config:cache` and `manager:doctor` all run before `migrate` on a first
    // install. A password-reset must not become a 500 because a table is one deploy behind.
    Schema::drop('mail_settings');

    Mail::raw('Proving this does not explode', static fn (Message $message) => $message
        ->to('somebody@example.org')
        ->subject('Test'));
})->throwsNoExceptions();

it('encrypts the relay credential at rest', function (): void {
    storeMail(['password' => 'hunter2']);

    $raw = (string) DB::table('mail_settings')->value('password');

    // A database dump alone must not hand somebody the ability to send a convincing password-reset
    // email to every user of this installation.
    expect($raw)->not->toContain('hunter2')
        ->and(MailSetting::query()->firstOrFail()->password)->toBe('hunter2');
});

/*
|--------------------------------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------------------------------
*/

it('never renders the stored credential', function (): void {
    /*
     | The direct successor to the old "never puts mail configuration on the settings screen".
     |
     | The host, the login and the From address are shown now - that is what the screen is for. The
     | credential is not, and never will be: it is not rendered into the form, so there is nothing in
     | the page source to give away and nothing for a shoulder to read.
     */
    storeMail(['password' => 'hunter2']);

    $html = (string) $this->actingAs($this->owner)->get(route('settings.mail'))->assertOk()->getContent();

    expect($html)->toContain('smtp.relay.example')
        ->and($html)->toContain('apikey')
        ->and($html)->toContain('manager@example.org')
        ->and($html)->not->toContain('hunter2');
});

it('starts from the environment when nothing is stored', function (): void {
    // So a first save adopts what is already in force rather than silently replacing it with blanks.
    config()->set('mail.mailers.smtp.host', 'from-the-environment.example');
    config()->set('mail.from.address', 'noreply@example.org');

    $this->actingAs($this->owner)
        ->get(route('settings.mail'))
        ->assertOk()
        ->assertSee('from-the-environment.example')
        ->assertSee('noreply@example.org')
        ->assertSee('from the environment');
});

it('saves a relay and puts it into force for the rest of the request', function (): void {
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'apikey',
            'password' => 'hunter2',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $stored = MailSetting::query()->sole();

    expect($stored->host)->toBe('smtp.relay.example')
        ->and($stored->password)->toBe('hunter2')
        // Nothing has been proved yet. Saving is not sending.
        ->and($stored->last_tested_at)->toBeNull();
});

it('keeps a blank credential rather than clearing it', function (): void {
    // A browser sends an untouched password input as an empty string. If blank meant "clear", every
    // save that changed a port would silently drop the relay's login.
    storeMail(['password' => 'hunter2']);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 2525,
            'username' => 'apikey',
            'password' => '',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertRedirect();

    expect(MailSetting::query()->sole()->password)->toBe('hunter2');
});

it('clears the credential only when asked to explicitly', function (): void {
    storeMail(['password' => 'hunter2']);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 587,
            'username' => 'apikey',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
            'clear_password' => '1',
        ])->assertRedirect();

    expect(MailSetting::query()->sole()->password)->toBeNull();
});

it('refuses a transport this installation could not actually send through', function (): void {
    // The whole point of asking. Accepting it here would produce a class-not-found from a password
    // reset hours later, which is the worst possible place to discover a missing package.
    $unavailable = collect(MailSetting::TRANSPORTS)
        ->reject(fn (string $t): bool => MailSetting::missingPackage($t) === null)
        ->first();

    if ($unavailable === null) {
        $this->markTestSkipped('Every transport has its package installed here.');
    }

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.update'), [
            'transport' => $unavailable,
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertSessionHasErrors('transport');

    expect(MailSetting::query()->count())->toBe(0);
});

it('requires a host and a port for SMTP and a region for SES', function (): void {
    $recent = ['auth.password_confirmed_at' => now()->timestamp];

    $this->actingAs($this->owner)->withSession($recent)
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertSessionHasErrors(['host', 'port']);

    $this->actingAs($this->owner)->withSession($recent)
        ->post(route('settings.mail.update'), [
            'transport' => 'ses',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertSessionHasErrors(['username', 'region']);
});

it('discards the stored configuration on request', function (): void {
    storeMail();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->delete(route('settings.mail.forget'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(MailSetting::query()->count())->toBe(0);
});

it('records a change without recording the credential', function (): void {
    // App\Domain\Audit\SecretGuard throws rather than redacting, so a payload key matching
    // password|secret|credential|token would fail the write outright and take the save with it.
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 587,
            'username' => 'apikey',
            'password' => 'hunter2',
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])->assertRedirect();

    $event = AuditEvent::query()->where('action', 'settings.mail.updated')->sole();
    $after = $event->after;

    expect($after)->toHaveKey('host')
        ->and($after)->not->toHaveKey('password')
        ->and(json_encode($after))->not->toContain('hunter2');
});

it('stamps a successful test against the stored configuration', function (): void {
    storeMail();

    Mail::shouldReceive('raw')->once();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(MailSetting::query()->sole()->last_test_outcome)->toBe(MailSetting::OUTCOME_SUCCESS);
});

it('reports a failing transport as a class name and nothing else', function (): void {
    storeMail();

    Mail::shouldReceive('raw')->once()
        ->andThrow(new RuntimeException('535 auth failed for postmaster@example.org with hunter2'));

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('settings.mail.test'))
        ->assertRedirect();

    $warning = (string) session('warning');

    // A mail exception can carry the transport configuration, credentials included, and this
    // response is a web page.
    expect($warning)->toContain('RuntimeException')
        ->and($warning)->not->toContain('hunter2')
        ->and($warning)->not->toContain('535 auth failed');

    expect(MailSetting::query()->sole()->last_test_outcome)->toBe(MailSetting::OUTCOME_FAILURE);
});

it('keeps the whole screen to owners', function (): void {
    $member = User::factory()->create(['email_verified_at' => now()]);
    Membership::factory()->for($member)->for($this->organisation)->admin()->create();

    $recent = ['auth.password_confirmed_at' => now()->timestamp];

    $this->actingAs($member)->get(route('settings.mail'))->assertForbidden();
    $this->actingAs($member)->withSession($recent)->post(route('settings.mail.test'))->assertForbidden();
    $this->actingAs($member)->withSession($recent)->delete(route('settings.mail.forget'))->assertForbidden();
    $this->actingAs($member)->withSession($recent)
        ->post(route('settings.mail.update'), ['transport' => 'log'])
        ->assertForbidden();
});

it('needs recent authentication to change how mail is sent', function (): void {
    // Changing where mail leaves from changes how a password reset reaches somebody.
    $this->actingAs($this->owner)
        ->post(route('settings.mail.update'), [
            'transport' => 'smtp',
            'host' => 'smtp.relay.example',
            'port' => 587,
            'from_address' => 'manager@example.org',
            'from_name' => 'Manager',
        ])
        ->assertRedirect(route('password.confirm'));

    expect(MailSetting::query()->count())->toBe(0);
});

it('reads the stored configuration when it says whether mail is set up', function (): void {
    // Diagnostics reads config('mail.default') directly, and the stored configuration is applied at
    // send time. Without the apply() at the top of that check it would report the environment while
    // something else did the sending.
    config()->set('mail.default', 'log');

    storeMail();

    $mail = collect(app(Diagnostics::class)->all())->firstWhere('name', 'Mail');

    expect($mail->failed())->toBeFalse()
        ->and($mail->warned())->toBeFalse();
});
