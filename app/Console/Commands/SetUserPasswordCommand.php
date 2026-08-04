<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sets a user's password from the command line.
 *
 * This exists because a self-hosted installation can otherwise lock its owner out permanently. The
 * setup route closes as soon as an account exists, and the password reset flow needs working mail - so
 * an operator who loses the only owner password on an installation without SMTP configured has no way
 * back in at all. That is not an acceptable state for software somebody runs themselves.
 *
 * It grants no new authority. Anyone who can run this already has shell access to the container, and
 * therefore APP_KEY and the database, and therefore the installation. What it does is make an authority
 * they already hold usable without hand-editing a password hash.
 *
 * The second factor is deliberately **not** cleared. Resetting a password and removing multi-factor
 * authentication are different acts with different consequences, and rolling them together would make
 * this a one-command MFA bypass. Losing a TOTP device is a real situation, so there is a separate flag
 * for it that says what it is doing and audits it separately.
 */
final class SetUserPasswordCommand extends Command
{
    protected $signature = 'manager:user:password
                            {email : The account to change}
                            {--generate : Generate a strong password and print it}
                            {--reset-second-factor : Also remove the second factor, if one is enrolled}';

    protected $description = 'Set a user\'s password, for when nobody can get in';

    public function handle(AuditRecorder $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Not a login form, so there is no reason to be vague. Being unhelpful here would only
            // obstruct the operator this command exists for.
            $this->components->error("No account with the address {$email}.");

            $known = User::query()->orderBy('id')->pluck('email');

            if ($known->isNotEmpty()) {
                $this->line('  Accounts on this installation:');

                foreach ($known as $address) {
                    $this->line("    {$address}");
                }
            }

            return self::FAILURE;
        }

        $password = $this->option('generate')
            ? Str::password(24)
            : (string) $this->secret('New password (not echoed)');

        if (! $this->option('generate')) {
            if (strlen($password) < 12) {
                $this->components->error('Too short. Use at least 12 characters.');

                return self::FAILURE;
            }

            if ($password !== (string) $this->secret('Confirm it')) {
                $this->components->error('Those did not match. Nothing changed.');

                return self::FAILURE;
            }
        }

        $hadSecondFactor = $user->hasConfirmedTotp();
        $clearing = (bool) $this->option('reset-second-factor') && $hadSecondFactor;

        $attributes = ['password' => Hash::make($password)];

        // Added only when explicitly asked for. Clearing these as a side effect of a password change
        // would turn this command into a way to strip multi-factor authentication from an account.
        if ($clearing) {
            $attributes['totp_secret'] = null;
            $attributes['totp_confirmed_at'] = null;
        }

        $user->forceFill($attributes)->save();

        $organisation = Membership::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->with('organisation')
            ->first()?->organisation;

        $audit->record(
            action: 'user.password_set_from_console',
            organisation: $organisation,
            actor: $user,
            actorType: AuditEvent::ACTOR_SYSTEM,
            actorLabel: 'Console',
            targetType: 'user',
            targetId: (string) $user->external_id,
            // No password, no hash. That the change happened, and whether the second factor went with
            // it, is what an operator needs to see in the log afterwards.
            after: [
                'second_factor_cleared' => $clearing,
                'had_second_factor' => $hadSecondFactor,
            ],
        );

        $this->components->info("Password set for {$user->email}.");

        if ($this->option('generate')) {
            $this->newLine();
            $this->line("  {$password}");
            $this->newLine();
            $this->components->warn('Shown once. Change it after logging in.');
        }

        if ($clearing) {
            $this->components->warn(
                'The second factor was removed. Enrol a new one immediately - until you do, this '
                .'account is protected by a password alone.'
            );
        } elseif ($hadSecondFactor) {
            $this->line('  The second factor is untouched; you will still be asked for it.');
        }

        return self::SUCCESS;
    }
}
