<?php

declare(strict_types=1);

namespace App\Domain\Job;

use App\Domain\Audit\AuditRecorder;
use App\Models\AuditEvent;
use App\Models\Connector;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole life of a remote job.
 *
 * Invariant 10 says every remote job must be authenticated, authorised, validated and audited. Those
 * four happen in different places, and it is worth being explicit about which:
 *
 *  - **Authenticated** by the signature middleware, before this class is reached. A connector
 *    claiming or reporting has already proved which site it is.
 *  - **Authorised** here, in {@see self::enqueue()} and again in {@see self::claimFor()}. Checked
 *    twice on purpose: a capability revoked between enqueuing and claiming must stop the job, or
 *    revocation would only apply to work nobody had asked for yet.
 *  - **Validated** here, against the registry's parameter schema on the way in and its result schema
 *    on the way back.
 *  - **Audited** here, on every transition including the refusals.
 */
final class JobService
{
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly AuditRecorder $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * Queue a job for a site.
     *
     * @param  array<string, mixed>  $parameters
     * @param  string|null  $idempotencyKey  two attempts with the same key produce one job
     *
     * @throws JobRejectedException
     */
    public function enqueue(
        Site $site,
        string $type,
        array $parameters = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): RemoteJob {
        try {
            $definition = $this->registry->get($type);
        } catch (UnknownJobTypeException) {
            $this->recordRefusal($site, $type, $actor, JobRejectedException::UNKNOWN_TYPE);

            throw new JobRejectedException(JobRejectedException::UNKNOWN_TYPE);
        }

        // Unknown parameters are rejected, not dropped. A caller passing something the schema does
        // not define has misunderstood the job, and silently ignoring it hides that.
        $problems = $definition->validateParameters($parameters);

        if ($problems !== []) {
            $this->recordRefusal($site, $type, $actor, JobRejectedException::INVALID_PARAMETERS, $problems);

            throw new JobRejectedException(JobRejectedException::INVALID_PARAMETERS, $problems);
        }

        if (! $site->hasCapability($definition->requiredCapability)) {
            $this->recordRefusal($site, $type, $actor, JobRejectedException::CAPABILITY_NOT_GRANTED);

            throw new JobRejectedException(JobRejectedException::CAPABILITY_NOT_GRANTED);
        }

        if ($site->activeConnector()->first() === null) {
            $this->recordRefusal($site, $type, $actor, JobRejectedException::SITE_NOT_CONNECTED);

            throw new JobRejectedException(JobRejectedException::SITE_NOT_CONNECTED);
        }

        return DB::transaction(function () use ($site, $definition, $parameters, $actor, $idempotencyKey): RemoteJob {
            // Checked before inserting, which handles the ordinary case without relying on an
            // exception. The index below is what makes it correct under concurrency.
            $outstanding = $this->outstandingFor($site, $idempotencyKey);

            if ($outstanding !== null) {
                return $outstanding;
            }

            try {
                // Nested, so it becomes a savepoint. A constraint violation aborts the Postgres
                // transaction, and without a savepoint to roll back to, the recovery query below
                // could not run — every statement after the failure would fail too.
                $job = DB::transaction(fn (): RemoteJob => RemoteJob::query()->create([
                    'site_id' => $site->id,
                    'type' => $definition->type,
                    'schema_version' => $definition->schemaVersion,
                    'parameters' => $parameters,
                    'state' => Jobs::STATE_QUEUED,
                    'idempotency_key' => $idempotencyKey,
                    'requested_by' => $actor?->id,
                    'requested_by_label' => $actor?->name ?: $actor?->email,
                    'correlation_id' => $this->correlationId->get(),
                ]));
            } catch (QueryException $e) {
                // The partial unique index refused a duplicate: somebody enqueued the same key
                // between the check above and this insert. Returning their job is the point of an
                // idempotency key — the caller asked for this work, and it is already going to happen.
                $existing = $this->outstandingFor($site, $idempotencyKey);

                if ($existing === null) {
                    throw $e;
                }

                return $existing;
            }

            $this->audit->record(
                action: 'job.queued',
                site: $site,
                actor: $actor,
                actorType: $actor === null ? AuditEvent::ACTOR_SYSTEM : AuditEvent::ACTOR_USER,
                targetType: 'job',
                targetId: $job->external_id,
                after: [
                    'type' => $definition->type,
                    'description' => $definition->auditDescription,
                    'parameters' => $parameters,
                    'capability' => $definition->requiredCapability,
                ],
            );

            return $job;
        });
    }

