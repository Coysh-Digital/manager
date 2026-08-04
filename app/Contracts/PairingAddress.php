<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The address a managed site should pair against, when this installation publishes one.
 *
 * Self-hosted there is nothing to publish. The operator chose the address themselves, it is whatever
 * they put in front of this application, and Manager genuinely does not know it — `APP_URL` is what
 * it was told to generate links with, not a promise about what a Craft site somewhere else can
 * reach. Saying "pair against ..." on a screen would be guessing at the reader's own infrastructure
 * and getting it wrong in front of them.
 *
 * A hosted edition is the opposite case: it does know, because it published the name. And it needs
 * to say so, because it does not necessarily serve connector traffic on the address the reader is
 * looking at. A backup is one request carrying an entire encrypted database; the hostname a browser
 * reaches a control panel on is usually the one behind a proxy, and a proxy caps a request body. A
 * site paired against it reports normally and then fails every backup with a refusal raised in front
 * of the platform — no correlation identifier, nothing in the log, and only after the database has
 * been dumped, encrypted and hashed.
 *
 * So this is deliberately *not* "what host am I on". It is "what address has whoever runs this
 * installation published for connectors", which is a fact only they hold, and null is the honest
 * answer nearly everywhere.
 *
 * A seam rather than a config key, for the same reason as the rest of them: a claim about what an
 * installation is should follow from what is wired into it, not from a value somebody could set by
 * accident. {@see ServerAccess} is the closest neighbour and answers a different question — whether
 * the reader can reach the machine *Manager* runs on. This one is about the machine their *site*
 * runs on, and where it should send its traffic.
 */
interface PairingAddress
{
    /**
     * The base URL a site should pair against, or null when this installation publishes none.
     *
     * Scheme and host, no trailing slash and no path. Null means "say nothing" rather than "use the
     * current host" — a screen must render without the sentence at all, not with a guess in it.
     */
    public function url(): ?string;
}
