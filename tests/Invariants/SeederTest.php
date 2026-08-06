<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

/*
 | Seeding must not create an account, and must not close first-run setup.
 |
 | The Laravel skeleton's DatabaseSeeder creates a "Test User" at test@example.com through
 | UserFactory, whose default password is the string "password". On a control plane holding the keys
 | to every managed installation that is an account with a known password, created by a command an
 | operator has every reason to think is routine.
 |
 | The second effect is the one that has no undo. EnsureSetupIsAvailable returns 404 as soon as *any*
 | user exists - the setup route stops existing rather than redirecting, which is the right design
 | and is what makes this a one-way door. `php artisan db:seed` on a fresh install would plant an
 | account nobody chose and permanently close the screen the first real account is created on, with
 | the only recovery being to find and delete the row by hand.
 |
 | Nothing in the repository referenced the seeder - no composer script, no CI step, no documented
 | install path - so it was reachable only by somebody typing the command Laravel taught them to
 | type. That is exactly the kind of thing a test has to hold, because nothing else was going to.
 |
 | Asserted by running it rather than by reading the file, so a user created indirectly - through a
 | call to another seeder, a factory in a callback, an observer - is caught just the same.
 */

it('creates no account, so first-run setup stays open', function (): void {
    expect(User::query()->count())->toBe(0);

    (new DatabaseSeeder)->setContainer(app())->run();

    expect(User::query()->count())->toBe(0);
});

it('leaves the setup route reachable after seeding', function (): void {
    // The consequence, asserted as a consequence rather than inferred from the count above. If
    // somebody puts a user back in the seeder, this is the failure that explains why it matters.
    (new DatabaseSeeder)->setContainer(app())->run();

    $this->get(route('setup'))->assertOk();
});
