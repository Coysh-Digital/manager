<?php

declare(strict_types=1);

namespace App\Support\SelfHosted;

use App\Contracts\Provisioner;
use App\Models\Organisation;

/**
 * Provisioning for an installation that has nothing to provision.
 *
 * A self-hosted installation has one organisation, and everything it needs already exists: the
 * database is the operator's, the backup disk is configured in the environment, and there is no
 * billing record because nobody is being billed. So this does nothing, on purpose.
 *
 * It exists so that {@see Provisioner} is a real seam rather than a promise. An interface with no
 * implementation and no call site is not an extension point, it is a description of software the
 * reader cannot see, which is the exact leak the interface was written to prevent. With this bound
 * and called from setup, a self-hosted operator has a documented place to hook organisation
 * creation, and the Cloud edition replaces it rather than inventing the call site.
 */
final class NullProvisioner implements Provisioner
{
    public function provision(Organisation $organisation): void
    {
        //
    }
}