    /**
     * Hand a connector the jobs it is currently allowed to run.
     *
     * The claim is a conditional UPDATE, so two connectors for the same site cannot both take one
     * job — which is what makes invariant 16 hold here: a retried claim gets different work, or
     * nothing, never the same job twice.
     *
     * @return Collection<int, array<string, mixed>> envelopes, ready to sign
     */
    public function claimFor(Site $site, Connector $connector, int $limit = Jobs::MAX_CLAIM_BATCH): Collection
    {
        $limit = max(1, min($limit, Jobs::MAX_CLAIM_BATCH));

        return DB::transaction(function () use ($site, $connector, $limit): Collection {
            $candidates = RemoteJob::query()
                ->where('site_id', $site->id)
                ->where('state', Jobs::STATE_QUEUED)
                ->orderBy('id')
                ->limit($limit)
                // Skips rows another transaction is already claiming rather than waiting on them.
                ->lockForUpdate()
                ->get();

            $envelopes = collect();

            foreach ($candidates as $job) {
                $definition = $this->registry->has($job->type) ? $this->registry->get($job->type) : null;

                // A type that has since left the registry is cancelled rather than handed out. This
                // is the case where a job was queued, the platform was upgraded, and the definition
                // is gone: running it would mean executing something no longer defined.
                if ($definition === null) {
                    $this->finish($job, Jobs::STATE_CANCELLED, failureReason: 'job type no longer defined');

                    continue;
                }

                // Re-checked at claim time. A capability revoked since the job was queued must stop
                // it, or revocation would only cover work nobody had asked for yet.
                if (! $site->hasCapability($definition->requiredCapability)) {
                    $this->finish($job, Jobs::STATE_CANCELLED, failureReason: 'capability revoked after the job was queued');

                    continue;
                }

                $expiresAt = Carbon::now()->addSeconds($definition->maxRuntimeSeconds);

                $claimed = DB::selectOne(
                    <<<'SQL'
                        UPDATE remote_jobs
                           SET state = ?, claimed_at = now(), expires_at = ?,
                               claimed_by_connector_id = ?, claim_count = claim_count + 1,
                               updated_at = now()
                         WHERE id = ? AND state = ?
                     RETURNING id
                    SQL,
                    [Jobs::STATE_CLAIMED, $expiresAt, $connector->id, $job->id, Jobs::STATE_QUEUED],
                );

                if ($claimed === null) {
                    // Lost the race. Somebody else has it; nothing to do.
                    continue;
                }

                $this->audit->record(
                    action: 'job.claimed',
                    site: $site,
                    actorType: AuditEvent::ACTOR_CONNECTOR,
                    actorLabel: 'Connector '.$connector->connector_version,
                    targetType: 'job',
                    targetId: $job->external_id,
                    after: ['type' => $job->type, 'expires_at' => $expiresAt->toIso8601String()],
                );

                $envelopes->push($definition->envelope(
                    $job->external_id,
                    $job->parameters,
                    $expiresAt->getTimestamp(),
                ));
            }

            return $envelopes;
        });
    }

    /**
     * Record what a connector reports back.
     *
     * @param  array<string, mixed>  $result
     *
     * @throws JobRejectedException
     */
    public function report(
        Site $site,
        Connector $connector,
        string $jobExternalId,
        bool $succeeded,
        array $result = [],
        ?string $failureReason = null,
    ): RemoteJob {
        $job = RemoteJob::query()
            // Scoped to the reporting site, so a connector cannot report on another site's job even
            // holding a valid identifier.
            ->where('site_id', $site->id)
            ->where('external_id', $jobExternalId)
            ->first();

        if ($job === null) {
            throw new JobRejectedException(JobRejectedException::NOT_CLAIMED_BY_THIS_CONNECTOR);
        }

        // A result for an already-finished job is accepted quietly and changes nothing. This is the
        // retry case: the connector reported, the response was lost, it reported again. Treating
        // that as an error would push a connector into a loop over work that is already done.
        if ($job->isFinished()) {
            return $job;
        }

        if ($job->claimed_by_connector_id !== $connector->id) {
            throw new JobRejectedException(JobRejectedException::NOT_CLAIMED_BY_THIS_CONNECTOR);
        }

        $definition = $this->registry->get($job->type);

        if ($succeeded) {
            $problems = $definition->validateResult($result);

            if ($problems !== []) {
                $this->finish($job, Jobs::STATE_FAILED, failureReason: 'result did not match the expected schema');

                $this->audit->record(
                    action: 'job.result.rejected',
                    site: $site,
                    actorType: AuditEvent::ACTOR_CONNECTOR,
                    actorLabel: 'Connector '.$connector->connector_version,
                    targetType: 'job',
                    targetId: $job->external_id,
                    outcome: AuditEvent::OUTCOME_FAILURE,
                    failureReason: 'invalid result',
                    // Paths only. The validator never quotes a rejected value, which is what makes
                    // its output safe to store.
                    after: ['problems' => array_slice($problems, 0, 20)],
                );

                throw new JobRejectedException(JobRejectedException::INVALID_RESULT, $problems);
            }
        }

        $this->finish(
            $job,
            $succeeded ? Jobs::STATE_SUCCEEDED : Jobs::STATE_FAILED,
            result: $succeeded ? $result : null,
            failureReason: $succeeded ? null : ($failureReason ?? 'reported as failed'),
        );

        $this->audit->record(
            action: $succeeded ? 'job.succeeded' : 'job.failed',
            site: $site,
            actorType: AuditEvent::ACTOR_CONNECTOR,
            actorLabel: 'Connector '.$connector->connector_version,
            targetType: 'job',
            targetId: $job->external_id,
            outcome: $succeeded ? AuditEvent::OUTCOME_SUCCESS : AuditEvent::OUTCOME_FAILURE,
            failureReason: $succeeded ? null : $failureReason,
            after: ['type' => $job->type, 'result' => $succeeded ? $result : null],
        );

        return $job->fresh() ?? $job;
    }

