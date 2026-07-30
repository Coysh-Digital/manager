<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Backup\BackupKeypair;
use App\Domain\Backup\BackupService;
use App\Domain\Connector\PlatformKeypair;
use App\Models\CapabilityGrant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything `manager:doctor` checks, and the readiness endpoint's subset.
 *
 * These are the checks the specification asks a self-hosted deployment to make, written so that a
 * misconfiguration is discovered by an operator running one command rather than by a connector
 * failing at three in the morning.
 *
 * Each check says what is wrong *and* what to do about it. A diagnostic that reports "storage not
 * writable" without saying which path is a diagnostic somebody has to debug.
 */
final class Diagnostics
{
    public function __construct(
        private readonly PlatformKeypair $keypair,
        private readonly BackupKeypair $backupKeypair,
        private readonly BackupService $backups,
    ) {}

    /**
     * Every check, in the order an operator would want to read them.
     *
     * @return list<Check>
     */
    public function all(): array
    {
        return [
            ...$this->configuration(),
            ...$this->infrastructure(),
            ...$this->security(),
        ];
    }

    /**
     * The subset that determines whether this instance can serve traffic.
     *
     * Deliberately narrow: readiness is polled by orchestrators, and a probe that fails because
     * mail has not been verified would take a working instance out of rotation for no good reason.
     *
     * @return list<Check>
     */
    public function readiness(): array
    {
        return [
            $this->database(),
            $this->migrations(),
            $this->nonceStore(),
            $this->storageWritable(),
        ];
    }

    /**
     * @return list<Check>
     */
    private function configuration(): array
    {
        $checks = [];

        $appKey = (string) config('app.key');

        $checks[] = $appKey === ''
            ? Check::fail('Application key', 'Not set.', 'Run: php artisan key:generate')
            : Check::pass('Application key', 'Set.');

        $checks[] = config('app.debug') && app()->environment('production')
            ? Check::fail(
                'Debug mode',
                'APP_DEBUG is on in production, so exceptions will render internal detail to visitors.',
                'Set APP_DEBUG=false and clear the config cache.',
            )
            : Check::pass('Debug mode', app()->environment('production') ? 'Off in production.' : 'On outside production, which is fine.');

        $url = (string) config('app.url');

        if ($url === '' || $url === 'http://localhost') {
            $checks[] = Check::fail(
                'Public URL',
                'APP_URL is unset or still the default.',
                'Set APP_URL to the address browsers and connectors actually use. Cookie security and callback links depend on it.',
            );
        } elseif (! str_starts_with($url, 'https://') && app()->environment('production')) {
            $checks[] = Check::fail(
                'Public URL',
                "APP_URL is {$url}, which is not HTTPS.",
                'Terminate TLS in front of Manager. Signed requests protect integrity, not confidentiality.',
            );
        } else {
            $checks[] = Check::pass('Public URL', $url);
        }

        // A wildcard would let any caller set X-Forwarded-For, defeating the per-network rate
        // limits and the source addresses in the audit log.
        $proxies = (string) config('manager.trusted_proxies');

        $checks[] = match (true) {
            trim($proxies) === '*' => Check::fail(
                'Trusted proxies',
                'Set to "*", so any caller can forge its apparent source address.',
                'List the actual proxy addresses or CIDR ranges in MANAGER_TRUSTED_PROXIES.',
            ),
            $proxies === '' => Check::pass('Trusted proxies', 'None configured, so forwarded headers are ignored.'),
            default => Check::pass('Trusted proxies', $proxies),
        };

        return $checks;
    }

    /**
     * @return list<Check>
     */
    private function infrastructure(): array
    {
        return [
            $this->database(),
            $this->migrations(),
            $this->nonceStore(),
            $this->storageWritable(),
            $this->queue(),
        ];
    }

    private function database(): Check
    {
        try {
            $version = (string) DB::selectOne('select version() as v')->v;

            if (DB::connection()->getDriverName() !== 'pgsql') {
                return Check::fail(
                    'Database',
                    'Not PostgreSQL.',
                    'The audit log relies on a trigger and on privileges that are not portable. Use PostgreSQL.',
                );
            }

            return Check::pass('Database', explode(' (', $version)[0]);
        } catch (Throwable $e) {
            return Check::fail('Database', 'Cannot connect.', 'Check DB_HOST, DB_DATABASE and credentials.');
        }
    }

