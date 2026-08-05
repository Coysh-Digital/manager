<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\TeamInvitation;
use Illuminate\Support\Facades\File;

/*
 * What customer-facing email looks like, and what deliberately does not.
 *
 * Two separate concerns share this file because they are the two halves of one decision: MailMessage
 * emails are branded HTML, and the findings alerts are not, and each is wrong if it drifts into the
 * other.
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

it('leaves the findings alerts as plain text', function (): void {
    /*
     | The decision this protects is stated in EmailTransport's own docblock: an HTML mail about a
     | security finding is a phishing template somebody has been trained to click. It is the kind of
     | thing a later "let's brand all our emails" change undoes without noticing, because branding
     | everything is the obvious instruction and this is the one exception to it.
     */
    $source = File::get(app_path('Domain/Notifications/EmailTransport.php'));

    expect($source)->toContain('Mail::raw(')
        ->and($source)->not->toContain('->markdown(')
        ->and($source)->not->toContain('->view(');
});
