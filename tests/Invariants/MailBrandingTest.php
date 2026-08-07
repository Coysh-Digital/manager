<?php

declare(strict_types=1);

use App\Domain\Backup\BackupFailureNotice;
use App\Domain\Notifications\EmailCopy;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Mail\AlertMail;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PasswordReset;
use App\Notifications\TeamInvitation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * What customer-facing email looks like.
 *
 * **This file used to assert the opposite of half of what it now asserts.** The monitoring alerts
 * were plain text on purpose, and the argument was that an HTML mail about a security finding is a
 * phishing template somebody has been trained to click. That decision was reversed deliberately, by
 * the person who owns the product, and not because a check went red — see
 * App\Domain\Notifications\EmailTransport for what changed our mind.
 *
 * What survived the reversal is the part that was actually load-bearing: an alert links to a screen
 * and never carries a credential. That is asserted below, harder than it was before.
 */

it('renders MailMessage through the Manager theme', function (): void {
    /*
     | The theme resolves through the view finder, so a typo in config/mail.php or a moved file does
     | not error — it silently falls back to Laravel's stock CSS and every email keeps sending. That
     | is exactly the failure worth a test: nothing breaks, and the product just looks like a
     | framework default to everybody who receives one.
     */
    $rendered = (string) (new TeamInvitation('token', 'Coysh Digital', 'Somebody'))
        ->toMail(new User(['name' => 'Invitee', 'email' => 'invitee@example.org']))
        ->render();

    expect($rendered)->toContain('#c9331c')
        ->and($rendered)->toContain('has invited you');
});

it('renders the password reset in Manager\'s own words', function (): void {
    /*
     | The framework's own notification is perfectly good English written by nobody in particular,
     | and it was the last email here still speaking in that voice. Overriding it is one method on
     | the User model, which is exactly the kind of thing a later refactor removes without noticing:
     | the reset would keep working, keep arriving, and quietly go back to "we received a password
     | reset request for your account".
     */
    $rendered = (string) (new PasswordReset('token'))
        ->toMail(new User(['name' => 'Tim Coysh', 'email' => 'tim@example.org']))
        ->render();

    expect($rendered)->toContain('#c9331c')
        ->and($rendered)->toContain('asked to reset')
        ->and($rendered)->toContain('Hello Tim')
        ->and($rendered)->not->toContain('we received a password reset request');
});

it('sends a password reset through Manager\'s notification, not the framework\'s', function (): void {
    // The override above is only worth anything if the broker actually reaches it.
    Notification::fake();

    $user = User::factory()->create();
    $user->sendPasswordResetNotification('token');

    Notification::assertSentTo($user, PasswordReset::class);
});

it('keeps the email theme in step with the interface palette', function (string $token, string $hex): void {
    /*
     | In-repo parity, doing for the email theme what the marketing repository's DesignTokenParityTest
     | does for its copy of the palette.
     |
     | The theme cannot use var(--token): email clients do not support custom properties, and adding
     | one to app.css for this purpose would turn that other suite red in a repository nobody editing
     | the theme is looking at. So the values are copied, and copies drift. This is the thing that
     | notices — in the same repository as both files, so whoever changes the palette is told.
     */
    $palette = File::get(resource_path('css/app.css'));
    $theme = File::get(resource_path('views/vendor/mail/html/themes/manager.css'));

    // The light :root block. The theme is light-only on purpose; see its header.
    $light = substr($palette, 0, strpos($palette, ":root[data-theme='dark']") ?: strlen($palette));

    expect($light)->toContain("{$token}: {$hex}")
        ->and($theme)->toContain($hex);
})->with([
    ['--primary', '#c9331c'],
    ['--text', '#1c1917'],
    ['--text-2', '#5d5854'],
    ['--text-3', '#8a847f'],
    ['--bg', '#f7f5f2'],
    ['--surface', '#ffffff'],
    ['--border', '#e5e1db'],
    ['--pale', '#fcebe6'],
]);

