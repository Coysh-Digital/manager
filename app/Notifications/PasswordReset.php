<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Notifications\EmailCopy;
use App\Domain\Notifications\EmailCopyTemplate;
use App\Domain\Notifications\Greeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody has asked for a way back into their account.
 *
 * This exists for the reason {@see TeamInvitation} exists. The framework's own notification opens
 * "You are receiving this email because we received a password reset request for your account" -
 * correct, and written by nobody in particular. It is the last email in this installation still
 * speaking in a voice that is not the product's, and it is the one arriving at the moment somebody
 * is already slightly worried.
 *
 * **The mechanism is untouched.** Laravel's broker still issues the token: single-use, expiring,
 * stored hashed. Only the words are different, which is the same trade the invitation made.
 *
 * **Not queued, and unlike the invitation that is deliberate.** An invitation is sent after a form
 * submission that has already succeeded, so the membership exists whether the mail does or not. A
 * reset is the whole transaction: if it does not arrive, somebody is locked out. `QUEUE_CONNECTION`
 * defaults to `database`, so a fresh installation with no worker running would accept the request,
 * report success, and send nothing - and password resets are one of only two ways into an
 * installation that has no other account.
 */
final class PasswordReset extends Notification
{
    /** The catalogue key this email's wording is stored under. */
    public const COPY_KEY = 'core.password-reset';

    /**
     * The wording this ships with.
     *
     * Only the opening paragraphs are here. What the editor cannot reach is listed in
     * {@see self::toMail()}, with the reason for each.
     */
    public static function copy(): EmailCopyTemplate
    {
        return new EmailCopyTemplate(
            key: self::COPY_KEY,
            subject: 'Reset your Manager password',

            // "asked to reset" is asserted verbatim by tests/Invariants/MailBrandingTest.php, which
            // uses it to prove the Manager theme rendered at all. Rewording this first sentence
            // turns that test red for a reason that reads as unrelated to whoever did it.
            body: <<<'COPY'
                Somebody asked to reset the password on the Manager account for :email.

                If that was you, the button below sets a new one.
                COPY,

            placeholders: [
                'email' => 'The address the reset was requested for',
            ],
        );
    }

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();

        $url = route('password.reset', [
            'token' => $this->token,

            // The broker keys its token by email, so the link carries it. Same shape the framework's
            // own reset link uses, because it is answered by the same controller.
            'email' => $email,
        ]);

        $expires = (int) config('auth.passwords.users.expire', 60);

        $copy = app(EmailCopy::class);
        $replacements = ['email' => $email];

        $message = (new MailMessage)
            ->subject($copy->subject(self::COPY_KEY, $replacements))
            ->greeting(Greeting::for($notifiable));

        foreach ($copy->lines(self::COPY_KEY, $replacements) as $line) {
            $message->line($line);
        }

        /*
         | Everything below is fixed in code, and no override can reach it.
         |
         | The action label and its URL, because links are the phishing surface of an email: an
         | editable destination would turn a wording screen into an open redirector aimed at people
         | who have been told to expect a link from us.
         |
         | The expiry sentence, because it states a number the code enforces. Wording that can
         | disagree with behaviour is worse than wording nobody can change.
         |
         | And the last paragraph, appended after the editable body so that no edit can remove it.
         | It is the only thing in the message that tells somebody who did not ask for this that
         | they do not have to do anything, and whoever is rewriting the copy that week should not
         | be able to take it away.
        */
        return $message
            ->action('Set a new password', $url)
            ->line("This link works for the next {$expires} minutes and can be used once. If it expires, ask for another from the sign-in screen.")
            ->line('If you did not ask for this, ignore it. Your password has not changed, and nobody can change it without this link.');
    }
}
