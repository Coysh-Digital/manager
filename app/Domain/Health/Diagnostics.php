<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Contracts\BackupSizeLimit;
use App\Contracts\DirectUploadGrants;
use App\Contracts\MailAdministration;
use App\Contracts\ServerAccess;
use App\Domain\Backup\BackupKeypair;
use App\Domain\Backup\BackupService;
use App\Domain\Connector\PlatformKeypair;
use App\Domain\Mail\MailConfiguration;
use App\Domain\Notifications\OutboundUrlGuard;
use App\Models\CapabilityGrant;
use App\Models\User;
use App\Support\SelfHosted\NoDirectUploads;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    /**
     * How long a job may sit unclaimed before the queue is called stalled.
     *
     * Generous on purpose. A worker restarting during a deploy, or a long job holding the only
     * process, both produce a short backlog that resolves itself, and a check that goes red for those
     * is one people learn to ignore. Twenty minutes of nothing being picked up is not a busy queue.
     */
    private const QUEUE_STALL_MINUTES = 20;

    public function __construct(
        private readonly PlatformKeypair $keypair,
        private readonly BackupKeypair $backupKeypair,
        private readonly BackupService $backups,
        private readonly OutboundUrlGuard $outboundUrls,
        private readonly BackupSizeLimit $backupSize,
    ) {}

    /**
     * The checks this reader has any business seeing.
     *
     * Filtered on {@see ServerAccess}, and reusing that seam rather than adding one is deliberate:
     * every check hidden here is about the machine serving the page, and somebody who cannot reach
     * that machine cannot act on any of them. The same question, asked once.
     *
     * `manager:doctor` and the back-office keep calling {@see self::all()}, because the operator is
     * exactly who the hidden ones are for.
     *
     * @return list<Check>
     */
    public function forReader(): array
    {
        if (app(ServerAccess::class)->reachable()) {
            return $this->all();
        }

        return array_values(array_filter(
            $this->all(),
            static fn (Check $check): bool => ! $check->isForOperator(),
        ));
    }

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
            $this->backupSizeCeiling(),
            $this->uploadPathCeiling(),
            ...$this->security(),
        ];
    }

    /**
     * How large an artifact this installation will accept.
     *
     * Reported because it is invisible until it refuses something, and because when it refuses it
     * does so in middleware - before anything with context about the artifact runs, and with a
     * correlation id nothing writes down. A blank MANAGER_BACKUP_MAX_BYTES made this zero on a live
     * console and every upload was refused with "payload too large", including a 2.1 MB one, for
     * four nights.
     *
     * The blank case cannot recur - every degenerate value now means "no ceiling" rather than a
     * ceiling of zero, and an invariant asserts it - but a deliberately small value can, and looks
     * identical from the outside. So the number is stated rather than left to be discovered.
     *
     * Read through {@see BackupSizeLimit} rather than from config, because a hosted edition answers
     * it without consulting config at all and a diagnostic that reported the operator's unused
     * environment variable would be describing a limit nothing enforces.
     */
    private function backupSizeCeiling(): Check
    {
        $bytes = $this->backupSize->ceilingBytes();

        /*
         | No ceiling is the default, and it is a pass rather than a warning.
         |
         | The size is still bounded - by the organisation's quota, by the disk, and by whatever the
         | web server will carry. Only the last of those is invisible from in here, and it has its
         | own check below rather than a hedge in this one.
         */
        if ($bytes === null) {
            // The other half of "shown to everybody". How large a backup this platform will accept
            // is a fact about the reader's own data rather than about our machine, and on a hosted
            // edition this branch is the only one reachable - the ceiling is lifted there, so the
            // row says so instead of leaving somebody to wonder.
            return Check::pass(
                'Backup size ceiling',
                'No ceiling. Artifacts are bounded by storage quota and disk.',
            )->forEveryone();
        }

        $readable = round($bytes / 1024 / 1024).' MB';

        // Anything under a megabyte will refuse essentially every real database.
        if ($bytes < 1024 * 1024) {
            return Check::fail(
                'Backup size ceiling',
                "{$bytes} bytes.",
                'MANAGER_BACKUP_MAX_BYTES is set low enough to refuse every backup. Unset it for no ceiling.',
            );
        }

        /*
         | Raised past what a single request can carry, on an edition that cannot split one.
         |
         | An object store refuses a single PUT over five gigabytes, so above that an artifact has to
         | arrive in parts - and only an edition binding {@see DirectUploadGrants} to something real
         | can issue them. Self-hosted binds {@see NoDirectUploads}, so every byte comes through this
         | application instead: a ceiling above 5 GB there is a promise the upload path cannot keep,
         | and without this it would be discovered at the end of a multi-hour upload.
         |
         | A warning rather than a failure. An operator may have raised it deliberately while pointing
         | sites at their own bucket, in which case the artifact never comes here at all and the
         | ceiling is exactly right. This check cannot tell, so it says what it sees.
         */
        if ($bytes > Protocol::SINGLE_PUT_MAX_BYTES && app(DirectUploadGrants::class) instanceof NoDirectUploads) {
            return Check::warn(
                'Backup size ceiling',
                "Artifacts up to {$readable}.",
                'Above 5 GB an artifact cannot be uploaded in one request, and this edition streams '
                .'every byte through the application. Sites uploading directly to their own storage '
                .'are unaffected; anything else will fail at the end of the upload.',
            );
        }

        return Check::pass('Backup size ceiling', "Artifacts up to {$readable}.");
    }

    /**
     * Whether the request path can actually carry what the check above says it will accept.
     *
     * The ceiling is a policy. This is the plumbing, and when the two disagree the plumbing wins
     * silently - which is the whole reason this exists.
     *
     * PHP refuses a request whose Content-Length exceeds `post_max_size` before any application
     * code runs, and Laravel's ValidatePostSize applies it to a PUT exactly as to a POST. So an
     * installation can advertise unlimited backups on the job claim, accept the declaration, and
     * then refuse the bytes - with a 413 the operator never configured and cannot find in this
     * application's own settings. The shipped Docker image did precisely this: nginx carved the
     * upload route out of its body limit while php.ini left `post_max_size` at 2M.
     *
     * **This cannot see nginx**, or Cloudflare, or any other proxy, and that is not a gap this
     * check can close from inside PHP - the request never arrives. A body limit there answers with
     * its own error page, so the connector reports "Correlation ID: unknown" and this platform logs
     * nothing at all. That failure is diagnosed by sending a large request and reading the response,
     * not from here. It cost four nights on a live console at `client_max_body_size 2m`.
     *
     * So read a pass here as "PHP is not the limit", never as "uploads work". The check says what
     * it can see, and says so rather than implying it can see everything.
     */
    private function uploadPathCeiling(): Check
    {
        $postMax = $this->iniBytes('post_max_size');
        $ceiling = $this->backupSize->ceilingBytes();

        if ($postMax === null) {
            return Check::pass('Upload path ceiling', 'PHP accepts a request body of any size.');
        }

        $readable = round($postMax / 1024 / 1024).' MB';

        /*
         | A ceiling this platform has already promised, and plumbing that cannot keep it.
         |
         | Stated as a contradiction rather than as a small number, because that is what makes it a
         | failure: the claim response has told every site it will accept up to the ceiling, and
         | those sites will dump, encrypt and offer an artifact before finding out otherwise.
         */
        if ($ceiling !== null && $postMax < $ceiling) {
            return Check::fail(
                'Upload path ceiling',
                "PHP post_max_size is {$readable}, below this platform's backup ceiling of "
                .round($ceiling / 1024 / 1024).' MB.',
                'Sites are told they may upload to the ceiling and PHP will refuse them before this '
                .'application runs. Raise post_max_size to at least the ceiling, or lower '
                .'MANAGER_BACKUP_MAX_BYTES to match.',
            );
        }

        // The same floor, and the same reasoning, as the ceiling check above: under a megabyte
        // refuses essentially every real database.
        if ($postMax < 1024 * 1024) {
            return Check::fail(
                'Upload path ceiling',
                "PHP post_max_size is {$readable}.",
                'PHP will refuse essentially every backup before this application sees it, with a '
                .'413 that is not configured here and does not appear in this log. Raise '
                .'post_max_size, or set it to 0 for no limit.',
            );
        }

        /*
         | Otherwise a pass that states the number, which is the whole job.
         |
         | Not a warning, however small the number is above the floor. Something has to be the
         | binding limit and PHP is a perfectly reasonable candidate; a check that warned on every
         | default installation would be a permanent yellow nobody reads, which is how the last
         | long-running amber here stopped being read.
         */
        return Check::pass(
            'Upload path ceiling',
            $ceiling === null
                ? "PHP accepts request bodies up to {$readable}, which is the effective backup ceiling."
                : "PHP accepts request bodies up to {$readable}.",
        );
    }

    /**
     * A php.ini shorthand size as bytes, or null when it means "no limit".
     *
     * `post_max_size` accepts 0 and the empty string for unlimited, and otherwise a number with an
     * optional K, M or G suffix. Parsed here rather than compared as a string, because "2M" and
     * "2048" and "0" all have to end up as the same kind of thing before any of this can be
     * reasoned about.
     */
    private function iniBytes(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '' || $raw === '0' || str_starts_with($raw, '-')) {
            return null;
        }

        $value = (int) $raw;

        return match (strtoupper(substr($raw, -1))) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
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

        /*
         | Blank is safe against forgery and unsafe for rate limiting, and it used to report as a
         | clean pass.
         |
         | With no trusted proxy, forwarded headers are ignored - correct, and the reason a wildcard
         | fails above. But it also means $request->ip() is the *proxy's* address for every caller,
         | so the per-network connector limit and the pairing limit collapse into one bucket shared
         | by the whole fleet. Any unauthenticated caller can exhaust it, and every site stops being
         | able to pair or report. The audit log records the proxy as the source of everything.
         |
         | Warned rather than failed, on both halves of the severity. It is not "do not serve
         | traffic": nothing is spoofable and an installation with no proxy in front is correctly
         | configured this way. But it is not a pass either, which is what it said while the fleet
         | shared a bucket.
         |
         | Keyed on the canonical URL being HTTPS, because that is what a proxy in front looks like
         | from a console command with no request to inspect: this stack does not terminate TLS
         | itself, so an HTTPS APP_URL means something else does.
        */
        $behindTls = str_starts_with((string) config('app.url'), 'https://');

        $checks[] = match (true) {
            trim($proxies) === '*' => Check::fail(
                'Trusted proxies',
                'Set to "*", so any caller can forge its apparent source address.',
                'List the actual proxy addresses or CIDR ranges in MANAGER_TRUSTED_PROXIES.',
            ),
            $proxies === '' && $behindTls => Check::warn(
                'Trusted proxies',
                'None configured, but APP_URL is HTTPS - so something terminates TLS in front, and '
                .'every caller appears to come from it. The connector and pairing rate limits are '
                .'one bucket shared by the whole fleet, and the audit log records the proxy as the '
                .'source of everything.',
                'List the proxy addresses or CIDR ranges in MANAGER_TRUSTED_PROXIES.',
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
     * Reports a status and no values. This check appears on the General settings screen, whose
     * reader is not necessarily an owner and therefore not necessarily somebody the Mail screen is
     * offered to - so the host, the port, the username and the from-address are all things this
     * check knows and never says. What matters is the answer to "will a password reset arrive", and
     * that is a yes or a no.
     */
    private function mail(): Check
    {
        // The stored configuration is pushed into the config repository at send time rather than at
        // boot - see App\Domain\Mail\MailConfiguration. Asking for it here is what stops this check
        // reporting on the environment while something else does the sending. Memoised, so this is
        // free after the first call in a process.
        app(MailConfiguration::class)->apply();

        $mailer = (string) config('mail.default');

        /*
         | Every remedy below names a place to go and fix it, which is the right instruction for the
         | person who can and useless to anybody else. On a hosted edition the relay belongs to
         | whoever runs the service, so the check still reports whether mail works - that is worth
         | knowing either way - and stops telling the reader to go and fix it.
         |
         | Both routes are named, because both are real: the environment is what an installation
         | starts with and what a container sets, and Settings → Mail is what an owner can reach
         | without a shell.
        */
        $operatorManaged = app(MailAdministration::class)->operatorManaged();

        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return Check::warn(
                'Mail',
                'No mail transport is configured, so nothing is delivered.',
                $operatorManaged
                    ? 'Set MAIL_MAILER and the matching MAIL_* variables, or configure a relay under '
                        .'Settings → Mail. Password resets, invitations and notification emails cannot '
                        .'reach anybody until you do. See docs/env.md.'
                    : null,
            );
        }

        if (! is_string(config('mail.from.address')) || config('mail.from.address') === '') {
            return Check::warn(
                'Mail',
                'A transport is configured but no sender address is set.',
                $operatorManaged
                    ? 'Set MAIL_FROM_ADDRESS, or a From address under Settings → Mail. Most relays '
                        .'reject a message with no envelope sender.'
                    : null,
            );
        }

        // The second sentence names a screen that does not exist on every edition. Pointing at a
        // control the reader cannot reach is worse than stopping at the fact.
        return Check::pass('Mail', $operatorManaged
            ? 'A transport and a sender address are configured. Send a test from Settings → Mail to prove delivery.'
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
     * which is how "bring your own bucket" works here - and a custom endpoint is a URL this
     * application will connect to, so it gets the same scrutiny a webhook destination does.
     *
     * The endpoint is checked against {@see OutboundUrlGuard}: HTTPS only, and no loopback,
     * link-local, private-range or metadata address. `169.254.169.254` is the one that matters - an
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
         | Anywhere else - which is most places, including every Ploi host - it is what happens when
         | the credentials were simply never set, and the symptom is not a clean refusal: the upload
         | hangs, the artifact sits at "uploading", and the operator sees
         |
         |     cURL error 28: Connection timed out ... 169.254.169.254
         |
         | which reads like a network fault rather than a missing variable.
         |
         | Reported from a live console deployment, where .env.example's "Object storage" block said
         | in as many words that AWS_* was "used for backups" - it is not, and never has been. The
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
                .'contents - but it does expose their sizes, their names and your credentials.',
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

        /*
         | Configured is not the same as consumed.
         |
         | This used to stop at the line above, which answers "is a queue set up" and not "is anything
         | draining it" - and the second is the question that matters, because every failure it hides
         | is silent. A stopped worker means backups are never fetched, notification deliveries never
         | leave, and, where a hosting layer is installed, no subscription email is ever sent. Nothing
         | errors. Every screen looks right. The only visible symptom is an absence, which is exactly
         | the kind of thing nobody notices until a customer asks why they were not told.
         |
         | Measured by the age of the oldest waiting job rather than by the depth. A deep queue is
         | normal after a burst of work and says nothing on its own; a job that has been waiting
         | twenty minutes means nothing has picked it up in twenty minutes, whatever the depth.
         |
         | Cannot distinguish an empty queue from an unworked one, and does not pretend to: with
         | nothing waiting there is nothing to time, and it reports the connection and stops.
         */
        try {
            $waiting = Queue::size();
        } catch (Throwable $e) {
            return Check::fail(
                'Queue',
                "Connection {$connection} could not be reached.",
                'Background work is not running. '.$e->getMessage(),
            );
        }

        if ($waiting === 0) {
            return Check::pass('Queue', "Connection: {$connection}. Nothing waiting.");
        }

        $oldest = $this->oldestQueuedJobMinutes();

        if ($oldest !== null && $oldest >= self::QUEUE_STALL_MINUTES) {
            return Check::fail(
                'Queue',
                "{$waiting} job(s) waiting, the oldest for {$oldest} minutes. Nothing appears to be processing them.",
                'Start a queue worker, or check the one that should be running. '
                    .'Backups, notifications and scheduled work are all queued.',
            );
        }

        return Check::pass('Queue', "Connection: {$connection}. {$waiting} job(s) waiting.");
    }

    /**
     * How long the oldest waiting job has been waiting, in minutes.
     *
     * Only the database driver can answer this: the jobs table carries available_at, so the question
     * is a query. Redis holds an opaque list and would need every payload decoded to find out, which
     * is not worth doing on a health check - so this returns null there and the caller reports the
     * depth without judging it.
     */
    private function oldestQueuedJobMinutes(): ?int
    {
        if ((string) config('queue.default') !== 'database') {
            return null;
        }

        try {
            $availableAt = DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->min('available_at');

            if (! is_numeric($availableAt)) {
                return null;
            }

            return (int) floor((time() - (int) $availableAt) / 60);
        } catch (Throwable) {
            // The table not existing is not a queue fault - a fresh installation before migrations
            // looks exactly like this, and the migrations check already reports that.
            return null;
        }
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
     *
     * Split by artifact format, and that is the whole point of the counting below. This key belongs to
     * the v1 format, where the platform holds the recipient and can therefore read every artifact. An
     * organisation that has raised its floor to v2 seals to its own recovery keys instead and never
     * consults this key at all - so for those sites its absence is correct and its presence is legacy.
     *
     * Reporting one answer for both was wrong in both directions at once. An installation whose
     * organisations had all moved to v2 was told a key it no longer uses meant its backups were
     * readable, which understates the security it actually has; and removing that key - the right
     * thing to do once no v1 artifacts remain - turned the check red and could fail a deploy, because
     * `manager:doctor` runs on every one.
     */
    private function backupEncryption(): Check
    {
        /*
         | Sites with permission, grouped by the format their organisation has settled on.
         |
         | Joined rather than counted per organisation, because the question is "how many sites would
         | try to back up", and a site inherits its format from the organisation above it.
         */
        $granted = CapabilityGrant::query()
            ->where('capability_grants.capability', 'backups:create')
            ->where('capability_grants.state', CapabilityGrant::STATE_GRANTED)
            ->join('sites', 'sites.id', '=', 'capability_grants.site_id')
            ->join('organisations', 'organisations.id', '=', 'sites.organisation_id')
            ->selectRaw('organisations.backup_format_floor as floor, count(*) as total')
            ->groupBy('organisations.backup_format_floor')
            ->pluck('total', 'floor');

        $legacy = (int) $granted->get(Protocol::BACKUP_FORMAT_V1, 0);
        $sealed = (int) $granted->get(Protocol::BACKUP_FORMAT_V2, 0);

        $sites = static fn (int $count): string => $count.' '.($count === 1 ? 'site' : 'sites');

        if (! $this->backupKeypair->isConfigured()) {
            // Only v1 sites are broken by this. Saying so lets an operator who has finished moving to
            // recovery keys delete the key without the health screen calling it an outage.
            if ($legacy > 0) {
                return Check::fail(
                    'Backup encryption key',
                    'Not configured, and '.$sites($legacy).' still on the v1 artifact format '
                    .($legacy === 1 ? 'has' : 'have').' permission to back up. No backup will be taken '
                    .'for them until it is: a connector without a key refuses rather than uploading a '
                    .'database in the clear.',
                    'Run: php artisan manager:backups:keygen - or set up a recovery key for those organisations, which moves them to the sealed format and retires this key.',
                );
            }

            if ($sealed > 0) {
                return Check::pass(
                    'Backup encryption key',
                    'Not configured, and not needed: '.$sites($sealed).' with permission seal each '
                    .'artifact to their own organisation\'s recovery keys, which this platform does not '
                    .'hold. Storing to '.$this->backups->describeStorage().'.',
                );
            }

            return Check::warn(
                'Backup encryption key',
                'Not configured. No site has permission to back up yet, so nothing is failing.',
                'Run: php artisan manager:backups:keygen before granting backups:create, or set up a recovery key to use the sealed format instead.',
            );
        }

        /*
         | Configured. What that means now depends on who is still using it, and the honest answer is
         | not the same sentence for both.
         */
        if ($legacy > 0) {
            // Said plainly rather than leaving somebody to infer end-to-end encryption from the word
            // "encrypted". For these sites it is simply true that we can read their backups.
            return Check::pass(
                'Backup encryption key',
                'Configured, storing to '.$this->backups->describeStorage().'. '
                .$sites($legacy).' on the v1 format '.($legacy === 1 ? 'seals' : 'seal').' to this key, '
                .'so whoever holds it can read those backups - they are not end-to-end encrypted.'
                .($sealed > 0 ? ' '.$sites($sealed).' already seal to their own recovery keys instead.' : ''),
            );
        }

        if ($sealed > 0) {
            return Check::pass(
                'Backup encryption key',
                'Configured but no longer used for new backups: '.$sites($sealed).' with permission '
                .'seal to their own organisation\'s recovery keys. Keep this key only while artifacts '
                .'taken before that change are still within retention - it is the only thing that can '
                .'read them. Storing to '.$this->backups->describeStorage().'.',
            );
        }

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

            /*
             | Shown to everybody, hosted included, and it is one of only two that are.
             |
             | It is not about the machine, it is about the reader's own audit log - and "entries
             | cannot be edited or deleted" is a claim the interface makes to them in as many words.
             | Somebody told that is entitled to see it checked rather than asserted, and it is the
             | one row here where a customer of a hosted edition learns something about their data
             | rather than about our infrastructure.
            */
            return ($missing === []
                ? Check::pass('Audit log protection', 'Both append-only triggers present.')
                : Check::fail(
                    'Audit log protection',
                    'Missing: '.implode(', ', $missing).'.',
                    'The audit log can be edited without them. Re-run migrations.',
                ))->forEveryone();
        } catch (Throwable $e) {
            return Check::fail('Audit log protection', 'Cannot inspect triggers.', 'Check the database connection.')
                ->forEveryone();
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