it('escapes markup an operator types into an override', function (): void {
    /*
     | Operator-entered copy is rendered through Laravel's markdown pipeline, which is configured to
     | escape raw HTML and to strip unsafe link schemes. Both are defaults, and both are one line in
     | config/mail.php away from not being — so this asserts the behaviour rather than the setting.
     |
     | The threat is not really an operator attacking their own customers. It is that this is the one
     | screen where free text typed into a back-office ends up rendered in somebody else's inbox, and
     | a pasted fragment carrying a script tag or a javascript: link should fail closed.
     */
    app(EmailCopy::class)->put(
        TeamInvitation::COPY_KEY,
        subject: null,
        body: "<script>alert(1)</script>\n\n[Click here](javascript:alert(1))",
    );

    $rendered = (string) (new TeamInvitation('token', 'Coysh Digital', 'Tim'))
        ->toMail(new User(['name' => 'Invitee', 'email' => 'invitee@example.org']))
        ->render();

    expect($rendered)->not->toContain('<script>')
        ->and($rendered)->not->toContain('javascript:');
});

it('sends an alert as branded HTML with a plain-text alternative', function (): void {
    /*
     | Both parts, from the message that was actually built rather than from Mailable::render(),
     | which only ever returns the HTML. A text part that has silently stopped being attached is the
     | failure worth catching: nothing errors, every alert keeps arriving, and the people whose
     | clients refuse HTML get an empty message.
     */
    $site = Site::factory()->create(['name' => 'Example Site', 'expected_domain' => 'example.org']);

    Mail::to('alerts@example.org')->send(new AlertMail(
        BackupFailureNotice::event($site, 'The site refused the artifact', '01ARTIFACT'),
    ));

    $message = Mail::mailer()->getSymfonyTransport()->messages()[0]->getOriginalMessage();

    $html = (string) $message->getHtmlBody();
    $text = (string) $message->getTextBody();

    expect($html)->toContain('#c9331c')
        ->and($html)->toContain('Example Site')
        ->and($text)->toContain('Example Site')
        ->and($text)->toContain('example.org');

    /*
     | The half of the old plain-text decision that was actually load-bearing.
     |
     | An alert sits in a mailbox for years and gets forwarded, so its link must be an address and
     | never a credential — every destination is a named route to a screen behind a session. A
     | signed URL here would make the email itself the way in, for anybody who ever sees it.
    */
    foreach ([$html, $text] as $part) {
        expect($part)->not->toContain('token')
            ->and($part)->not->toContain('signature=')
            ->and($part)->not->toContain(config('app.key'));
    }
});

it('points an alert at the screen the event is about', function (): void {
    /*
     | Every alert used to end with the same URL — /findings — whatever had happened. Reported live:
     | a backup failure arrived pointing at the screen that lists security findings, which had
     | nothing to say about it, so the reasonable conclusion was that the alert was wrong.
     */
    $site = Site::factory()->create();

    $backup = app(EmailTransport::class)->body(
        BackupFailureNotice::event($site, 'The site refused the artifact'),
    );

    $finding = app(EmailTransport::class)->body(new NotificationEvent(
        type: NotificationEvent::FINDING_OPENED,
        subject: 'Craft has an outstanding security release',
        summary: 'This site runs Craft 5.6.2 and 5.6.4 is available.',
        site: $site,
    ));

    expect($backup)->toContain(route('sites.backups', $site))
        ->and($backup)->not->toContain('/findings')
        ->and($finding)->toContain(route('findings.index'));
});

it('says the failure reason once', function (): void {
    /*
     | BackupFailureNotice puts the reason in the summary and in the context, and both are correct:
     | the summary leads with it because it is the only part saying what to do, and the context
     | keeps it because NotificationEvent::toPayload() is the webhook contract. Reported live: the
     | email printed the same sentence twice, once as prose and once as a labelled row beneath it.
     */
    $site = Site::factory()->create(['name' => 'Example Site']);
    $reason = 'The site refused the artifact';

    $body = app(EmailTransport::class)->body(BackupFailureNotice::event($site, $reason));

    expect(substr_count($body, $reason))->toBe(1);

    // And the webhook still carries it, which is the reason it could not simply be deleted.
    expect(BackupFailureNotice::event($site, $reason)->toPayload()['context']['reason'])->toBe($reason);
});
