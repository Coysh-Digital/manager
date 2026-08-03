<?php

declare(strict_types=1);

namespace App\Domain\Job;

use coyshdigital\managerprotocol\Jobs;

/**
 * Every remote job the platform will enqueue, and nothing else.
 *
 * A closed set, built once and read many times. Invariant 9 says remote jobs must use a fixed
 * allowlist of versioned job types; this class *is* that allowlist, and the only way to add to it is
 * to edit this file and decide all nine properties a definition requires.
 *
 * Read the definitions below and note the shape of what is here. Every job names an operation the
 * connector already implements. None of them takes a command, a path, a query or a URL — a job that
 * accepted any of those would be a channel for arbitrary execution wearing a job's clothes, which is
 * exactly what invariant 8 forbids.
 */
final class JobRegistry
{
    /** @var array<string, JobDefinition>|null */
    private ?array $definitions = null;

    /**
     * @throws UnknownJobTypeException
     */
    public function get(string $type): JobDefinition
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$type])) {
            // Deliberately does not echo the requested type back into the message unfiltered beyond
            // its own name, and never suggests near matches: a caller guessing at job types learns
            // nothing from the error.
            throw new UnknownJobTypeException("'{$type}' is not a job type this platform defines.");
        }

        return $definitions[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->definitions()[$type]);
    }

    /**
     * @return array<string, JobDefinition>
     */
    public function all(): array
    {
        return $this->definitions();
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return array<string, JobDefinition>
     */
    private function definitions(): array
    {
        return $this->definitions ??= $this->build();
    }

    /**
     * @return array<string, JobDefinition>
     */
    private function build(): array
    {
        $definitions = [];

        /*
         | inventory.refresh
         |
         | Ask a site to gather and send its operational metadata now, rather than waiting for the
         | next scheduled run. Useful straight after a deployment.
         |
         | Takes no parameters at all, which is the safest possible parameter schema.
         */
        $definitions[Jobs::INVENTORY_REFRESH] = new JobDefinition(
            type: Jobs::INVENTORY_REFRESH,
            schemaVersion: 'v1',
            requiredCapability: 'inventory:read',
            parameterSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [],
            ],
            // Generous, because a site under load may not pick the job up immediately, but bounded:
            // a job nobody reports on becomes expired rather than sitting claimed forever.
            maxRuntimeSeconds: 300,
            idempotency: JobDefinition::IDEMPOTENT,
            cancellable: true,
            resultSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['reported'],
                'properties' => [
                    'reported' => ['type' => 'boolean'],
                    // The report itself arrives on the inventory endpoint, validated against
                    // inventory.v1 there. Duplicating it here would mean two places to keep in step.
                    'schema_version' => ['type' => 'string', 'maxLength' => 32],
                ],
            ],
            auditDescription: 'Requested an immediate inventory report',
        );

        /*
         | updates.check
         |
         | Ask a site to check for available Craft and plugin updates and report what it finds.
         |
         | Read-only with respect to the site. The connector does make an outbound request to
         | Craft's own update service to answer it, which is the site checking its own updates —
         | not the arbitrary HTTP that invariant 8 forbids.
         */
        $definitions[Jobs::UPDATES_CHECK] = new JobDefinition(
            type: Jobs::UPDATES_CHECK,
            schemaVersion: 'v1',
            requiredCapability: 'updates:read',
            parameterSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    // Whether to bypass the connector's own cached answer. A boolean, not a URL or
                    // an endpoint to query.
                    'force' => ['type' => 'boolean'],
                ],
            ],
            // Longer, because it depends on a third party responding.
            maxRuntimeSeconds: 600,
            idempotency: JobDefinition::IDEMPOTENT,
            cancellable: true,
            resultSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['reported'],
                'properties' => [
                    'reported' => ['type' => 'boolean'],
                    'craft_update_available' => ['type' => 'boolean'],
                    'plugin_updates' => ['type' => 'integer', 'minimum' => 0],
                    'security_releases' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            auditDescription: 'Requested an update check',
        );

        /*
         | backup.create
         |
         | Take a database backup, encrypt it on the site and upload it here.
         |
         | Takes no parameters, and that is the interesting part of this definition. The obvious design
         | hands the connector somewhere to upload to — but a parameter naming a destination would let
         | a compromised platform tell a site to send its entire database somewhere else. So there is
         | nothing in the payload for a forged instruction to occupy: the connector uploads to the
         | platform it is already paired with, at an address it holds locally and no payload can reach.
         |
         | at_most_once, unlike the two above. A retry after an unknown outcome is not free here: it
         | means a second dump of a production database, and possibly two artifacts where an operator
         | expected one. The declare step is keyed on this job so a retried *report* is harmless, but
         | the work itself is not re-issued automatically.
         */
        $definitions[Jobs::BACKUP_CREATE] = new JobDefinition(
            type: Jobs::BACKUP_CREATE,
            schemaVersion: 'v1',
            requiredCapability: 'backups:create',
            /*
             | One optional parameter, and it is the platform overriding the site.
             |
             | `max_megabytes` is sent only by an edition that owns and bills for the storage, and
             | zero means no limit. Self-hosted never sends it, so a site's own maxBackupMegabytes
             | stands — which is the setting's whole purpose there: it bounds a dump on a disk the
             | same operator owns.
             |
             | Hosted, it is the wrong control in the wrong place. The customer's plugin config is on
             | their own server, most sites have no config file at all, and the 2 GB default becomes
             | a ceiling nobody chose on storage we are already metering and charging for. Refusing
             | the backup is a worse answer than billing for the space.
             */
            parameterSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'max_megabytes' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            /*
             | A dump plus encryption plus an upload, on a site that may be large and a connection
             | that may be slow. Bounded all the same: an unreported job expires rather than sitting
             | claimed.
             |
             | Seven hours: the default `manager.backups.upload_window` of six, plus an hour for the
             | dump and the encryption that happen before the upload starts. The previous value was
             | one hour, sized for a world where the wire contract capped an artifact at 2 GB — it
             | would now expire a job while its artifact was still arriving, and fail a site that had
             | done every part of the work correctly.
             |
             | A literal rather than read from that config key, which is what it looks like it should
             | be. This registry is evaluated by a data provider, before the container exists, so
             | `config()` here throws "Target class [config] does not exist" during the test run
             | rather than at any point a person would see. An operator who lengthens the upload
             | window well past six hours should raise this too.
             */
            maxRuntimeSeconds: 25200,
            idempotency: JobDefinition::AT_MOST_ONCE,

            /*
             | Cancellable, and what that means is narrower than the word.
             |
             | This said false, with the reasoning that cancelling tells the connector nothing: by
             | the time a site noticed, the dump has either happened or it has not. That is still
             | true, and it is why nothing here claims to stop a site working.
             |
             | What it missed is that the platform's own half is worth stopping. A backup nobody
             | wants any more otherwise sits in-flight for seven hours until the expiry sweep, the
             | screen keeps saying it is coming, a second one cannot be requested because the
             | idempotency key is still outstanding, and whatever eventually arrives is stored and
             | billed. Cancelling ends the waiting and makes the declaration that follows be
             | refused — the declare endpoint already requires a claimed job, so that half needs no
             | new rule, only a reason to trust it.
             |
             | The interface says exactly this much and no more: it stops us accepting the backup,
             | it does not stop the site making one.
            */
            cancellable: true,
            resultSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['stored'],
                'properties' => [
                    'stored' => ['type' => 'boolean'],

                    // The artifact identifier this platform issued at the declare step, echoed back so
                    // the job result and the artifact can be tied together in the audit log.
                    'artifact' => ['type' => 'string', 'maxLength' => 40],
                    'plaintext_bytes' => ['type' => 'integer', 'minimum' => 0],
                ],
            ],
            auditDescription: 'Requested a database backup',
        );

        return $definitions;
    }
}
