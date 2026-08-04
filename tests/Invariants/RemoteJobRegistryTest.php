<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Domain\Job\JobDefinition;
use App\Domain\Job\JobRegistry;
use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Domain\Job\UnknownJobTypeException;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\CanonicalResponse;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;

/**
 * Invariants 8, 9 and 10.
 *
 *   8. No capability may provide arbitrary PHP, shell, console or SQL execution.
 *   9. Remote jobs must use a fixed allowlist of versioned job types.
 *  10. Every remote job must be authenticated, authorised, validated and audited.
 *
 * Those four words in invariant 10 happen in different places, and each is tested separately below.
 * A job that were authenticated and audited but not re-authorised at claim time would satisfy a
 * careless reading and still let a revoked capability run work.
 */
beforeEach(function (): void {
    config([
        'manager.signing.public_key' => ($platform = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $platform['secret'],
    ]);

    $this->platformKeypair = $platform;
    $this->keypair = Keys::generateKeypair();
    $this->site = Site::factory()->connected()->create();
    $this->connector = Connector::factory()->for($this->site)->withKeypair($this->keypair)->create();

    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();
    CapabilityGrant::factory()->for($this->site)->capability('updates:read')->create();

    $this->jobs = app(JobService::class);
});

// --------------------------------------------------------------------------------------------------
// Invariant 9: a fixed allowlist of versioned types.
// --------------------------------------------------------------------------------------------------

it('defines every property the specification requires for a job', function (string $type): void {
    $definition = app(JobRegistry::class)->get($type);

    // All nine are constructor arguments, so a definition cannot be added without deciding each. The
    // failure mode being designed out is a job added in a hurry with an unbounded runtime or an
    // unstated capability.
    expect($definition->type)->toBe($type)
        ->and($definition->schemaVersion)->not->toBeEmpty()
        ->and($definition->requiredCapability)->not->toBeEmpty()
        ->and($definition->parameterSchema)->toHaveKey('additionalProperties')
        ->and($definition->parameterSchema['additionalProperties'])->toBeFalse()
        ->and($definition->maxRuntimeSeconds)->toBeGreaterThan(0)
        ->and($definition->idempotency)->toBeIn([JobDefinition::IDEMPOTENT, JobDefinition::AT_MOST_ONCE])
        ->and($definition->resultSchema)->toHaveKey('additionalProperties')
        ->and($definition->auditDescription)->not->toBeEmpty();
})->with(fn () => app(JobRegistry::class)->types());

it('refuses to enqueue a job type it does not define', function (string $type): void {
    expect(fn () => $this->jobs->enqueue($this->site, $type))
        ->toThrow(JobRejectedException::class);

    expect(RemoteJob::query()->count())->toBe(0);
})->with([
    'console runner' => ['console.run'],
    'php eval' => ['php.eval'],
    'sql' => ['sql.query'],
    'shell' => ['shell.exec'],
    'file read' => ['file.read'],
    'arbitrary http' => ['http.request'],
    'reserved but unimplemented' => [Jobs::BACKUP_CREATE],
    'near miss' => ['inventory.refresh '],
    'empty' => [''],
]);

it('offers no job type that could execute something arbitrary', function (): void {
    // Invariant 8 at the registry level. Every job names an operation the connector already
    // implements; none takes a command, a path, a query or a URL.
    foreach (app(JobRegistry::class)->all() as $type => $definition) {
        foreach (['exec', 'eval', 'shell', 'console', 'command', 'sql', 'query', 'script'] as $forbidden) {
            expect($type)->not->toContain($forbidden);
        }

        // Nor may a parameter smuggle one in.
        foreach (array_keys($definition->parameterSchema['properties'] ?? []) as $parameter) {
            foreach (['command', 'script', 'sql', 'query', 'path', 'file', 'url', 'code', 'callback'] as $forbidden) {
                expect($parameter)->not->toContain($forbidden);
            }
        }
    }
});

it('rejects a parameter the job does not define', function (): void {
    // Rejected rather than dropped. A caller passing something the schema does not define has
    // misunderstood the job, and silently ignoring it hides that.
    expect(fn () => $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, ['command' => 'rm -rf /']))
        ->toThrow(JobRejectedException::class);

    expect(fn () => $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH, ['anything' => 1]))
        ->toThrow(JobRejectedException::class);

    expect(RemoteJob::query()->count())->toBe(0);
});

it('rejects a parameter of the wrong type', function (): void {
    expect(fn () => $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, ['force' => 'yes please']))
        ->toThrow(JobRejectedException::class);
});

it('throws a distinct error for an unknown type in the registry itself', function (): void {
    expect(fn () => app(JobRegistry::class)->get('nope.nope'))
        ->toThrow(UnknownJobTypeException::class);
});

