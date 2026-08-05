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
    ) {}

    /**
     * @param  string  $trigger  what has to happen, in a sentence
     * @param  string  $recipients  who receives it, in the words the settings screens use
     * @param  string|null  $notification  the class, where there is one — several of the core's
     *                                     emails are Mail::raw or come from the framework, and a
     *                                     made-up class name would be worse than an honest null
     */
    public static function core(
        string $name,
        string $trigger,
        string $recipients,
        ?string $notification = null,
    ): self {
        return new self($name, $trigger, $recipients, self::CORE, $notification);
    }

    public static function hosted(
        string $name,
        string $trigger,
        string $recipients,
        ?string $notification = null,
    ): self {
        return new self($name, $trigger, $recipients, self::HOSTED, $notification);
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

    public function isHosted(): bool
    {
        return $this->edition === self::HOSTED;
    }
}
