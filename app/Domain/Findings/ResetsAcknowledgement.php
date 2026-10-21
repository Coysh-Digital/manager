<?php

declare(strict_types=1);

namespace App\Domain\Findings;

/**
 * A rule whose finding can stop being about the thing somebody acknowledged while it stays true.
 *
 * An acknowledgement is a statement about one problem: "we know, it is deliberate here". Normally the
 * finding resolving is what ends it, and the next occurrence starts with none. But a rule can keep
 * matching across a change that makes it a different problem - a certificate that is replaced by
 * another which is also inside the warning window, say, as happens every cycle with a short-lived
 * one. Left alone, the acknowledgement quietly covers a certificate nobody has looked at.
 *
 * Optional, and implemented by few rules on purpose. Most findings are the same problem for as long
 * as they match, and clearing an acknowledgement is how a list teaches people to stop making them.
 */
interface ResetsAcknowledgement
{
    /**
     * Whether the finding's subject is no longer the one it was when it was last evidenced.
     *
     * Called only while the finding is acknowledged and the rule still matches. A false answer, or a
     * missing field on either side, keeps the acknowledgement: the evidence has to say it changed.
     *
     * @param  array<string, mixed>  $previousEvidence  what the outstanding finding holds now
     * @param  array<string, mixed>  $evidence  what this evaluation produced
     */
    public function subjectChanged(array $previousEvidence, array $evidence): bool;
}
