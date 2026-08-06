<?php

declare(strict_types=1);

use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;
use App\Models\AuditEvent;
use App\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/*
 | Three failures the platform could not see about itself.
 |
 | They share a shape, which is why they are in one file: each one is work that was asked for, did not
 | happen, and produced no symptom anybody was looking at. The request succeeded. The screen said so.
 |
 |  - **Failed jobs.** Laravel has written to `failed_jobs` since the first migration and nothing read
 |    it. A notification never delivered, a backup never fetched, an artifact never pruned.
 |  - **A stalled queue.** The check existed and was inert on the shipped driver: it answered only for
 |    `database` while `.env.example` ships `QUEUE_CONNECTION=redis`, so it reported the depth and
 |    never judged it.
 |  - **A broken audit chain.** The command is scheduled, and a scheduled command's output goes
 |    nowhere. The one execution most likely to find a break reported it to nobody, while the
 |    troubleshooting page tells the reader what to do "if this fails" as though they would find out.
 */

function healthCheck(string $name): Check
{
    foreach (app(Diagnostics::class)->all() as $check) {
        if ($check->name === $name) {
            return $check;
        }
    }

    throw new RuntimeException("No diagnostic named '{$name}'.");
}

/*
|--------------------------------------------------------------------------------------------------
| Failed jobs
|--------------------------------------------------------------------------------------------------
*/

it('says nothing when no job has been written off', function (): void {
    expect(healthCheck('Failed jobs')->warned())->toBeFalse()
        ->and(healthCheck('Failed jobs')->failed())->toBeFalse();
});

it('reports jobs that failed every attempt', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Something went wrong',
        'failed_at' => now(),
    ]);

    $check = healthCheck('Failed jobs');

    // A warning at any count, never a failure. One transient error is not a reason to pull an
    // instance out of rotation, and a check that does becomes a check people skip.
    expect($check->warned())->toBeTrue()
        ->and($check->failed())->toBeFalse()
        ->and($check->detail)->toContain('1 job')
        ->and($check->remedy)->toContain('queue:failed');
});

/*
|--------------------------------------------------------------------------------------------------
| A stalled queue, on the driver that ships
|--------------------------------------------------------------------------------------------------
*/

it('measures the age of the oldest waiting job on redis, not only on database', function (): void {
    /*
     | The gap this closes. `.env.example` ships QUEUE_CONNECTION=redis, and the stall check answered
     | only for `database` - so on a stock installation it reported the depth of the queue and never
     | judged how long anything had been sitting in it.
     |
     | Pushed as a raw payload in Laravel's own shape, at the key its RedisQueue builds, so this
     | exercises the reading rather than a fixture of our own invention.
    */
    config(['queue.default' => 'redis']);

    $old = json_encode(['uuid' => 'x', 'displayName' => 'Test', 'createdAt' => now()->subHour()->timestamp]);

    Redis::connection('default')->del('queues:default');
    Redis::connection('default')->rpush('queues:default', [$old]);

    try {
        $check = healthCheck('Queue');

        expect($check->failed())->toBeTrue()
            ->and($check->detail)->toContain('60 minutes');
    } finally {
        Redis::connection('default')->del('queues:default');
    }
});

it('does not judge a redis queue whose payload it cannot read', function (): void {
    // A health check that threw because a payload had a shape it did not recognise would be worse
    // than the gap it is closing.
    config(['queue.default' => 'redis']);

    Redis::connection('default')->del('queues:default');
    Redis::connection('default')->rpush('queues:default', ['not json at all']);

    try {
        expect(healthCheck('Queue')->failed())->toBeFalse();
    } finally {
        Redis::connection('default')->del('queues:default');
    }
});

/*
|--------------------------------------------------------------------------------------------------
| A broken audit chain
|--------------------------------------------------------------------------------------------------
*/

it('logs a broken audit chain at critical, so an unattended run is not silent', function (): void {
    $organisation = Organisation::factory()->create();

    AuditEvent::factory()->for($organisation)->create();

    // Break the chain by rewriting a hash the next row is linked against. The trigger refuses
    // ordinary mutation, so this goes round it - which is exactly the tampering being detected.
    DB::statement('ALTER TABLE audit_events DISABLE TRIGGER audit_events_reject_mutation');
    DB::table('audit_events')->where('organisation_id', $organisation->id)->update(['hash' => str_repeat('0', 64)]);
    DB::statement('ALTER TABLE audit_events ENABLE TRIGGER audit_events_reject_mutation');

    Log::spy();

    $this->artisan('manager:audit:verify')->assertFailed();

    // critical rather than error: this is not a failed job to retry, it is evidence that history has
    // been rewritten, and every aggregator worth having alerts on critical and samples error.
    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Audit chain verification failed')
            && $context['chains'] !== []);
});

it('says nothing to the log when every chain is intact', function (): void {
    // An alert that fires on success is an alert nobody reads.
    Organisation::factory()->create();

    Log::spy();

    $this->artisan('manager:audit:verify')->assertSuccessful();

    Log::shouldNotHaveReceived('critical');
});
