<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Notifications\AlertLink;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One monitoring alert, as an email.
 *
 * A Mailable rather than a Notification because there is no notifiable: an email destination is a
 * row somebody typed an address into, not a user, and it may not correspond to an account at all.
 * {@see EmailTransport} is what sends it.
 *
 * **The text part is declared rather than derived.** Laravel will happily render a plain-text
 * alternative from the markdown view, and it does a decent job of it - but the text part is what a
 * client refusing HTML falls back to, and it should not change shape as a side effect of somebody
 * rearranging a Blade template. It comes from {@see EmailTransport::body()}, which is also what the
 * invariants assert on.
 */
final class AlertMail extends Mailable
{
    public function __construct(private readonly NotificationEvent $event) {}

    public function envelope(): Envelope
    {
        // The bracketed product name stays. It reads like a mailing list, which is a fair complaint,
        // and it is also what a filter rule matches on - and somebody who has routed these into a
        // folder should not find out that the rule stopped working by not being told about a backup.
        return new Envelope(
            subject: '['.config('app.name').'] '.$this->event->subject,
        );
    }

    public function content(): Content
    {
        $transport = app(EmailTransport::class);

        return new Content(
            markdown: 'mail.alert',
            text: 'mail.alert-text',
            with: [
                'event' => $this->event,
                'rows' => $transport->rows($this->event),
                'link' => AlertLink::for($this->event),
                'preferences' => AlertLink::preferences(),
                'plain' => $transport->body($this->event),
            ],
        );
    }
}
