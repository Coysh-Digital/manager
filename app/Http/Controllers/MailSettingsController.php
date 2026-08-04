<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\MailAdministration;
use App\Contracts\ServerAccess;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Notifications\EmailTransport;
use App\Models\AuditEvent;
use App\Models\MailSetting;
use App\Models\Membership;
use App\Models\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * How this installation sends mail.
 *
 * Two gates, both applied to every action here. Self-hosted only, because on a hosted edition the
 * relay belongs to whoever runs the service and this screen would be a form for changing somebody
 * else's infrastructure. And owner-only, because the screen holds a relay's host and login, and
 * whoever administers sites is not necessarily whoever holds those.
 *
 * That second gate is the old rule kept rather than dropped. There used to be no mail screen at all,
 * on the reasoning that whoever can reach Settings is not necessarily whoever holds the credentials.
 * That reasoning was right; what was wrong was the conclusion, because the alternative it left was a
 * shell on the server — and on an installation whose mail has never worked, the one thing that
 * cannot be used to tell somebody how to fix it is email. So the rule became a permission.
 *
 * The stored password is write-only. It is never rendered back into the form, an untouched password
 * input arrives as an empty string, and blank therefore means "keep what is stored". Removing one is
 * an explicit act.
 *
 * @see MailConfiguration for how a saved configuration comes into force, and why the environment is
 *      never written to.
 */