    private function migrations(): Check
    {
        try {
            $repository = app('migration.repository');

            if (! $repository->repositoryExists()) {
                return Check::fail('Migrations', 'Never run on this database.', 'Run: php artisan migrate --force');
            }

            // Compared by file name against the ledger, rather than through the migrator, which
            // needs a resolved connection and reports differently between Laravel versions.
            $applied = $repository->getRan();

            $onDisk = array_map(
                static fn (string $path): string => basename($path, '.php'),
                (array) glob(database_path('migrations/*.php')),
            );

            $outstanding = array_diff($onDisk, $applied);

            return $outstanding === []
                ? Check::pass('Migrations', count($applied).' applied, none pending.')
                : Check::fail('Migrations', count($outstanding).' pending.', 'Run: php artisan migrate --force');
        } catch (Throwable $e) {
            return Check::fail('Migrations', 'Cannot determine migration state.', 'Check the database connection.');
        }
    }

    /**
     * Replay protection has to be shared and atomic across every worker.
     */
    private function nonceStore(): Check
    {
        $store = (string) config('manager.connector.nonce_store');

        // An in-process store would let a replay through on a second worker, and a file store
        // cannot make the claim atomic. Either would silently weaken replay protection.
        if (in_array($store, ['array', 'file', 'null'], true)) {
            return Check::fail(
                'Replay-protection store',
                "Configured as '{$store}', which is neither shared nor atomic.",
                'Set MANAGER_NONCE_STORE to redis. A non-atomic store lets a captured request be replayed on another worker.',
            );
        }

        try {
            $probe = 'manager:doctor:'.bin2hex(random_bytes(8));

            $first = Cache::store($store)->add($probe, true, 10);
            $second = Cache::store($store)->add($probe, true, 10);

            Cache::store($store)->forget($probe);

            return $first && ! $second
                ? Check::pass('Replay-protection store', "{$store}, atomic add confirmed.")
                : Check::fail(
                    'Replay-protection store',
                    "'{$store}' did not behave atomically.",
                    'A second add of the same key succeeded, which means a replay would be accepted.',
                );
        } catch (Throwable $e) {
            return Check::fail(
                'Replay-protection store',
                "Cannot reach '{$store}'.",
                'Connector requests will be rejected until this is reachable: replay protection fails closed.',
            );
        }
    }

    private function storageWritable(): Check
    {
        try {
            $probe = 'doctor-'.bin2hex(random_bytes(6)).'.txt';

            Storage::disk('local')->put($probe, 'ok');
            $readable = Storage::disk('local')->get($probe) === 'ok';
            Storage::disk('local')->delete($probe);

            if (! $readable) {
                return Check::fail('Storage', 'Wrote a file but could not read it back.', 'Check the local disk configuration.');
            }

            // The local disk must not be reachable over HTTP. Anything Manager writes there is
            // operational, and from Phase 3 it will hold backup artifacts.
            $publicPath = public_path('storage');
            $exposed = is_link($publicPath) && str_contains((string) readlink($publicPath), 'app/private') === false
                && str_contains((string) readlink($publicPath), storage_path('app'));

            return $exposed
                ? Check::warn('Storage', 'Writable, but storage/app is symlinked into the web root.', 'Remove public/storage unless you are deliberately serving files from it.')
                : Check::pass('Storage', 'Writable and not served over HTTP.');
        } catch (Throwable $e) {
            return Check::fail('Storage', 'Not writable.', 'Check permissions on storage/ and bootstrap/cache/.');
        }
    }

