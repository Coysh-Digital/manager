<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Models\MailSetting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Puts the stored mail configuration into force, at the moment mail is about to be sent.
 *
 * The environment is never written to. That is not an implementation detail, it is the design:
 *
 *  - `.env` would need a `config:cache` to take effect, and the Docker entrypoint caches config at
 *    boot, before `migrate` - so a value written at runtime would be read by nothing.
 *  - It would be lost on the next container start, because the image bakes the file.
 *  - It would put a relay password in a file on disk beside everything else.
 *  - And, most of all: because the environment is untouched, discarding the stored configuration is
 *    a complete revert. That is what makes a saved-but-broken relay recoverable from the interface
 *    by somebody with no shell, which matters here more than anywhere else in this application —
 *    the one thing you cannot use to tell somebody their mail is broken is email.
 *
 * So this mutates the already-loaded config repository instead, which the config cache can neither
 * see nor invalidate, and does it at send time rather than at boot.
 *
 * @see ConfiguredMailManager for where it is called from, and why that is the only place it needs
 *      to be called from.
 */
final class MailConfiguration
{
    private bool $applied = false;

    /**
     * What the environment said, captured before anything was written over it.
     *
     * @var array<string, mixed>|null
     */
    private ?array $environment = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Push the stored configuration into the live config repository.
     *
     * Idempotent and memoised: at most one query per process, and none at all in a process that
     * never sends mail, because the only caller is the mail manager resolving a mailer.
     */
    public function apply(): void
    {
        if ($this->applied) {
            return;
        }

        // Set before the work rather than after it. A configuration that throws on the way in must
        // not be retried on every subsequent send; the environment stands, and the health check on
        // the Settings screen is what says so.
        $this->applied = true;

        /*
         | Snapshotted the first time, and only the first time.
         |
         | Without this, discarding the stored configuration would not be a revert: deleting the row
         | leaves its last values sitting in the config repository for the rest of the process, and
         | in a queue worker the rest of the process is the rest of the hour. Taken before anything
         | is written, which is the only moment it is still true.
         */
        $this->environment ??= $this->snapshot();

        config($this->environment);

        $stored = $this->stored();

        if ($stored === null) {
            return;
        }

        config($stored->toConfig());

        /*
         | A mailer resolved earlier in this process was built from what is now the wrong config —
         | MailManager caches them by name, and the global From address is set once at resolve time
         | rather than read per message.
         |
         | Guarded on resolved(), so this never forces the deferred MailServiceProvider to load
         | merely to ask it to forget something it has not built.
         */
        if ($this->app->resolved('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }
    }

    /**
     * Read it again on the next send.
     *
     * Two callers. The controller, so that saving takes effect for the rest of the request —
     * including the test send sitting next to the save button, which would otherwise prove the
     * previous configuration works. And a queue worker, per job: `queue:work` is a long-lived
     * process that does not reboot between jobs, so a configuration pushed in at the first send
     * would still be in force an hour after somebody changed it.
     */
    public function markStale(): void
    {
        $this->applied = false;
    }

    /**
     * The stored configuration, or null when the environment is the answer.
     */
    public function stored(): ?MailSetting
    {
        try {
            /*
             | Asked rather than assumed. `key:generate`, `config:cache` and `manager:doctor` all run
             | before `migrate` on a first install - see deploy/docker/entrypoint.sh - and an
             | invitation email must not become a 500 because a table is one deploy behind.
             |
             | hasTable rather than catching the query error, because in Postgres a failed statement
             | poisons the surrounding transaction, and mail does get sent inside one.
             */
            if (! Schema::hasTable('mail_settings')) {
                return null;
            }

            return MailSetting::query()->first();
        } catch (Throwable) {
            // No database reachable at all. The environment is the answer, which is what it was
            // before this table existed.
            return null;
        }
    }

    /**
     * The environment's value for every key the stored configuration can write.
     *
     * Derived from an empty model rather than from a hand-written list, so the two cannot drift: a
     * key added to toConfig() is snapshotted by having been added.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $keys = array_keys((new MailSetting)->toConfig());

        return array_combine($keys, array_map(static fn (string $key) => config($key), $keys));
    }
}