    /**
     * Cancel a queued job.
     *
     * @throws JobRejectedException
     */
    public function cancel(RemoteJob $job, ?User $actor, string $reason = 'cancelled'): void
    {
        if ($job->isFinished()) {
            throw new JobRejectedException(JobRejectedException::ALREADY_FINISHED);
        }

        $definition = $this->registry->get($job->type);

        // Cancelling a claimed job is only meaningful if the definition says so. For one that is
        // not cancellable, the connector may already be part-way through, and pretending otherwise
        // would make the interface lie.
        if ($job->isClaimed() && ! $definition->cancellable) {
            throw new JobRejectedException(JobRejectedException::ALREADY_FINISHED);
        }

        $this->finish($job, Jobs::STATE_CANCELLED, failureReason: $reason);

        $this->audit->record(
            action: 'job.cancelled',
            site: $job->site,
            actor: $actor,
            actorType: $actor === null ? AuditEvent::ACTOR_SYSTEM : AuditEvent::ACTOR_USER,
            targetType: 'job',
            targetId: $job->external_id,
            after: ['type' => $job->type, 'reason' => $reason],
        );
    }

    /**
     * Mark claimed jobs that outran their maximum runtime.
     *
     * Expiry is written by this sweep rather than inferred on read, so that "claimed" always means a
     * connector is genuinely expected to report, and the audit log records when the platform gave up.
     *
     * @return int how many were expired
     */
    public function expireOverdue(): int
    {
        $overdue = RemoteJob::query()
            ->where('state', Jobs::STATE_CLAIMED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->get();

        foreach ($overdue as $job) {
            $this->finish($job, Jobs::STATE_EXPIRED, failureReason: 'no result within the maximum runtime');

            $this->audit->record(
                action: 'job.expired',
                site: $job->site,
                actorType: AuditEvent::ACTOR_SYSTEM,
                actorLabel: 'Scheduler',
                targetType: 'job',
                targetId: $job->external_id,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: 'no result within the maximum runtime',
                after: ['type' => $job->type, 'claim_count' => $job->claim_count],
            );
        }

        return $overdue->count();
    }

    /**
     * An unfinished job already holding this idempotency key.
     *
     * Only queued and claimed count. A completed job must not block a later request that legitimately
     * reuses the key — a nightly check should run again tomorrow.
     */
    private function outstandingFor(Site $site, ?string $idempotencyKey): ?RemoteJob
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return RemoteJob::query()
            ->where('site_id', $site->id)
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('state', [Jobs::STATE_QUEUED, Jobs::STATE_CLAIMED])
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(RemoteJob $job, string $state, ?array $result = null, ?string $failureReason = null): void
    {
        $job->forceFill([
            'state' => $state,
            'result' => $result,
            'failure_reason' => $failureReason,
            'finished_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @param  list<string>  $problems
     */
    private function recordRefusal(Site $site, string $type, ?User $actor, string $reason, array $problems = []): void
    {
        $this->audit->record(
            action: 'job.refused',
            site: $site,
            actor: $actor,
            actorType: $actor === null ? AuditEvent::ACTOR_SYSTEM : AuditEvent::ACTOR_USER,
            targetType: 'job_type',
            targetId: $type,
            outcome: AuditEvent::OUTCOME_FAILURE,
            failureReason: $reason,
            after: $problems === [] ? null : ['problems' => array_slice($problems, 0, 20)],
        );
    }
}
