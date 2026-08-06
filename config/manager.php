<?php

declare(strict_types=1);

use coyshdigital\managerprotocol\Protocol;

return [

    /*
    |---------------------------------------------------------------------------------------------
    | Version
    |---------------------------------------------------------------------------------------------
    |
    | What this installation is running, for the screen that says so and for a support conversation
    | that would otherwise start with "which version?".
    |
    | Null unless something sets it, and deliberately so. The release tarball is produced by
    | `git archive`, so it carries no `.git` and an installation cannot work its own version out by
    | asking git - and the Docker image already has `MANAGER_VERSION` as a build argument that
    | nothing has ever read. This is what reads it. An installation running from a clone leaves it
    | unset and the screen says as much, which is the honest answer: guessing a number that people
    | then quote at support is worse than an em-dash.
    |
    */

    'version' => env('MANAGER_VERSION') ?: null,

    /*
    |---------------------------------------------------------------------------------------------
    | Pairing
    |---------------------------------------------------------------------------------------------
    |
    | An enrolment code is a bearer secret until it is consumed, so it is short-lived by design.
    | Attempt limits exist because rate limiting, not hashing cost, is what stops guessing: the
    | code carries 256 bits of entropy and there is no dictionary to slow an attacker down to.
    |
    */

    'enrolment' => [
        'ttl' => (int) env('MANAGER_ENROLMENT_TTL', 900),
        'max_attempts_per_ip' => (int) env('MANAGER_ENROLMENT_MAX_ATTEMPTS_IP', 10),
        'max_attempts_per_site' => (int) env('MANAGER_ENROLMENT_MAX_ATTEMPTS_SITE', 5),
        'decay_seconds' => (int) env('MANAGER_ENROLMENT_DECAY', 900),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Connector requests
    |---------------------------------------------------------------------------------------------
    |
    | The timestamp tolerance and the nonce retention move together: the store has to remember a
    | nonce for at least as long as its timestamp could still be accepted, which is why the TTL is
    | derived rather than configured separately.
    |
    */

    'connector' => [
        'timestamp_tolerance' => (int) env('MANAGER_TIMESTAMP_TOLERANCE', Protocol::DEFAULT_TIMESTAMP_TOLERANCE),
        /*
         | `?:` rather than leaning on env()'s default argument.
         |
         | env('KEY', $fallback) returns the fallback only when the key is *absent*. A key that is
         | present and blank returns an empty string, which `(int)` turns into 0 - and a size cap of
         | zero rejects everything. .env.example ships several of these lines blank, so copying it
         | and filling in only what you need is enough to set one.
         */
        'max_payload_bytes' => ((int) env('MANAGER_MAX_PAYLOAD_BYTES', Protocol::MAX_PAYLOAD_BYTES)) ?: Protocol::MAX_PAYLOAD_BYTES,
        'rate_limit_per_site' => (int) env('MANAGER_RATE_LIMIT_SITE', 60),
        'rate_limit_per_ip' => (int) env('MANAGER_RATE_LIMIT_IP', 120),

        /*
         | A separate allowance for artifact bytes, counted separately.
         |
         | The two above are sized for a connector that reports: a heartbeat, an inventory, a job
         | claim - a handful of requests a minute, where sixty is generous and a site exceeding it is
         | misbehaving. An artifact arriving in eight-megabyte parts is not that. A two-gigabyte
         | database is two hundred and fifty requests, and on a fast uplink they arrive in under a
         | minute, so the ordinary allowance would refuse a backup for doing exactly what this
         | platform told it to do - and the per-IP one would do it sooner, because every site behind
         | one office NAT shares it.
         |
         | Counted under its own key rather than raising the general limit. An upload in progress must
         | not be able to exhaust the allowance that heartbeats and job claims depend on, and a site
         | flooding the reporting endpoints must not be given a larger budget because uploads need
         | one. Two budgets, two purposes.
         |
         | Only the streaming routes draw on it, which is to say only the two that carry artifact
         | bytes. Not skipped, and never to be: "no rate limit on the endpoint that accepts the
         | largest bodies" is not a sentence that should be true of this file.
         */
        'rate_limit_ingest_per_site' => ((int) env('MANAGER_RATE_LIMIT_INGEST_SITE', 600)) ?: 600,
        'rate_limit_ingest_per_ip' => ((int) env('MANAGER_RATE_LIMIT_INGEST_IP', 1200)) ?: 1200,

        /*
        | Which cache store holds seen nonces. This must be a shared, atomic store: the replay
        | check relies on an atomic add, and an in-process store would let a replay through on a
        | second worker. If it is unreachable, verification rejects rather than passes.
        */
        'nonce_store' => env('MANAGER_NONCE_STORE', 'redis'),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Platform signing key
    |---------------------------------------------------------------------------------------------
    |
    | The Ed25519 keypair the platform signs responses with. Generated by `manager:keys:generate`
    | during setup and stored encrypted; the environment variables exist so an immutable
    | deployment can supply them instead.
    |
    */

    'signing' => [
        'public_key' => env('MANAGER_SIGNING_PUBLIC_KEY'),
        'secret_key' => env('MANAGER_SIGNING_SECRET_KEY'),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Backups
    |---------------------------------------------------------------------------------------------
    |
    | The encryption keypair is X25519 and separate from the Ed25519 signing pair above. Using one
    | keypair for both signing and encryption is a well-known way to weaken both, so there are two.
    |
    | A connector seals each artifact's key to the public half, which it holds from pairing. Only the
    | secret half opens it - which means, stated plainly, that whoever holds this key can read every
    | backup. That is not end-to-end encryption and the documentation does not claim it is.
    |
    | The size ceiling is a policy statement, not a buffer size. Nothing is ever held in memory: an
    | artifact larger than this is a site whose backup strategy needs a conversation - and since
    | manager-protocol 1.5.0 it is a conversation an operator can have, because the wire contract no
    | longer decides it for them.
    |
    */

    'backups' => [
        'public_key' => env('MANAGER_BACKUP_PUBLIC_KEY'),
        'secret_key' => env('MANAGER_BACKUP_SECRET_KEY'),

        'disk' => env('MANAGER_BACKUP_DISK', 'backups'),

        /*
         | The operator's ceiling, and null means they have not set one.
         |
         | Null rather than a number is the change, and it closes a trap rather than loosening one.
         | env() falls back only for an *absent* key, so a blank MANAGER_BACKUP_MAX_BYTES line came
         | through as '' and (int) '' is 0 - a ceiling of zero, which refused every upload with
         | HTTP 413 before a byte of the body was read. A 2.1 MB backup failed that way on a live
         | console for four nights. The `?:` that used to sit here caught that one shape and only
         | that one; now every degenerate value - absent, blank, zero, negative - maps to "no
         | ceiling", so **there is no value of this variable that refuses everything.**
         |
         | Unlimited by default, which reverses what this comment used to argue. The previous
         | reasoning was that a self-hosted installation should not silently acquire an unlimited
         | ceiling just because manager-protocol 1.5.0 stopped enforcing one. That reasoning kept a
         | 2 GiB default that nobody had chosen, and it was the wrong default in both editions: a
         | hosted platform that owns, meters and bills for the storage refused a 3.2 GB database it
         | was being paid to hold, and a self-hoster with a large disk hit a wall the protocol had
         | already stopped imposing. A limit somebody set is a policy. A limit nobody set is an
         | accident, and this is where it was coming from.
         |
         | Operators who want a wall set this to bytes and get one, named in the refusal. Everyone
         | else is bounded by quota_bytes below, by their disk, and by whatever their web server
         | will actually carry - see the "Upload path ceiling" diagnostic, which exists because the
         | last one of those is invisible from in here.
         |
         | Not the value the platform advertises to a connector, and not the value anything checks
         | against. Both go through {@see \App\Contracts\BackupSizeLimit}, because a hosted edition
         | answers this differently and an environment variable on the console is not the place for
         | that decision. This is the self-hosted answer's input.
         */
        'max_bytes' => (static function (): ?int {
            $bytes = (int) env('MANAGER_BACKUP_MAX_BYTES', 0);

            return $bytes > 0 ? $bytes : null;
        })(),

        /*
         | Bytes per part when an artifact is too large for a single request.
         |
         | Object stores refuse one request over 5 GB and permit at most ten thousand parts, so this
         | decides the largest artifact that can be uploaded at all: 256 MiB gives a ceiling far above
         | anything `max_bytes` would plausibly be set to, while keeping a failed part cheap to retry.
         |
         | Lower it in tests. It is what makes a 26 MiB artifact exercise six parts and therefore the
         | same code path a twenty-gigabyte one takes; the alternative is a suite that never runs the
         | multipart path and a first real exercise of it on somebody's production backup.
         */
        'part_bytes' => ((int) env('MANAGER_BACKUP_PART_BYTES', Protocol::ARTIFACT_PART_BYTES)) ?: Protocol::ARTIFACT_PART_BYTES,

        /*
         | Bytes per part when an artifact arrives *through this application* rather than going
         | straight to a store.
         |
         | A second part size, and the temptation is to reuse `part_bytes` above. Resist it: the two
         | numbers exist for unrelated reasons and would be wrong at each other's value. `part_bytes`
         | is 256 MiB because an object store refuses a single PUT over 5 GB and permits ten thousand
         | parts - a constraint about the *store*. This one is about the *request path*: nginx,
         | php-fpm and anything else between a site and this code, none of which this application can
         | see and every one of which has an opinion about how long a request may take.
         |
         | Eight mebibytes, chosen against the slowest plausible uplink rather than the fastest, and
         | against the shortest plausible timeout rather than the usual one. Sixty seconds is the
         | common default for both `request_terminate_timeout` and `fastcgi_read_timeout`, but thirty
         | is not unusual on a managed host - and the population with no direct-upload path at all is
         | self-hosted operators, who are the ones most likely to be on a business ADSL line. Eight
         | mebibytes clears thirty seconds at 2.2 Mbit/s and sixty at 1.1. Sixteen needs twice that,
         | which is fine for a hosted site on a hundred megabits and not fine for the people this
         | number exists to protect.
         |
         | A twenty-gigabyte artifact is then some two and a half thousand parts, which is fine: they
         | are sequential, each is retried on its own, and none of them is long enough to be
         | interesting to a proxy. The cost of a smaller part is one more signature verification and
         | one more row update per eight megabytes, which is noise against the transfer.
         |
         | That is the whole point. Before this existed a backup was one request for its entire
         | length, so a database large enough to take longer than an upstream timeout could not be
         | uploaded at all - and the failure arrived as an HTML 502 from a web server, with no
         | correlation id and nothing in this application's log, at the end of a multi-hour upload.
         |
         | Pinned onto the artifact at declare rather than read again when each part arrives. An
         | operator changing this variable mid-upload would otherwise move every remaining offset,
         | and the resulting file would fail its whole-file checksum - after the site had uploaded
         | all of it.
         |
         | Lower it in tests, for the same reason `part_bytes` says to. Clamped so that neither a
         | blank line nor a typo produces a part size that refuses every backup or restores the
         | single-request failure this exists to remove.
         */
        'ingest_part_bytes' => (static function (): int {
            $bytes = (int) env('MANAGER_BACKUP_INGEST_PART_BYTES', 8 * 1024 * 1024);

            return $bytes > 0
                ? max(64 * 1024, min($bytes, 256 * 1024 * 1024))
                : 8 * 1024 * 1024;
        })(),

        /*
         | Total bytes one organisation may hold, across every site. Unset by default, because an
         | operator who has not asked for a limit should not discover one.
         |
         | max_bytes above stops a single enormous dump; this stops forty ordinary ones. The failure
         | it prevents is the worse of the two: a volume that fills, after which every site's backups
         | fail at once, including the ones that were behaving.
         |
         | Null rather than a large number so that "no limit" stays distinguishable from "a big one".
         */
        'quota_bytes' => env('MANAGER_BACKUP_QUOTA_BYTES') === null
            ? null
            : (int) env('MANAGER_BACKUP_QUOTA_BYTES'),

        /*
         | How long a declared artifact may sit without its bytes arriving before it is written off.
         |
         | Six hours, because that is what an artifact of the size now permitted actually takes. Twenty
         | gigabytes on a 20 Mbit uplink is around two and a half hours before anything goes wrong; the
         | previous hour was sized for a world with a 2 GB ceiling in the wire contract, and it would
         | have written off a large backup as "declared but never uploaded" while it was still
         | uploading - then failed it, on a site that had done every part of the work correctly.
         |
         | This is also the number a presigned grant's lifetime is derived from, so the two cannot
         | drift apart into a window that outlives the credential it depends on.
         */
        'upload_window' => ((int) env('MANAGER_BACKUP_UPLOAD_WINDOW', 21600)) ?: 21600,
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Site health
    |---------------------------------------------------------------------------------------------
    |
    | How a site's check-in record is read. The interval mirrors the connector's own heartbeat
    | schedule; an operator who has changed the cron across their fleet sets it here, or every site
    | reports a permanent shortfall against a cadence nobody is keeping.
    |
    | The grace multiplier is what separates "a queue was busy" from "this site has stopped". Three
    | missed beats - fifteen minutes at the default - before a gap is called an outage.
    |
    | Retention is not optional. Uptime is derived from the heartbeats table rather than stored, so it
    | grows at roughly 8,600 rows per site per month, and the runtime and sign-in reports add another
    | 1,600 between them. Only the latest of each report is ever displayed - the history is kept so
    | somebody investigating an incident can look back, not because a screen needs it - so ninety days
    | is already generous. Nothing else would ever remove any of it.
    |
    */

    'health' => [
        'heartbeat_interval' => (int) env('MANAGER_HEARTBEAT_INTERVAL', 300),
        'heartbeat_grace_multiplier' => (int) env('MANAGER_HEARTBEAT_GRACE', 3),

        // Covers heartbeats, runtime reports and sign-in reports. MANAGER_HEARTBEAT_RETENTION_DAYS
        // is still honoured: it was the documented name before the other two tables existed, and
        // silently ignoring an operator's existing setting would be the wrong way to rename one.
        'telemetry_retention_days' => (int) env(
            'MANAGER_TELEMETRY_RETENTION_DAYS',
            env('MANAGER_HEARTBEAT_RETENTION_DAYS', 90),
        ),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Updates
    |---------------------------------------------------------------------------------------------
    |
    | Whether this installation may read Craft's published changelog so the notes can be shown on
    | the updates screen instead of in another tab.
    |
    | One request, for one public file, cached for the whole installation. It carries nothing about
    | which sites exist or which are behind - that association is the thing worth protecting, and it
    | never leaves. Off is still a supported way to run this: an installation with no outbound
    | access is a deliberate configuration, not a fault, and the screen falls back to the link it
    | has always had.
    |
    */

    'updates' => [
        'fetch_changelogs' => filter_var(
            env('MANAGER_FETCH_CHANGELOGS', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Account security
    |---------------------------------------------------------------------------------------------
    */

    'auth' => [
        'recent_auth_minutes' => (int) env('MANAGER_RECENT_AUTH_MINUTES', 15),
        'max_login_attempts' => (int) env('MANAGER_MAX_LOGIN_ATTEMPTS', 5),
        'login_decay_seconds' => (int) env('MANAGER_LOGIN_DECAY', 900),
        'recovery_code_count' => 10,

        /*
        | Public registration is off by default and there is no environment variable to turn it on
        | in the self-hosted edition. Accounts are created by an owner, or through the one-time
        | setup flow.
        */
        'allow_registration' => false,
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Setup
    |---------------------------------------------------------------------------------------------
    |
    | The first-run flow that creates the owner account. It disables itself permanently once an
    | owner exists - the route stops resolving rather than merely being hidden.
    |
    */

    'setup' => [
        'ttl' => (int) env('MANAGER_SETUP_TTL', 3600),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Trusted proxies
    |---------------------------------------------------------------------------------------------
    |
    | Mirrored here as well as being read in bootstrap/app.php so that `manager:doctor` can report
    | on it. Reading env() from application code would return null once the config is cached, and a
    | check that silently passes on a production install is worse than no check.
    |
    | Never "*": trusting every proxy lets any caller set X-Forwarded-For, which defeats the
    | per-network rate limits and the source addresses recorded in the audit log.
    |
    */

    'trusted_proxies' => env('MANAGER_TRUSTED_PROXIES', ''),

    /*
    |---------------------------------------------------------------------------------------------
    | Response headers
    |---------------------------------------------------------------------------------------------
    |
    | How long a browser should refuse to reach this installation over plain HTTP, in seconds. Sent
    | only when APP_URL is already HTTPS - on an installation served over HTTP it would be a promise
    | the operator has not made.
    |
    | Configurable because sending it once commits every browser that saw it for this long, and an
    | operator moving a host back to HTTP has no way to withdraw it early. A year is the usual
    | answer; zero switches it off.
    |
    | The `?:` rather than a plain default is the blank-versus-absent trap: env() returns the default
    | only when the key is absent, and a blank line in .env would otherwise make this zero.
    |
    */
    'security' => [
        'hsts_seconds' => (int) (env('MANAGER_HSTS_SECONDS') ?: 31536000),
    ],

    /*
    |---------------------------------------------------------------------------------------------
    | Diagnostics
    |---------------------------------------------------------------------------------------------
    |
    | Optional, off by default, and never mandatory in a self-hosted deployment. Carries no site
    | content and no secrets, is visible in settings, and can be turned off again at any time.
    |
    */

    'diagnostics' => [
        'enabled' => (bool) env('MANAGER_DIAGNOSTICS_ENABLED', false),
        'endpoint' => env('MANAGER_DIAGNOSTICS_ENDPOINT'),
    ],

];
