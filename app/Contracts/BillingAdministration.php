<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Where somebody goes to manage what they pay, if anybody pays for this installation at all.
 *
 * Nobody bills a self-hosted install. You already have the source, you are running it on your own
 * infrastructure, and there is no subscription, no card and no invoice - so the honest answer here
 * is null, and every part of the interface that would link to one stays absent rather than
 * appearing greyed out or leading somewhere that explains why it does not apply.
 *
 * A hosted edition answers with a URL, and the core links to it without knowing anything else: not
 * the price, not the plan, not what happens when somebody gets there. It cannot know. The screen on
 * the other end of that link belongs to whoever runs the service, and a core that knew what it cost
 * would be a core with an edition.
 *
 * One method rather than a `managedExternally(): bool` beside it, deliberately. Two answers can
 * disagree - "billing is managed elsewhere" with nowhere to send anybody is a section rendering a
 * dead link - and the pair would need a docblock contract to stop them. The presence of a URL is
 * the whole gate, and it cannot contradict itself.
 *
 * A seam rather than a config key, for the same reason as the others: an installation should behave
 * the way it does because of what is wired into it, not because of a string somebody set.
 * {@see ProductLabel} is display only and explicitly may not be branched on, so this is its own
 * contract rather than a second use of that one.
 */
interface BillingAdministration
{
    /**
     * An absolute URL where somebody may manage payment, or null when nobody bills this
     * installation.
     *
     * Absolute, because callers compare it against the current request to decide whether they are
     * looking at it. Null rather than an empty string so that "there is no billing here" cannot be
     * mistaken for a URL somebody forgot to fill in.
     */
    public function url(): ?string;
}
