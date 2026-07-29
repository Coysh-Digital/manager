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

        return $definitions;
    }
}
