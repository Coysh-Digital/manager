<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The small word under the wordmark.
 *
 * A seam rather than a config key, and that distinction is the whole reason this interface exists.
 * The core has no notion of an edition - there is a test asserting it - because an installation that
 * *claims* to be something is a claim, whereas an installation with a different implementation bound
 * is a fact. This is the same shape as every other contract here: the self-hosted answer is the
 * default, and a hosting layer that is actually installed and active replaces it.
 *
 * It is display only. Nothing may branch on this value, and nothing does - a label that started
 * gating behaviour would be `config('manager.edition')` wearing a hat.
 */
interface ProductLabel
{
    /**
     * What this installation calls itself, in sentence case. Rendered uppercase by CSS.
     */
    public function label(): string;
}
