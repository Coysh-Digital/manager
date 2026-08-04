<?php

declare(strict_types=1);

use App\Domain\Audit\AuditChainVerifier;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\SecretLeakException;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 13, and the audit-logging requirements generally.
 *
 * "Append-only" and "tamper-evident" are claims that have to be demonstrated, not asserted. These
 * tests exercise the three layers that make them true: the model, the database trigger, and the
 * hash chain.
 */
beforeEach(function (): void {
    $this->recorder = app(AuditRecorder::class);
    $this->organisation = Organisation::factory()->create();
});

it('chains events so each one commits to its predecessor', function (): void {
    $first = $this->recorder->record(action: 'first.event', organisation: $this->organisation);
    $second = $this->recorder->record(action: 'second.event', organisation: $this->organisation);

    expect($first->seq)->toBe(1)
        ->and($first->prev_hash)->toBe(AuditEvent::GENESIS_HASH)
        ->and($second->seq)->toBe(2)
        ->and($second->prev_hash)->toBe($first->hash);
});

it('keeps a separate chain per organisation', function (): void {
    $other = Organisation::factory()->create();

    $this->recorder->record(action: 'a', organisation: $this->organisation);
    $mine = $this->recorder->record(action: 'b', organisation: $this->organisation);
    $theirs = $this->recorder->record(action: 'c', organisation: $other);

    // One tenant's activity must not be inferable from another's sequence numbers.
    expect($mine->seq)->toBe(2)
        ->and($theirs->seq)->toBe(1);
});

it('gives platform-level events a chain of their own', function (): void {
    $first = $this->recorder->record(action: 'platform.setup', actorType: AuditEvent::ACTOR_SYSTEM);
    $second = $this->recorder->record(action: 'platform.key.rotated', actorType: AuditEvent::ACTOR_SYSTEM);

    // NULLS NOT DISTINCT on the unique index is what stops these colliding at seq 1.
    expect($first->seq)->toBe(1)
        ->and($second->seq)->toBe(2)
        ->and($second->prev_hash)->toBe($first->hash);
});

it('verifies an untouched chain', function (): void {
    $site = Site::factory()->for($this->organisation)->create();

    foreach (range(1, 5) as $i) {
        // Deliberately varied: events with payloads, events with a site and actor, and events
        // with neither. An earlier version of the verifier passed this test only because every
        // event had null payloads, and hashed the raw JSON string for any event that did not —
        // so a chain of real events reported itself as tampered with.
        $this->recorder->record(
            action: "event.{$i}",
            organisation: $this->organisation,
            site: $i % 2 === 0 ? $site : null,
            before: $i % 2 === 0 ? ['state' => 'granted', 'nested' => ['a' => 1]] : null,
            after: $i % 2 === 0 ? ['state' => 'revoked', 'list' => [1, 2, 3]] : null,
        );
    }

    $result = app(AuditChainVerifier::class)->verify($this->organisation->id);

    expect($result->isIntact())->toBeTrue()
        ->and($result->eventsChecked)->toBe(5)
        ->and($result->problems)->toBe([]);
});

it('verifies a chain built from mixed payload shapes', function (array $before, array $after): void {
    $this->recorder->record(action: 'shape', organisation: $this->organisation, before: $before, after: $after);

    expect(app(AuditChainVerifier::class)->verify($this->organisation->id)->problems)->toBe([]);
})->with([
    'nested maps' => [['a' => ['b' => ['c' => 1]]], ['a' => ['b' => ['c' => 2]]]],
    'lists' => [['items' => [1, 2, 3]], ['items' => [3, 2, 1]]],
    'mixed types' => [['n' => 1, 's' => 'x', 'b' => true], ['n' => 2, 's' => 'y', 'b' => false]],
    'empty' => [[], []],
    'unicode' => [['name' => 'Coysh Digitál'], ['name' => 'Coysh Digital']],
    'slashes' => [['url' => 'https://example.org/a/b'], ['url' => 'https://example.org/c']],
]);

