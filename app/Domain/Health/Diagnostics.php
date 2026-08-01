<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Contracts\MailAdministration;
use App\Domain\Backup\BackupKeypair;
use App\Domain\Backup\BackupService;
use App\Domain\Connector\PlatformKeypair;
use App\Domain\Notifications\OutboundUrlGuard;
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
        private readonly OutboundUrlGuard $outboundUrls,
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
            ...$this->backupDestination(),
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
            $this->mail(),
        ];
    }

    /**
     * Whether outbound mail is configured at all.
     *
     * Not in {@see readiness()}, deliberately, for the reason given there: an orchestrator must not
     * pull a working instance out of rotation because nobody set up a mail relay.
     *
     * Reports a status and no values. Everything this reads comes from the environment, and an
     * operator who can see this screen may not be the operator who holds the credentials — so the
     * host, the port, the username and the from-address are all things this check knows and never
     * says. What matters is the answer to "will a password reset arrive", and that is a yes or a no.
     */
    private function mail(): Check
    {
        $mailer = (string) config('mail.default');

        /*
         | Every remedy below names an environment variable, which is the right instruction for the
         | person who holds the environment and useless to anybody else. On a hosted edition the
         | relay belongs to whoever runs the service, so the check still reports whether mail works
         | — that is worth knowing either way — and stops telling the reader to go and fix it.
        */
        $operatorManaged = app(MailAdministration::class)->operatorManaged();

        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return Check::warn(
                'Mail',
                'No mail transport is configured, so nothing is delivered.',
                $operatorManaged
                    ? 'Set MAIL_MAILER and the matching MAIL_* variables. Password resets, invitations and '
                        .'notification emails cannot reach anybody until you do. See docs/env.md.'
                    : null,
            );
        }

        if (! is_string(config('mail.from.address')) || config('mail.from.address') === '') {
            return Check::warn(
                'Mail',
                'A transport is configured but no sender address is set.',
                $operatorManaged
                    ? 'Set MAIL_FROM_ADDRESS. Most relays reject a message with no envelope sender.'
                    : null,
            );
        }

        // The second sentence names a button that is not on every edition's screen. Pointing at a
        // control the reader cannot see is worse than stopping at the fact.
        return Check::pass('Mail', $operatorManaged
            ? 'A transport and a sender address are configured. Send a test from Settings to prove delivery.'
            : 'A transport and a sender address are configured.');
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

    /**
     * Where backups are being written, and whether that destination is somewhere sensible.
     *
     * An operator running self-hosted may point `MANAGER_BACKUP_DRIVER` at any S3-compatible service,
     * which is how "bring your own bucket" works here — and a custom endpoint is a URL this
     * application will connect to, so it gets the same scrutiny a webhook destination does.
     *
     * The endpoint is checked against {@see OutboundUrlGuard}: HTTPS only, and no loopback,
     * link-local, private-range or metadata address. `169.254.169.254` is the one that matters — an
     * endpoint pointed there turns every backup upload into a request for cloud instance credentials.
     *
     * A warning rather than a failure, deliberately. Some operators genuinely do run MinIO on a
     * private network beside Manager, and refusing to start would be this check overruling somebody
     * who knows their own topology. It says so, once, where they will see it.
     *
     * @return list<Check>
     */
    private function backupDestination(): array
    {
        $disk = (string) config('manager.backups.disk');
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        if ($driver !== 's3') {
            return [Check::pass('Backup storage', "Disk: {$disk} ({$driver}).")];
        }

        $endpoint = config("filesystems.disks.{$disk}.endpoint");
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");

        if ($bucket === '') {
            return [Check::fail(
                'Backup storage',
                'The backup disk is S3 but no bucket is set.',
                'Set MANAGER_BACKUP_S3_BUCKET.',
            )];
        }

        /*
         | No credentials is a real configuration, and a rare one.
         |
         | The AWS SDK walks a credential chain and ends it at the EC2 instance metadata service on
         | 169.254.169.254. On an EC2 host with a role attached that is correct and deliberate.
         | Anywhere else — which is most places, including every Ploi host — it is what happens when
         | the credentials were simply never set, and the symptom is not a clean refusal: the upload
         | hangs, the artifact sits at "uploading", and the operator sees
         |
         |     cURL error 28: Connection timed out ... 169.254.169.254
         |
         | which reads like a network fault rather than a missing variable.
         |
         | Reported from a live console deployment, where .env.example's "Object storage" block said
         | in as many words that AWS_* was "used for backups" — it is not, and never has been. The
         | backups disk reads MANAGER_BACKUP_S3_* alone, which is the whole point of it having its
         | own credentials. That comment is corrected in the same change as this check.
         |
         | A warning rather than a failure, because the instance-role case is legitimate and this
         | cannot tell the two apart from configuration alone.
        */
        $key = (string) config("filesystems.disks.{$disk}.key");
        $secret = (string) config("filesystems.disks.{$disk}.secret");

        if ($key === '' || $secret === '') {
            return [Check::warn(
                'Backup storage',
                "S3 bucket {$bucket}, with no credentials configured.",
                'Set MANAGER_BACKUP_S3_KEY and MANAGER_BACKUP_S3_SECRET. Backups do not read the '
                .'AWS_* variables. Without them the SDK falls back to the EC2 instance metadata '
                .'service, and on a host that is not EC2 with a role attached every upload stalls '
                .'for a second and then fails. Ignore this only if this host genuinely has an '
                .'instance role.',
            )];
        }

        if (! is_string($endpoint) || $endpoint === '') {
            // No endpoint means AWS itself, which needs no checking.
            return [Check::pass('Backup storage', "S3 bucket: {$bucket}.")];
        }

        // Scheme first, because the guard refuses anything but HTTPS as well and would otherwise
        // answer a plaintext endpoint with a message about private addresses. A check that reports the
        // wrong reason sends somebody looking in the wrong place.
        if (! str_starts_with(strtolower($endpoint), 'https://')) {
            return [Check::warn(
                'Backup storage',
                'The configured storage endpoint is not HTTPS.',
                'Artifacts are encrypted before they leave a site, so this does not expose their '
                .'contents — but it does expose their sizes, their names and your credentials.',
            )];
        }

        if (! $this->outboundUrls->isSafe($endpoint)) {
            return [Check::warn(
                'Backup storage',
                'The configured storage endpoint resolves to a private, loopback or metadata address.',
                'Confirm this is a service you run deliberately. An endpoint on 169.254.169.254 would '
                .'send every backup upload to a cloud metadata service.',
            )];
        }

        return [Check::pass('Backup storage', "S3-compatible bucket: {$bucket}.")];
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
