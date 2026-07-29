<?php

declare(strict_types=1);

namespace App\Domain\Job;

use coyshdigital\managerprotocol\SchemaValidator;

/**
 * One entry in the job registry.
 *
 * The specification requires a job definition to carry a stable type, a schema version, a required
 * capability, a valid parameter schema, a maximum runtime, idempotency behaviour, cancellation
 * behaviour, an expected result schema and an audit description. All nine are constructor arguments,
 * so a definition cannot be added without deciding every one of them.
 *
 * That is the point of the shape. The failure mode being designed out is somebody adding a job in a
 * hurry and leaving the runtime unbounded, or the capability unstated, or the result unvalidated.
 */
final class JobDefinition
{
    /**
     * Runs safely more than once. A retry produces the same outcome.
     */
    public const IDEMPOTENT = 'idempotent';

    /**
     * Must not run twice. A retry after an unknown outcome needs a human decision.
     */
    public const AT_MOST_ONCE = 'at_most_once';

    /**
     * @param  string  $type  stable identifier, never renamed once released
     * @param  string  $schemaVersion  bumped when the parameter or result shape changes
     * @param  string  $requiredCapability  no capability, no job
     * @param  array<string, mixed>  $parameterSchema  JSON Schema with additionalProperties false
     * @param  int  $maxRuntimeSeconds  after which the job is considered expired, not still running
     * @param  string  $idempotency  one of the constants above
     * @param  bool  $cancellable  whether cancelling a claimed job is meaningful
     * @param  array<string, mixed>  $resultSchema  what a connector may report back
     * @param  string  $auditDescription  what appears in the audit log, in plain words
     */
    public function __construct(
        public readonly string $type,
        public readonly string $schemaVersion,
        public readonly string $requiredCapability,
        public readonly array $parameterSchema,
        public readonly int $maxRuntimeSeconds,
        public readonly string $idempotency,
        public readonly bool $cancellable,
        public readonly array $resultSchema,
        public readonly string $auditDescription,
    ) {}

    /**
     * Validate parameters against this job's schema.
     *
     * Unknown parameters are rejected rather than ignored — that is invariant 9's second half, and
     * the reason every parameter schema sets `additionalProperties: false`.
     *
     * @param  array<string, mixed>  $parameters
     * @return list<string> empty when acceptable
     */
    public function validateParameters(array $parameters): array
    {
        return (new SchemaValidator($this->parameterSchema))->validate($parameters);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public function validateResult(array $result): array
    {
        return (new SchemaValidator($this->resultSchema))->validate($result);
    }

    public function isIdempotent(): bool
    {
        return $this->idempotency === self::IDEMPOTENT;
    }

    /**
     * The shape handed to a connector when it claims this job.
     *
     * Carries the type, the schema version and the parameters, and nothing else. In particular it
     * carries no instruction the connector has to interpret: the type names an operation the
     * connector already implements, or it refuses.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function envelope(string $jobExternalId, array $parameters, int $expiresAt): array
    {
        return [
            'id' => $jobExternalId,
            'type' => $this->type,
            'schema_version' => $this->schemaVersion,
            'parameters' => $parameters,
            'expires_at' => $expiresAt,
            'max_runtime' => $this->maxRuntimeSeconds,
        ];
    }
}
