<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Organisation;
use App\Support\SelfHosted\NullProvisioner;

/**
 * Creates the resources a new organisation needs.
 *
 * Self-hosted has nothing to provision: the database is already there, the backup disk is already
 * configured in the environment, and there is no billing record because nobody is being billed.
 * {@see NullProvisioner} therefore does nothing, and is called anyway, so
 * that this is a seam an installation can actually use rather than a description of software that
 * is not in this repository.
 *
 * There is no deprovision method. This application has no way to delete an organisation, and it
 * cannot grow one cheaply: audit_events.organisation_id is restrictOnDelete precisely so the record
 * of what happened outlives whoever it happened to. A method that could never be called from here
 * would be the same empty promise this interface exists to avoid, so an edition that needs one
 * declares it itself.
 */
interface Provisioner
{
    public function provision(Organisation $organisation): void;
}
