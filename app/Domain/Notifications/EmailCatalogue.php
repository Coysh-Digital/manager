<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Notifications\TeamInvitation;

/**
 * Every email this installation can send, and what triggers each one.
 *
 * Deliberately a registry rather than a contract in `app/Contracts`. Every entry in that directory
 * answers a policy question with exactly one answer per installation — who holds the relay, where
 * artifacts go, who bills this — and the two editions genuinely disagree. A catalogue is not that
 * shape: it is the *union* of what both editions can send, so a hosted implementation would have to
 * return the core's list plus its own, either duplicating it or wrapping it. That is composition, and
 * composition is what an object you can append to is for.
 *
 * The second reason matters more. A contract returning an opaque array cannot be checked against
 * anything, and completeness is the only property this screen has that is worth having. Concrete
 * entries in a registry can be compared with a directory listing, which is what
 * `tests/Invariants/EmailCatalogueTest.php` does and what the hosting layer's own suite does for its
 * half.
 *
 * A hosting layer appends to this from its service provider's `boot()` — not `register()`, because a
 * package provider registers before the application's own and this singleton may not exist yet.
 * Nothing in this repository knows or cares whether anything does.
 */
final class EmailCatalogue
{
    /** @var list<EmailCatalogueEntry> */
    private array $entries = [];

    public function __construct()
    {
        $this->seedCoreEntries();
    }

    public function add(EmailCatalogueEntry ...$entries): void
    {
        foreach ($entries as $entry) {
            $this->entries[] = $entry;
        }
    }

    /**
     * Everything registered, core first.
     *
     * Stable ordering, so the screen does not rearrange itself depending on when a provider happened
     * to boot.
     *
     * @return list<EmailCatalogueEntry>
     */
    public function all(): array
    {
        $sorted = $this->entries;

        usort($sorted, static function (EmailCatalogueEntry $a, EmailCatalogueEntry $b): int {
            return [$a->isHosted(), $a->name] <=> [$b->isHosted(), $b->name];
        });

        return $sorted;
    }

    /** @return list<EmailCatalogueEntry> */
    public function hosted(): array
    {
        return array_values(array_filter($this->all(), static fn (EmailCatalogueEntry $e): bool => $e->isHosted()));
    }

    /**
     * What the core itself sends.
     *
     * The monitoring alerts are derived from {@see NotificationEvent::catalogue()} rather than listed
     * again here, for the same reason the settings screen renders `Diagnostics` rather than restating
     * its checks: two lists of the same thing eventually disagree, and the one nobody is looking at
     * is the one that goes stale. Adding an event type there puts it on this screen with no second
     * edit and no possibility of omission.
     */
    private function seedCoreEntries(): void
    {
        foreach (NotificationEvent::catalogue() as $type => $label) {
            $this->add(EmailCatalogueEntry::core(
                name: $label,
                trigger: "The `{$type}` event fires, and a destination subscribes to it.",
                recipients: 'Every email destination in Settings → Notifications subscribed to this event.',
            ));
        }

        $this->add(
            EmailCatalogueEntry::core(
                name: 'Password reset',
                trigger: 'Somebody asks for a reset link from the sign-in screen.',
                recipients: 'The address the reset was requested for, if an account exists.',
            ),
            EmailCatalogueEntry::core(
                name: 'Invitation',
                trigger: 'An owner or administrator invites somebody in Settings → People, or resends an invitation.',
                recipients: 'The person being invited.',
                notification: TeamInvitation::class,
            ),
            EmailCatalogueEntry::core(
                name: 'Test message',
                trigger: 'An owner presses Send test on the mail settings screen, or an operator runs the mail test command.',
                recipients: 'Whoever pressed the button. The screen offers no destination field on purpose.',
            ),
        );
    }
}
