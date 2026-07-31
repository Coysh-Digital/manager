<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\EmailTransport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Prove that mail actually leaves this installation.
 *
 * `manager:doctor` can say that a transport is configured; only sending something can say that it
 * works. The gap between those two matters more here than it looks: password resets and invitations
 * are the only way into a fresh installation that has no user yet, and the failure mode is silence.
 * Somebody invites a colleague, the colleague never receives anything, and nothing anywhere reports a
 * problem — which is why `manager:user:password` exists as a shell way in.
 *
 * The exception message is printed in full, and that is a deliberate departure from
 * {@see EmailTransport}, which reduces a failure to a class name because a
 * mail exception can carry the transport configuration and its message ends up in a delivery log a
 * whole organisation can read. This is a command run from a shell on the server by somebody who
 * already has the .env open. Withholding the reason from them would protect nothing and cost the
 * entire point of the command — the reason is almost always the useful part ("535 authentication
 * failed", "certificate verify failed"). The button on the Settings screen renders into a web page
 * and keeps the class name only.
 *
 * Nothing is queued. A queued test would report success as soon as the job was accepted, which is the
 * one thing already known.
 */
final class MailTestCommand extends Command
{
    protected $signature = 'manager:mail-test {email : Where to send it}';

    protected $description = 'Send a test email to prove the configured mail transport works';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("'{$recipient}' is not an email address.");

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            // Not an error. Writing mail to the log is a legitimate way to run this, and somebody who
            // has done it on purpose should be told where to look rather than corrected.
            $this->warn("MAIL_MAILER is '{$mailer}', so this will be written to the log rather than delivered.");
        }

        $this->line("Sending to {$recipient}…");

        try {
            Mail::raw($this->body(), function ($message) use ($recipient): void {
                $message->to($recipient)->subject('Manager test message');
            });
        } catch (Throwable $e) {
            $this->error('The message was not sent.');
            $this->newLine();
            $this->line($e->getMessage());
            $this->newLine();
            $this->line('Check the MAIL_* variables in .env. Nothing above is stored anywhere.');

            return self::FAILURE;
        }

        $this->info('Sent without error.');

        // Worth saying. A relay that accepts a message and then drops it is a different failure, and
        // this command cannot see it.
        $this->line('That means the transport accepted it, not that it arrived. Check the inbox.');

        return self::SUCCESS;
    }

    private function body(): string
    {
        return implode("\n", [
            'This is a test message from Manager.',
            '',
            'If you are reading it, this installation can send email — which means password resets,',
            'invitations and notification emails will reach people.',
            '',
            'Nothing else was sent, and no address was stored.',
        ]);
    }
}
