<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Organisation;

/**
 * Creates the resources a new organisation needs.
 *
 * Self-hosted has one organisation and nothing to provision. Cloud allocates storage prefixes,
 * quotas and billing records. Keeping it behind an interface is what stops cloud-only concerns
 * leaking into the core — see the note in the workspace README about cloud staying thin.
 */
interface Provisioner
{
    public function provision(Organisation $organisation): void;

    /**
     * Release what provisioning allocated. Must be safe to call more than once.
     */
    public function deprovision(Organisation $organisation): void;
}