    private function queue(): Check
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return Check::warn(
                'Queue',
                'Running synchronously, so nothing is processed in the background.',
                'Set QUEUE_CONNECTION=redis and run a worker.',
            );
        }

        return Check::pass('Queue', "Connection: {$connection}.");
    }

    /**
     * @return list<Check>
     */
    private function security(): array
    {
        $checks = [];

        $checks[] = $this->keypair->isConfigured()
            ? Check::pass('Platform signing key', 'Configured.')
            : Check::fail(
                'Platform signing key',
                'Not configured, so the platform cannot sign responses to connectors.',
                'Run: php artisan manager:keys:generate',
            );

        // Setup closing is load-bearing: while it is open, anyone who can reach the installation
        // can create the first owner.
        $checks[] = User::query()->exists()
            ? Check::pass('First-run setup', 'Closed: an account exists.')
            : Check::warn(
                'First-run setup',
                'Still open. Anyone who can reach this installation can create the first owner.',
                'Complete setup at /setup, or restrict access until you have.',
            );

        $checks[] = $this->auditTriggers();
        $checks[] = $this->databaseRole();
        $checks[] = $this->backupEncryption();

        $checks[] = config('session.secure') || ! str_starts_with((string) config('app.url'), 'https://')
            ? Check::pass('Session cookie', config('session.secure') ? 'Marked secure.' : 'Not secure, matching a non-HTTPS URL.')
            : Check::warn(
                'Session cookie',
                'APP_URL is HTTPS but the session cookie is not marked secure.',
                'Set SESSION_SECURE_COOKIE=true so the cookie is never sent over plain HTTP.',
            );

        $checks[] = config('manager.diagnostics.enabled')
            ? Check::warn('Optional diagnostics', 'Enabled. It carries no site content or secrets, and can be turned off at any time.')
            : Check::pass('Optional diagnostics', 'Disabled, which is the default.');

        return $checks;
    }

    /**
     * Whether backups can actually be taken, and whether they can be read back.
     *
     * Reported as a failure only when a site has been granted the permission, because a platform nobody
     * has asked to take backups does not need a backup key. A warning otherwise, so the gap is visible
     * before somebody grants the permission rather than after the first job fails.
     */
    private function backupEncryption(): Check
    {
        $granted = CapabilityGrant::query()
            ->where('capability', 'backups:create')
            ->where('state', CapabilityGrant::STATE_GRANTED)
            ->count();

        if (! $this->backupKeypair->isConfigured()) {
            $detail = $granted > 0
                ? "Not configured, and {$granted} ".($granted === 1 ? 'site has' : 'sites have')
                    .' permission to back up. No backup will be taken until it is: a connector without a '
                    .'key refuses rather than uploading a database in the clear.'
                : 'Not configured. No site has permission to back up yet, so nothing is failing.';

            return $granted > 0
                ? Check::fail('Backup encryption key', $detail, 'Run: php artisan manager:backups:keygen')
                : Check::warn('Backup encryption key', $detail, 'Run: php artisan manager:backups:keygen before granting backups:create');
        }

        // Configured. Say plainly what it means rather than leaving somebody to infer end-to-end
        // encryption from the word "encrypted".
        return Check::pass(
            'Backup encryption key',
            'Configured, storing to '.$this->backups->describeStorage()
            .'. Whoever holds this key can read every stored backup, so it is not end-to-end encrypted.',
        );
    }

    private function auditTriggers(): Check
    {
        try {
            $triggers = DB::select(
                'SELECT tgname FROM pg_trigger WHERE tgrelid = :t::regclass AND NOT tgisinternal',
                ['t' => 'audit_events'],
            );

            $names = array_map(fn (object $row): string => $row->tgname, $triggers);

            $expected = ['audit_events_reject_mutation', 'audit_events_reject_truncate'];
            $missing = array_diff($expected, $names);

            return $missing === []
                ? Check::pass('Audit log protection', 'Both append-only triggers present.')
                : Check::fail(
                    'Audit log protection',
                    'Missing: '.implode(', ', $missing).'.',
                    'The audit log can be edited without them. Re-run migrations.',
                );
        } catch (Throwable $e) {
            return Check::fail('Audit log protection', 'Cannot inspect triggers.', 'Check the database connection.');
        }
    }

    /**
     * A superuser bypasses privilege checks entirely, including the ones protecting the audit log.
     */
    private function databaseRole(): Check
    {
        try {
            $isSuperuser = (bool) DB::selectOne('SELECT usesuper FROM pg_user WHERE usename = current_user')?->usesuper;

            if (! $isSuperuser) {
                return Check::pass('Database role', 'Not a superuser.');
            }

            return app()->environment('production')
                ? Check::warn(
                    'Database role',
                    'Manager connects as a superuser, which bypasses table privileges.',
                    'Create a role without UPDATE or DELETE on audit_events. The append-only trigger still holds, but this removes a layer.',
                )
                : Check::pass('Database role', 'Superuser, which is expected outside production.');
        } catch (Throwable $e) {
            return Check::warn('Database role', 'Could not determine privileges.');
        }
    }

    /**
     * @param  list<Check>  $checks
     */
    public static function hasFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check->failed()) {
                return true;
            }
        }

        return false;
    }
}
