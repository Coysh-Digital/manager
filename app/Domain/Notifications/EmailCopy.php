<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\EmailCopyOverride;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What an email actually says, once anything an operator has changed is taken into account.
 *
 * The rule is one line — an override if there is one, the code's own wording otherwise — and every
 * notification whose wording is editable asks this rather than building its text inline.
 *
 * **There is no interface and no binding in `app/Contracts`.** Every entry in that directory answers
 * a policy question that the two editions genuinely disagree about, with one answer per
 * installation. This is not that shape: both editions resolve copy identically, and the only thing
 * that differs is whether a screen exists to write it. A contract here would need a self-hosted
 * implementation, and the honest one would be "you cannot change the wording", which is a worse
 * product answer than "you can, but this edition ships no screen for it yet".
 *
 * **Not a singleton, and nothing is cached.** Both for the same reason: a queue worker is a
 * long-lived process, and either would mean it sending wording an operator changed twenty minutes
 * ago with nothing to tell them why. The cost avoided would be one indexed lookup on a table with a
 * dozen rows, on a code path that is already opening an SMTP connection.
 */
final class EmailCopy
{
    public function __construct(private readonly EmailCatalogue $catalogue) {}

    /**
     * The subject line to send, with tokens substituted.
     *
     * @param  array<string, string>  $replacements
     */
    public function subject(string $key, array $replacements = []): string
    {
        $template = $this->template($key);

        return $template->render(
            $this->override($key)->subject ?? $template->subject,
            $replacements,
        );
    }

    /**
     * The body to send, split into paragraphs ready for MailMessage::line().
     *
     * Split here rather than at each call site, because MailMessage is line-based and a caller
     * given one blob would have to do this itself — differently, eventually.
     *
     * @param  array<string, string>  $replacements
     * @return list<string>
     */
    public function lines(string $key, array $replacements = []): array
    {
        $template = $this->template($key);

        $body = $template->render(
            $this->override($key)->body ?? $template->body,
            $replacements,
        );

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R{2,}/', $body) ?: []),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
    }

    /**
     * The code's own wording for an editable email.
     *
     * Throws rather than returning null for an unknown key. Every caller here is either a
     * notification naming its own constant or a screen that has already checked the entry is
     * editable, so a miss is a programming error rather than a state to handle.
     */
    public function template(string $key): EmailCopyTemplate
    {
        $template = $this->catalogue->find($key)?->copy;

        if ($template === null) {
            throw new InvalidArgumentException("No editable email is registered under `{$key}`.");
        }

        return $template;
    }

    public function override(string $key): ?EmailCopyOverride
    {
        return EmailCopyOverride::query()->where('key', $key)->first();
    }

    /**
     * Every override, keyed by catalogue key.
     *
     * One query, for the screen that lists the whole catalogue and has to mark which rows have been
     * edited. Asking per row would be a query per email.
     *
     * @return Collection<string, EmailCopyOverride>
     */
    public function overrides(): Collection
    {
        return EmailCopyOverride::query()->get()->keyBy('key');
    }

    /**
     * Store new wording.
     *
     * Validates before it writes, and the placeholder check is the half that matters: an undeclared
     * `:token` cannot be substituted, so it would send to a customer as literal text. Callers catch
     * {@see InvalidArgumentException} and turn it into a field error.
     */
    public function put(string $key, ?string $subject, ?string $body, ?User $by = null): EmailCopyOverride
    {
        $template = $this->template($key);

        $unknown = $template->unknownTokensIn(...array_filter([$subject, $body], is_string(...)));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Nothing replaces '.implode(', ', array_map(static fn (string $t): string => ':'.$t, $unknown)).'.',
            );
        }

        $override = $this->override($key) ?? new EmailCopyOverride;

        $override->forceFill([
            'key' => $key,
            'subject' => $subject,
            'body' => $body,
            'updated_by' => $by?->id,
        ])->save();

        return $override;
    }

    /** Revert to the code's own wording. The row is the override, so deleting it is the revert. */
    public function forget(string $key): void
    {
        EmailCopyOverride::query()->where('key', $key)->delete();
    }
}
