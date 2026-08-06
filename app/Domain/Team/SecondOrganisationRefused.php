<?php

declare(strict_types=1);

namespace App\Domain\Team;

use App\Http\Middleware\ResolveOrganisation;
use RuntimeException;

/**
 * An account may reach one organisation, and somebody tried to give it a second.
 *
 * This is a limitation rather than a rule, and the distinction is worth keeping in front of whoever
 * reads it next. {@see ResolveOrganisation} takes the lowest-id live membership
 * and there is no switcher anywhere, so a second membership grants nothing: it writes a row that
 * makes the team screen report access while the person carries on seeing the organisation they
 * joined first. The invitation email arrives, the account works, and it shows them somewhere else.
 *
 * The failure was silent in the direction that matters. Nothing the inviting owner could see said
 * anything had gone wrong - which is the ordinary agency case for this product, one person working
 * across two clients, and it is the case that fails.
 *
 * So the second membership is refused until there is somewhere for it to go. When an organisation
 * switcher exists this class is what should be deleted, and the two places that raise it are the two
 * places that need revisiting.
 */
final class SecondOrganisationRefused extends RuntimeException {}
