<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Notifications\EmailCopy;
use App\Domain\Notifications\EmailCopyTemplate;
use App\Domain\Team\TeamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody has been invited to help run this installation.
 *
 * This exists because invitations used to be password resets. `Password::sendResetLink()` was the
 * whole implementation, which is a good mechanism and the wrong message: a colleague who has never
 * had an account received "You are receiving this email because we received a password reset request
 * for your account", about an account they had never heard of, from a product they had never used.
 * The commonest reaction to that email is to delete it as phishing, which is the correct instinct and
 * a bad first impression.
 *
 * The mechanism is unchanged and deliberately so — see {@see TeamService}. The token
 * is still Laravel's own broker: single-use, expiring, stored hashed, and never seen by the
 * administrator who issued it. Only the words are different, and the words were the problem.
 *
 * Queued, unlike the framework's reset notification as it is dispatched here. An invitation is sent
 * from a form submission that has already succeeded — the membership row exists whether or not the
 * mail does — so a slow relay should not be holding that request open. TeamService already sends
 * outside its transaction for the same reason.
 */
final class TeamInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /** The catalogue key this email's wording is stored under. */
    public const COPY_KEY = 'core.invitation';

    /**
     * The wording this ships with.
     *
     * Here rather than in the catalogue registration, so the text sits next to the class that sends
     * it and the registry stays a registry. It also gives the invariants something to compare: an
     * entry advertising wording its own class does not send is a drift the suite can see.
     *
     * Only the opening two paragraphs are here. What the editor cannot reach is listed in
     * {@see self::toMail()}, with the reason for each.
     */
    public static function copy(): EmailCopyTemplate
    {
        return new EmailCopyTemplate(
            key: self::COPY_KEY,
            subject: ':inviter has invited you to :organisation on Manager',

            // "has invited you" is asserted verbatim by tests/Invariants/MailBrandingTest.php, which
            // uses it to prove the Manager mail theme rendered at all. Rewording this first sentence
            // turns that test red for a reason that reads as unrelated to whoever did it.
            body: <<<'COPY'
                :inviter has invited you to help look after :organisation's Craft sites in Manager.

                Manager watches those sites for security findings and things that have stopped working, and keeps encrypted backups of them. Setting a password is all that is needed to accept.
                COPY,

            placeholders: [
                'inviter' => 'The name of the person who sent the invitation',
                'organisation' => 'The organisation they are being invited to',
            ],
        );
    }

    public function __construct(
        private readonly string $token,
        private readonly string $organisation,
        private readonly string $invitedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,

            // The broker keys its token by email, so the link carries it. Same shape the framework's
            // own reset link uses, because it is answered by the same controller.
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $expires = (int) config('auth.passwords.users.expire', 60);

        /*
         | Resolved here rather than held as a property, and both halves of that matter.
         |
         | This class implements ShouldQueue, so anything on a property is serialised into the job
         | payload — a service among them would be at best wasteful and at worst unserialisable.
         | Reading it at send time is also the correct semantics: the wording that goes out is the
         | wording as it stands when the mail is actually sent, not as it stood when an
         | administrator pressed the button.
         */
        $copy = app(EmailCopy::class);

        $replacements = [
            'inviter' => $this->invitedBy,
            'organisation' => $this->organisation,
        ];

        $message = (new MailMessage)
            ->subject($copy->subject(self::COPY_KEY, $replacements))
            ->greeting('Hello');

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
         | The honest reading of an unexpected invitation is that it is a phishing attempt, and
         | somebody who cannot place the sender should not be persuaded out of that instinct by
         | whoever is writing the copy that week.
         */
        return $message
            ->action('Set your password', $url)
            ->line("This link works for the next {$expires} minutes and can be used once. If it expires, ask whoever invited you to send another.")
            ->line('If you were not expecting this, ignore it. Nothing is created against your address until you set a password, and the invitation expires on its own.');
    }
}
