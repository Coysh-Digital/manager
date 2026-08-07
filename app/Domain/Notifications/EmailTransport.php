<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Mail\AlertMail;
use App\Models\NotificationDestination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers a notification by email.
 *
 * **This used to send plain text through `Mail::raw`, and the argument for it was that an HTML mail
 * about a security finding is a phishing template somebody has been trained to click.** That was a
 * real argument and it is worth saying why it no longer decides this, because the code now does the
 * opposite of what its own docblock instructed for a year.
 *
 * The alerts were the only mail this installation sent that did not look like it came from us.
 * Password resets, invitations and every subscription email render through the Manager theme, so an
 * unstyled monospace block among them read as the odd one out - and "does not look like the other
 * mail from this product" is itself a phishing signal, pointing the wrong way. The message that
 * matters most was the one hardest to recognise.
 *
 * What the old argument was really protecting is the *link*, and that is kept intact rather than
 * traded away. See {@see AlertLink}: every destination is a named route to a screen that requires a
 * session, never a signed or tokenised URL, so the email is an address and never a credential. A
 * plain-text alternative still goes out alongside the HTML, built by {@see self::body()}, so a
 * client that refuses HTML loses nothing.
 *
 * `tests/Invariants/MailBrandingTest.php` asserts all of that, and it is the thing to read before
 * changing any of it back.
 */
final class EmailTransport
{
    /**
     * @return array{outcome: string, status_code: int|null, failure_reason: string|null, duration_ms: int}
     */
    public function send(NotificationDestination $destination, NotificationEvent $event): array
    {
        $startedAt = microtime(true);

        try {
            // Not queued, and the mailable is deliberately not a ShouldQueue: DeliverNotification is
            // already the queued job, and queueing from inside it would report success the moment a
            // second job was accepted rather than when the mail left.
            Mail::to($destination->target)->send(new AlertMail($event));
        } catch (Throwable $e) {
            return [
                'outcome' => 'failed',
                'status_code' => null,
                // The class name rather than the message: a mail exception can carry the transport
                // configuration, including credentials.
                'failure_reason' => Str::limit('Mail failed ('.(new \ReflectionClass($e))->getShortName().').', 200),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        return [
            'outcome' => 'sent',
            'status_code' => null,
            'failure_reason' => null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * What happened, as label and value pairs.
     *
     * The single source both parts of the message render from - a table in the HTML, an aligned
     * block in the text - so the two cannot come to say different things. Says which site and where
     * to look, and nothing about the vulnerability itself: an email sits in a mailbox for years.
     *
     * @return array<string, string>
     */
    public function rows(NotificationEvent $event): array
    {
        $rows = [];

        if ($event->site !== null) {
            $rows['Site'] = $event->site->name;
            $rows['Domain'] = $event->site->expected_domain;
            $rows['Environment'] = $event->site->environment;
        }

        foreach ($event->context as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $rendered = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;

            /*
             | Skip anything the summary has already said.
             |
             | BackupFailureNotice puts the failure reason in both places on purpose - the summary
             | leads with it because it is the only part that says what to do, and the context keeps
             | it because NotificationEvent::toPayload() is the webhook contract and a consumer may
             | read `context.reason`. Removing it there would be a wire change to fix a cosmetic
             | problem. Reported live: the alert printed the same sentence twice, once as prose and
             | once as a labelled row directly beneath it.
            */
            if ($rendered !== '' && str_contains($event->summary, $rendered)) {
                continue;
            }

            $rows[(string) Str::headline((string) $key)] = $rendered;
        }

        return $rows;
    }

    /**
     * The plain-text alternative.
     *
     * Public so it can be asserted on directly, and because the text part is where the old
     * plain-text-only decision still lives: whatever the HTML does, this has to remain a complete
     * message on its own.
     */
    public function body(NotificationEvent $event): string
    {
        $link = AlertLink::for($event);
        $rows = $this->rows($event);

        $lines = [$event->summary, ''];

        if ($rows !== []) {
            // Sized from the longest label present rather than a fixed width. It was str_pad(…, 13),
            // which silently ran the label into the value for anything longer than twelve characters.
            $width = max(array_map(strlen(...), array_keys($rows))) + 2;

            foreach ($rows as $label => $value) {
                $lines[] = '  '.str_pad($label, $width).$value;
            }

            $lines[] = '';
        }

        $lines[] = $link->label.':';
        $lines[] = $link->url;
        $lines[] = '';
        $lines[] = '--';
        $lines[] = 'You are receiving this because a notification destination in Manager subscribes to';
        $lines[] = $event->type.'. Change what you are sent:';
        $lines[] = AlertLink::preferences();

        return implode("\n", $lines);
    }
}
