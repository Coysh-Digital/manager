<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Health\Check;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One kind of email this installation can send, and what causes it.
 *
 * A value object in the style of {@see Check}: constructed through named helpers,
 * read-only afterwards, and carrying no behaviour beyond describing itself.
 *
 * The `trigger` is the field that earns this class its existence. Everything else here could be
 * derived by reflecting over a directory of notification classes — the name, the recipients, whether
 * it queues — but "what has to happen for somebody to receive this" cannot be. It is a sentence
 * somebody writes, and a catalogue without it is a list of class names, which is worse than no
 * catalogue at all because it looks like documentation.
 */
final class EmailCatalogueEntry
{
    /** Sent by the core, so a self-hosted installation sends it too. */
    public const CORE = 'core';

    /** Sent only where a hosting layer is bound. Nothing in this repository registers one. */
    public const HOSTED = 'hosted';

    private function __construct(
        public readonly string $name,
        public readonly string $trigger,
        public readonly string $recipients,
        public readonly string $edition,
        public readonly ?string $notification,
        public readonly ?EmailCopyTemplate $copy = null,
    ) {}

    /**
     * @param  string  $trigger  what has to happen, in a sentence
     * @param  string  $recipients  who receives it, in the words the settings screens use
     * @param  string|null  $notification  the class, where there is one — several of the core's
     *                                     emails are Mail::raw or come from the framework, and a
     *                                     made-up class name would be worse than an honest null
     * @param  EmailCopyTemplate|null  $copy  the shipped wording, where an operator is allowed to
     *                                        change it. Its presence is what makes an entry
     *                                        editable — see {@see self::editable()}
     */
    public static function core(
        string $name,
        string $trigger,
        string $recipients,
        ?string $notification = null,
        ?EmailCopyTemplate $copy = null,
    ): self {
        return new self($name, $trigger, $recipients, self::CORE, $notification, $copy);
    }

    public static function hosted(
        string $name,
        string $trigger,
        string $recipients,
        ?string $notification = null,
        ?EmailCopyTemplate $copy = null,
    ): self {
        return new self($name, $trigger, $recipients, self::HOSTED, $notification, $copy);
    }

    /**
     * Does this one go through a queue?
     *
     * Derived from the class rather than declared, so it cannot be stated wrongly. An unqueued email
     * sent from inside a webhook turns a mail outage into an outage of whatever called it, so this is
     * worth showing rather than leaving to whoever reads the source.
     *
     * Null where there is no class to ask. That is not "no" — Mail::raw is sent inline and the
     * framework's own notifications are queued or not depending on how they are dispatched — so the
     * screen says nothing rather than guessing.
     */
    public function queued(): ?bool
    {
        if ($this->notification === null) {
            return null;
        }

        return is_a($this->notification, ShouldQueue::class, true);
    }

    /**
     * May an operator change what this one says?
     *
     * Derived from whether there is wording to change, rather than declared beside it, for the same
     * reason {@see self::queued()} is derived: two fields that have to agree eventually do not. An
     * entry marked editable with no template would offer an empty editor, and one carrying a
     * template but marked uneditable would be wording nothing can reach.
     *
     * The emails that deliberately have no template: the monitoring alerts, because every sentence
     * in one is generated from the event that caused it and there is no fixed wording to offer an
     * editor; and the test message, whose whole value is being the same every time.
     */
    public function editable(): bool
    {
        return $this->copy !== null;
    }

    public function key(): ?string
    {
        return $this->copy?->key;
    }

    public function isHosted(): bool
    {
        return $this->edition === self::HOSTED;
    }
}
