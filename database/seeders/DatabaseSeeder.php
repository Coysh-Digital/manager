<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * This seeder creates nothing, deliberately.
 *
 * It arrived from the Laravel skeleton creating a "Test User" at test@example.com through
 * UserFactory, whose default password is the string "password". On a control plane holding the keys
 * to every managed installation, that is not a placeholder - it is an account with a known password,
 * created by a command an operator has every reason to believe is routine.
 *
 * The second half is worse and less obvious. First-run setup is gated by EnsureSetupIsAvailable,
 * which returns 404 as soon as *any* user exists - the route stops existing rather than redirecting,
 * so there is nothing to probe for and nothing to re-enter. That is the right design, and it makes
 * this seeder a one-way door: `php artisan db:seed` on a fresh install plants an account nobody
 * chose and closes the screen the real first account is supposed to be created on, permanently. The
 * recovery is to delete the row by hand from the database, having first worked out that a seeder was
 * the cause.
 *
 * Nothing in this repository referenced it - no composer script, no CI step, no documented install
 * path. It was reachable only by somebody typing the command Laravel taught them to type.
 *
 * So there is no seed data. The first account is created through the setup screen, which is the path
 * that is actually tested and the one the documentation describes. If you want a user for local
 * development, make one there, or use `php artisan tinker`.
 *
 * tests/Invariants/SeederTest.php asserts this file creates no user, so putting one back is a
 * deliberate act rather than an accident.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
