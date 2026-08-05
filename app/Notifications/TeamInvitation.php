<?php

declare(strict_types=1);

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject("{$this->invitedBy} has invited you to {$this->organisation} on Manager")
            ->greeting('Hello')
            ->line("{$this->invitedBy} has invited you to help look after {$this->organisation}'s Craft sites in Manager.")
            ->line('Manager watches those sites for security findings and things that have stopped working, and keeps encrypted backups of them. Setting a password is all that is needed to accept.')
            ->action('Set your password', $url)
            ->line("This link works for the next {$expires} minutes and can be used once. If it expires, ask whoever invited you to send another.")

            // Said plainly, because the honest reading of an unexpected invitation is that it is a
            // phishing attempt, and somebody who cannot place the sender should not be persuaded.
            ->line('If you were not expecting this, ignore it. Nothing is created against your address until you set a password, and the invitation expires on its own.');
    }
}