final class MailSettingsController
{
    public function __construct(
        private readonly MailConfiguration $configuration,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(): View
    {
        $this->authorise();

        $stored = $this->configuration->stored();

        return view('settings.mail', [
            'settings' => $stored,
            'transports' => MailSetting::TRANSPORTS,

            /*
             | What the environment holds, so a first visit shows the configuration already in force
             | rather than an empty form that would silently replace it on save.
             |
             | The password is deliberately not among these. Reading it out of the environment onto a
             | web page would undo the reason it is write-only in the first place, and somebody
             | adopting the environment's settings has, by definition, access to the file it is in.
             */
            'environment' => [
                'transport' => (string) config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username'),
                'from_address' => config('mail.from.address'),
                'from_name' => config('mail.from.name'),
            ],
        ]);
    }

    /**
     * Save a configuration, or adopt the environment's as a starting point.
     */
    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorise();

        $transport = (string) $request->input('transport');

        $rules = [
            /*
             | Only what this installation could actually send through. config/mail.php has listed
             | postmark and resend since it was generated, and neither package is required — so a
             | posted transport must be checked rather than trusted, or the form would accept a
             | setting that fails at send time with a class-not-found hours later.
             */
            'transport' => ['required', Rule::in(MailSetting::availableTransports())],
            'from_address' => ['required', 'email:rfc', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:512'],
        ];

        $rules += match ($transport) {
            MailSetting::TRANSPORT_SMTP => [
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
                'username' => ['nullable', 'string', 'max:255'],
            ],
            MailSetting::TRANSPORT_SES => [
                // The access key id. Not a secret, and the only field here that is a login rather
                // than a password.
                'username' => ['required', 'string', 'max:255'],
                'region' => ['required', 'string', 'max:32'],
            ],
            default => [],
        };

        $validated = $request->validate($rules);

        $settings = $this->configuration->stored() ?? new MailSetting;
        $before = $settings->exists ? $settings->auditable() : null;

        $settings->fill([
            'singleton' => true,
            'transport' => $validated['transport'],
            'from_address' => $validated['from_address'],
            'from_name' => $validated['from_name'],

            // Written for every transport, null included, for the same reason toConfig() writes
            // every key: a host left over from SMTP must not sit invisibly behind Postmark.
            'host' => $transport === MailSetting::TRANSPORT_SMTP ? ($validated['host'] ?? null) : null,
            'port' => $transport === MailSetting::TRANSPORT_SMTP ? ($validated['port'] ?? null) : null,
            'encryption' => $transport === MailSetting::TRANSPORT_SMTP ? ($validated['encryption'] ?? null) : null,
            'region' => $transport === MailSetting::TRANSPORT_SES ? ($validated['region'] ?? null) : null,
            'username' => in_array($transport, [MailSetting::TRANSPORT_SMTP, MailSetting::TRANSPORT_SES], true)
                ? ($validated['username'] ?? null)
                : null,
        ]);

        // Write-only. A browser sends an untouched password input as an empty string, so blank means
        // "keep what is stored" — there is nothing to render back into the form and nothing to leave
        // in the HTML. Clearing one is a separate, explicit act.
        if ($request->boolean('clear_password')) {
            $settings->password = null;
        } elseif (filled($validated['password'] ?? null)) {
            $settings->password = $validated['password'];
        }

        // Whatever was proved before is not proof of this. Stamped null rather than left standing, so
        // the banner comes back until somebody sends a test through the new settings.
        if ($settings->isDirty()) {
            $settings->last_tested_at = null;
            $settings->last_test_outcome = null;
        }

        $settings->updated_by = $request->user()->id;
        $settings->save();

        // So the rest of this request — including the test send sitting beside the save button —
        // uses what was just saved rather than what it replaced.
        $this->configuration->markStale();

        $this->audit->record(
            action: 'settings.mail.updated',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: $before,
            after: $settings->auditable(),
        );

        return back()->with(
            'status',
            'Saved. Send a test to prove it works — a configuration that validates is not the same as '
                .'mail arriving.',
        );
    }

    /**
     * Discard the stored configuration and go back to the environment.
     *
     * The complete undo, and the reason this feature stores an override rather than editing `.env`.
     * Nothing about signing in depends on mail, so a live session can always reach this — which is
     * what makes a saved-but-broken relay recoverable by somebody with no shell.
     */
    public function forget(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorise();

        $settings = $this->configuration->stored();

        if ($settings === null) {
            return back();
        }

        $before = $settings->auditable();
        $settings->delete();

        $this->configuration->markStale();

        $this->audit->record(
            action: 'settings.mail.reverted',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: $before,
        );

        return back()->with('status', 'Discarded. Mail is sent with the MAIL_* variables from the environment again.');
    }

    /**
     * Send a test message to the signed-in owner's own address.
     *
     * Deliberately to *their* address and nowhere else. A field here would be a form on an
     * authenticated page that sends arbitrary mail to an arbitrary address from this installation's
     * relay, which is an open relay with extra steps and a reputation problem waiting to happen.
     *
     * Not queued. A queued send reports success the moment the job is accepted, which proves the
     * queue works and says nothing about mail. The point is to fail here, now, where somebody is
     * looking.
     *
     * The failure is reported as a class name rather than a message, matching
     * {@see EmailTransport}: a mail exception can carry the transport
     * configuration, credentials included, and this response is a web page. `manager:mail-test`
     * prints the whole thing, because a shell on the server is a different audience.
     */
    public function test(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorise();

        $user = $request->user();

        try {
            Mail::raw(
                "This is a test message from Manager.\n\n"
                    .'If you are reading it, this installation can send email — which means password '
                    .'resets, invitations and notification emails will reach people.',
                static fn (Message $message) => $message->to($user->email)->subject('Manager test message'),
            );
        } catch (Throwable $e) {
            $this->stampTest(MailSetting::OUTCOME_FAILURE);

            $this->audit->record(
                action: 'settings.mail.tested',
                organisation: $organisation,
                actor: $user,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: $e::class,
            );

            $message = sprintf('The message was not sent (%s). ', class_basename($e))
                .($this->configuration->stored() !== null
                    ? 'Check the settings above — "Use the environment configuration" puts back whatever the server\'s .env holds.'
                    : 'Check the MAIL_* variables in the environment, or configure a relay above.');

            // Only offered to a reader who has a shell to run it in. See App\Contracts\ServerAccess.
            if (app(ServerAccess::class)->reachable()) {
                $message .= sprintf(' For the full error, run manager:mail-test %s on the server.', $user->email);
            }

            return back()->with('warning', $message);
        }

        $this->stampTest(MailSetting::OUTCOME_SUCCESS);

        $this->audit->record(
            action: 'settings.mail.tested',
            organisation: $organisation,
            actor: $user,
        );

        return back()->with(
            'status',
            "Sent to {$user->email}. The transport accepted it, which is not the same as it arriving — check your inbox.",
        );
    }

    /**
     * Record that this configuration has, or has not, been proved to send.
     */
    private function stampTest(string $outcome): void
    {
        $settings = $this->configuration->stored();

        // Nothing to stamp when the environment is the configuration. The result still reaches the
        // reader as a flash message; it is only the standing banner that has nowhere to live.
        $settings?->forceFill([
            'last_tested_at' => now(),
            'last_test_outcome' => $outcome,
        ])->save();
    }

    private function authorise(): void
    {
        /*
         | 404 before 403, deliberately. On a hosted edition this screen does not exist, and it must
         | not answer differently for an owner than for anybody else — a 403 would confirm there is
         | something there to be forbidden from.
         |
         | Refused rather than merely hidden, for the reason the test send has carried since it was
         | written: a route that still works when its control has gone is how a removed feature comes
         | back by URL.
         */
        abort_unless(app(MailAdministration::class)->operatorManaged(), 404);

        abort_unless(app(Membership::class)->isOwner(), 403);
    }
}