// --------------------------------------------------------------------------------------------------
// Invariant 10: authenticated, authorised, validated, audited.
// --------------------------------------------------------------------------------------------------

it('requires authentication to claim work', function (): void {
    $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);

    // Authenticated: an unsigned request never reaches the controller.
    $this->postJson('/api/connector/v1/jobs/claim')->assertUnauthorized();

    expect(RemoteJob::query()->firstOrFail()->state)->toBe(Jobs::STATE_QUEUED);
});

it('refuses to enqueue a job whose capability is not granted', function (): void {
    $bare = Site::factory()->connected()->create();
    Connector::factory()->for($bare)->create();

    // Authorised: no capability, no job. Absence of a grant is a denial.
    expect(fn () => $this->jobs->enqueue($bare, Jobs::INVENTORY_REFRESH))
        ->toThrow(JobRejectedException::class);

    expect(RemoteJob::query()->count())->toBe(0);
});

it('cancels a queued job when its capability is revoked before it is claimed', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK);

    app(CapabilityService::class)->revoke($this->site, 'updates:read', null, 'test', 'System');

    $claimed = $this->jobs->claimFor($this->site->fresh(), $this->connector);

    // Authorisation is re-checked at claim time. Without that, revoking a capability would only stop
    // work nobody had asked for yet - the queued job would still run.
    expect($claimed)->toHaveCount(0)
        ->and($job->fresh()->state)->toBe(Jobs::STATE_CANCELLED)
        ->and($job->fresh()->failure_reason)->toContain('capability revoked');
});

it('validates a reported result against the job definition', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);
    $this->jobs->claimFor($this->site, $this->connector);

    // Validated: a connector cannot report a shape the platform never asked for.
    expect(fn () => $this->jobs->report(
        site: $this->site,
        connector: $this->connector,
        jobExternalId: $job->external_id,
        succeeded: true,
        result: ['reported' => true, 'stolen_data' => 'entries'],
    ))->toThrow(JobRejectedException::class);

    expect($job->fresh()->state)->toBe(Jobs::STATE_FAILED)
        ->and($job->fresh()->result)->toBeNull();
});

it('audits every transition, including the refusals', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);
    $this->jobs->claimFor($this->site, $this->connector);
    $this->jobs->report($this->site, $this->connector, $job->external_id, true, ['reported' => true]);

    try {
        $this->jobs->enqueue($this->site, 'console.run');
    } catch (JobRejectedException) {
        // expected
    }

    $actions = AuditEvent::query()->pluck('action')->all();

    // Audited: a refused job is more interesting than a successful one, not less.
    expect($actions)->toContain('job.queued')
        ->and($actions)->toContain('job.claimed')
        ->and($actions)->toContain('job.succeeded')
        ->and($actions)->toContain('job.refused');
});

it('records the audit description rather than only the type', function (): void {
    $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK);

    $event = AuditEvent::query()->where('action', 'job.queued')->firstOrFail();

    // The definition carries plain words for the log, so an audit trail reads as a history rather
    // than as a list of identifiers.
    expect($event->after['description'])->toBe('Requested an update check')
        ->and($event->after['capability'])->toBe('updates:read');
});

// --------------------------------------------------------------------------------------------------
// Invariant 16 for jobs: a retry must not run work twice.
// --------------------------------------------------------------------------------------------------

it('hands one job to only one claimer', function (): void {
    $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);

    $first = $this->jobs->claimFor($this->site, $this->connector);
    $second = $this->jobs->claimFor($this->site, $this->connector);

    // The claim is a conditional UPDATE, so a repeated claim gets different work or nothing - never
    // the same job twice.
    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(0);
});

it('collapses two enqueue attempts carrying the same idempotency key', function (): void {
    $first = $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, idempotencyKey: 'nightly-check');
    $second = $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, idempotencyKey: 'nightly-check');

    expect($second->id)->toBe($first->id)
        ->and(RemoteJob::query()->count())->toBe(1);
});

it('accepts a repeated result quietly rather than erroring', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);
    $this->jobs->claimFor($this->site, $this->connector);

    $this->jobs->report($this->site, $this->connector, $job->external_id, true, ['reported' => true]);

    // The retry case: the connector reported, the response was lost, it reported again. Treating
    // that as an error would push a connector into a loop over work already done.
    $again = $this->jobs->report($this->site, $this->connector, $job->external_id, true, ['reported' => true]);

    expect($again->state)->toBe(Jobs::STATE_SUCCEEDED)
        ->and(AuditEvent::query()->where('action', 'job.succeeded')->count())->toBe(1);
});

