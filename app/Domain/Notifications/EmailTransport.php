<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\NotificationDestination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers a notification by email.
 *
 * Plain text, deliberately. An HTML mail about a security finding is a phishing template somebody has
 * been trained to click, and there is nothing here worth formatting.
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
            Mail::raw($this->body($event), function ($message) use ($destination, $event): void {
                $message->to($destination->target)
                    ->subject('['.config('app.name').'] '.$event->subject);
            });
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
     * The message body.
     *
     * Says what happened, which site, and where to look. No links with tokens in them, and no detail
     * about the vulnerability itself — an email sits in a mailbox for years.
     *
     * Public so it can be asserted on directly. Mail::raw does not produce a Mailable, so there is
     * nothing for the mail fake to inspect, and testing the wording through the transport would mean
     * testing it through the mailer.
     */
    public function body(NotificationEvent $event): string
    {
        $lines = [$event->summary, ''];

        if ($event->site !== null) {
            $lines[] = 'Site:        '.$event->site->name;
            $lines[] = 'Domain:      '.$event->site->expected_domain;
            $lines[] = 'Environment: '.$event->site->environment;
            $lines[] = '';
        }

        foreach ($event->context as $key => $value) {
            if (is_scalar($value)) {
                $lines[] = str_pad(Str::headline((string) $key).':', 13).$value;
            }
        }

        $lines[] = '';
        $lines[] = 'Open Manager: '.rtrim((string) config('app.url'), '/').'/findings';
        $lines[] = '';
        $lines[] = 'You are receiving this because a notification destination in Manager subscribes to';
        $lines[] = $event->type.'. Change that under Settings.';

        return implode("\n", $lines);
    }
}