it('refuses to update or delete through the model', function (): void {
    $event = $this->recorder->record(action: 'test.event', organisation: $this->organisation);

    // Caught in the application so a stray save() fails somewhere legible, rather than surfacing
    // as a database error much further down.
    expect(fn () => $event->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

it('refuses to update or delete at the database, bypassing the model entirely', function (): void {
    $event = $this->recorder->record(action: 'test.event', organisation: $this->organisation);

    // This is the layer that matters: it holds against raw SQL, and against the table owner.
    //
    // Each attempt runs inside its own savepoint. A raised exception aborts the surrounding
    // Postgres transaction, so without one the first rejection would poison every later statement
    // and the remaining assertions would be testing nothing.
    $attempt = fn (string $sql, array $bindings = []) => fn () => DB::transaction(
        fn () => DB::statement($sql, $bindings)
    );

    expect($attempt('UPDATE audit_events SET action = ? WHERE id = ?', ['tampered', $event->id]))
        ->toThrow(QueryException::class);

    expect($attempt('DELETE FROM audit_events WHERE id = ?', [$event->id]))
        ->toThrow(QueryException::class);

    expect($attempt('TRUNCATE audit_events'))
        ->toThrow(QueryException::class);

    expect(AuditEvent::query()->find($event->id))->not->toBeNull();
});

it('detects an altered event even when the trigger is circumvented', function (): void {
    $this->recorder->record(action: 'first', organisation: $this->organisation);
    $target = $this->recorder->record(action: 'second', organisation: $this->organisation);
    $this->recorder->record(action: 'third', organisation: $this->organisation);

    // Simulate an attacker with enough database access to drop the trigger. The chain is the
    // backstop for exactly this: prevention has failed, so detection has to work.
    DB::unprepared('ALTER TABLE audit_events DISABLE TRIGGER audit_events_reject_mutation');
    DB::statement('UPDATE audit_events SET action = ? WHERE id = ?', ['covered up', $target->id]);
    DB::unprepared('ALTER TABLE audit_events ENABLE TRIGGER audit_events_reject_mutation');

    $result = app(AuditChainVerifier::class)->verify($this->organisation->id);

    expect($result->isIntact())->toBeFalse()
        ->and(implode(' ', $result->problems))->toContain('altered');
});

it('detects a removed event', function (): void {
    $this->recorder->record(action: 'first', organisation: $this->organisation);
    $target = $this->recorder->record(action: 'second', organisation: $this->organisation);
    $this->recorder->record(action: 'third', organisation: $this->organisation);

    DB::unprepared('ALTER TABLE audit_events DISABLE TRIGGER audit_events_reject_mutation');
    DB::statement('DELETE FROM audit_events WHERE id = ?', [$target->id]);
    DB::unprepared('ALTER TABLE audit_events ENABLE TRIGGER audit_events_reject_mutation');

    $result = app(AuditChainVerifier::class)->verify($this->organisation->id);

    // Both the sequence gap and the broken link give it away.
    expect($result->isIntact())->toBeFalse()
        ->and(implode(' ', $result->problems))->toContain('Expected sequence 2');
});

it('records failures as well as successes', function (): void {
    $event = $this->recorder->record(
        action: 'site.pairing.rejected',
        organisation: $this->organisation,
        actorType: AuditEvent::ACTOR_CONNECTOR,
        outcome: AuditEvent::OUTCOME_FAILURE,
        failureReason: 'enrolment code already consumed',
    );

    // A rejected pairing attempt is more interesting than a successful one, not less.
    expect($event->succeeded())->toBeFalse()
        ->and($event->failure_reason)->toBe('enrolment code already consumed');
});

it('survives deletion of the user and site it refers to, untouched', function (): void {
    $user = User::factory()->create(['name' => 'Tim Coysh']);
    $site = Site::factory()->for($this->organisation)->create(['name' => 'Example Site']);

    $event = $this->recorder->record(action: 'site.deleted', site: $site, actor: $user);
    $originalHash = $event->hash;

    $site->delete();
    $user->delete();

    $event->refresh();

    // Nothing rewrites the row. actor_id and site_id are plain columns rather than foreign keys
    // precisely so that removing a user or a site cannot require an UPDATE here - an UPDATE the
    // append-only trigger would refuse anyway.
    expect($event->actor_id)->toBe($user->id)
        ->and($event->site_id)->toBe($site->id)
        ->and($event->hash)->toBe($originalHash);

    // The denormalised labels are what keep the line legible now the rows are gone.
    expect($event->actor_label)->toBe('Tim Coysh')
        ->and($event->site_label)->toBe('Example Site')
        ->and($event->actor()->first())->toBeNull()
        ->and($event->site()->first())->toBeNull();

    // And the chain still verifies, because nothing about the event changed.
    expect(app(AuditChainVerifier::class)->verify($this->organisation->id)->isIntact())->toBeTrue();
});

it('refuses to write a secret into the audit log', function (string $key): void {
    // Invariant 13. Throwing rather than redacting: a silent redaction would let a call site keep
    // passing a password indefinitely with nobody noticing.
    expect(fn () => $this->recorder->record(
        action: 'test.event',
        organisation: $this->organisation,
        after: [$key => 'should never be written'],
    ))->toThrow(SecretLeakException::class);
})->with([
    'password' => ['password'],
    'db password' => ['database_password'],
    'api key' => ['api_key'],
    'private key' => ['private_key'],
    'session token' => ['session_token'],
    'authorization header' => ['authorization'],
]);

it('finds a secret nested inside a payload', function (): void {
    expect(fn () => $this->recorder->record(
        action: 'test.event',
        organisation: $this->organisation,
        after: ['connection' => ['host' => 'db.internal', 'credential' => 'hunter2']],
    ))->toThrow(SecretLeakException::class);
});

it('finds a secret hiding under an innocuous key', function (string $value): void {
    // Key-name matching only helps when the caller named the field honestly.
    expect(fn () => $this->recorder->record(
        action: 'test.event',
        organisation: $this->organisation,
        after: ['note' => $value],
    ))->toThrow(SecretLeakException::class);
})->with([
    'enrolment code' => ['mgr_enrol_Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MGFiY2RlZmdoaWpr'],
    'bcrypt hash' => ['$2y$13$abcdefghijklmnopqrstuv'],
    'encrypted payload' => ['eyJpdiI6ImFiYyIsInZhbHVlIjoiZGVmIn0='],
]);

it('still records the values an audit trail is for', function (): void {
    // The guard has to be precise enough to stay switched on: a public key and a key rotation
    // date are both safe and both genuinely useful.
    $event = $this->recorder->record(
        action: 'connector.rotated',
        organisation: $this->organisation,
        before: ['public_key' => 'wtTQtctkVi1Kxeol29zmBxJZiTP35EF6A51LAb12sRs=', 'key_rotated_at' => null],
        after: ['public_key' => 'aS+LLskhPPUYoa1/YNrONyibUJP7CXz7MOfloHurVZI=', 'key_rotated_at' => '2026-07-29T12:00:00+00:00'],
    );

    expect($event->before['public_key'])->toContain('wtTQ')
        ->and($event->after['key_rotated_at'])->toBe('2026-07-29T12:00:00+00:00');
});

it('never leaks the offending value in the exception message', function (): void {
    // This exception gets logged, and logging the secret is the thing being prevented.
    try {
        $this->recorder->record(
            action: 'test.event',
            organisation: $this->organisation,
            after: ['password' => 'hunter2'],
        );
    } catch (SecretLeakException $e) {
        expect($e->getMessage())->not->toContain('hunter2')
            ->and($e->getMessage())->toContain('after.password');

        return;
    }

    $this->fail('Expected a SecretLeakException.');
});

it('hashes independently of key ordering', function (): void {
    // Otherwise verification would report tampering whenever a payload was built in a different
    // order, and a check that cries wolf gets ignored.
    $attributes = [
        'seq' => 1,
        'action' => 'test',
        'prev_hash' => AuditEvent::GENESIS_HASH,
        'created_at' => now(),
        'after' => ['b' => 2, 'a' => 1, 'nested' => ['z' => 1, 'y' => 2]],
    ];

    $reordered = $attributes;
    $reordered['after'] = ['nested' => ['y' => 2, 'z' => 1], 'a' => 1, 'b' => 2];

    expect(AuditRecorder::hashFor($attributes))->toBe(AuditRecorder::hashFor($reordered));
});