it('refuses a result from a connector that did not claim the job', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);
    $this->jobs->claimFor($this->site, $this->connector);

    $other = Connector::factory()->for(Site::factory()->connected())->create();

    expect(fn () => $this->jobs->report($this->site, $other, $job->external_id, true, ['reported' => true]))
        ->toThrow(JobRejectedException::class);

    expect($job->fresh()->state)->toBe(Jobs::STATE_CLAIMED);
});

it('will not let one site report on another site job', function (): void {
    $job = $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);
    $this->jobs->claimFor($this->site, $this->connector);

    $otherSite = Site::factory()->connected()->create();
    $otherConnector = Connector::factory()->for($otherSite)->create();

    // Scoped to the reporting site, so a valid identifier from elsewhere reaches nothing.
    expect(fn () => $this->jobs->report($otherSite, $otherConnector, $job->external_id, true, ['reported' => true]))
        ->toThrow(JobRejectedException::class);
});

// --------------------------------------------------------------------------------------------------
// Bounded runtime and signed instructions.
// --------------------------------------------------------------------------------------------------

it('expires a claimed job that outran its maximum runtime', function (): void {
    $job = RemoteJob::factory()->for($this->site)->overdue()->create();

    expect(app(JobService::class)->expireOverdue())->toBe(1);

    // Written by the sweep rather than inferred on read, so "claimed" always means a connector is
    // genuinely expected to report, and the log records when the platform gave up.
    expect($job->fresh()->state)->toBe(Jobs::STATE_EXPIRED)
        ->and(AuditEvent::query()->where('action', 'job.expired')->count())->toBe(1);
});

it('leaves a claimed job alone while it is still within its runtime', function (): void {
    $job = RemoteJob::factory()->for($this->site)->claimed()->create();

    expect(app(JobService::class)->expireOverdue())->toBe(0)
        ->and($job->fresh()->state)->toBe(Jobs::STATE_CLAIMED);
});

it('signs the claim response, because it carries instructions', function (): void {
    $this->jobs->enqueue($this->site, Jobs::INVENTORY_REFRESH);

    $nonce = Nonce::generate();

    $response = postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        [],
        $this->site,
        $this->keypair['secret'],
        ['nonce' => $nonce],
    );

    $response->assertOk();

    $signature = str_replace(Protocol::SIGNATURE_SCHEME.'=', '', (string) $response->headers->get(Protocol::HEADER_SIGNATURE));

    $canonical = new CanonicalResponse($this->site->external_id, $nonce, 200, $response->getContent());

    // Without this, anything between the connector and the platform could hand a site a job the
    // platform never issued.
    expect($canonical->verify($signature, $this->platformKeypair['public']))->toBeTrue();

    // And bound to this request: the same body against another nonce must not verify.
    $replayed = new CanonicalResponse($this->site->external_id, Nonce::generate(), 200, $response->getContent());

    expect($replayed->verify($signature, $this->platformKeypair['public']))->toBeFalse();
});

it('hands over only the fields a connector needs to act', function (): void {
    $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, ['force' => true]);

    $response = postSignedConnectorRequest(
        '/api/connector/v1/jobs/claim',
        [],
        $this->site,
        $this->keypair['secret'],
    );

    $envelope = $response->json('jobs.0');

    // A type, a schema version, validated parameters and a deadline. No instruction to interpret.
    expect(array_keys($envelope))->toBe(['id', 'type', 'schema_version', 'parameters', 'expires_at', 'max_runtime'])
        ->and($envelope['type'])->toBe(Jobs::UPDATES_CHECK)
        ->and($envelope['parameters'])->toBe(['force' => true]);
});

it('bounds how many jobs one claim returns', function (): void {
    foreach (range(1, Jobs::MAX_CLAIM_BATCH + 4) as $i) {
        $this->jobs->enqueue($this->site, Jobs::UPDATES_CHECK, ['force' => (bool) ($i % 2)], idempotencyKey: "job-{$i}");
    }

    // A site returning from a long outage works through a backlog in batches rather than being
    // handed more than it can finish before they expire.
    expect($this->jobs->claimFor($this->site, $this->connector))->toHaveCount(Jobs::MAX_CLAIM_BATCH);
});

it('refuses to enqueue for a site with no connector', function (): void {
    $orphan = Site::factory()->create();
    CapabilityGrant::factory()->for($orphan)->capability('inventory:read')->create();

    // Queueing work for a site that cannot collect it just accumulates jobs that will expire.
    expect(fn () => $this->jobs->enqueue($orphan, Jobs::INVENTORY_REFRESH))
        ->toThrow(JobRejectedException::class);
});
