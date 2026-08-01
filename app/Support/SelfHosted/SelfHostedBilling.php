<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\BillingAdministration;

/**
 * The self-hosted answer: nobody bills you, so there is nowhere to send you.
 *
 * There is nothing to decide here. This repository is the product people run on their own
 * infrastructure, free — there is no subscription to manage, no card to update and no invoice to
 * fetch. Null is not a placeholder for a URL that will arrive later; it is the fact.
 */
final class SelfHostedBilling implements BillingAdministration
{
    public function url(): ?string
    {
        return null;
    }
}
