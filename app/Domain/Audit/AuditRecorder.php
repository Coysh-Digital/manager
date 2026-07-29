<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only supported way to write an audit event.
 *
 * Two things happen here that cannot be left to call sites:
 *
 *  - Every event is chained. Each carries the hash of its predecessor within the same
 *    organisation, so altering or removing one breaks verification for everything after it.
 *  - Everything is screened by {@see SecretGuard} before it is written.
 *
 * Appends are serialised per organisation with a transaction-scoped advisory lock. Without it two
 * concurrent writers would read the same predecessor, compute the same sequence number, and one
 * would lose to the unique index — or worse, both would chain from the same point and leave a fork
 * that verification could not distinguish from tampering.
 */
final class AuditRecorder
{
    /**
     * Arbitrary namespace for the advisory lock, keeping it from colliding with any other
     * advisory lock the application might take.
     */
    private const LOCK_NAMESPACE = 8_242_001;

    public function __construct(
        private readonly CorrelationId $correlationId,
        private readonly SecretGuard $secretGuard,
    ) {}

    /**
     * Record one event.
     *
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     *
     * @throws SecretLeakException if either payload appears to contain a secret
     */
    public function record(
        string $action,
        ?Organisation $organisation = null,
        ?Site $site = null,
        ?User $actor = null,
        string $actorType = AuditEvent::ACTOR_USER,
        ?string $actorLabel = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        string $outcome = AuditEvent::OUTCOME_SUCCESS,
        ?string $failureReason = null,
        ?Request $request = null,
    ): AuditEvent {
        $this->secretGuard->assertSafe($before, 'before');
        $this->secretGuard->assertSafe($after, 'after');

        // A site always implies its organisation. Taking it from the site rather than trusting the
        // caller keeps an event from being filed against the wrong tenant.
        $organisation ??= $site?->organisation;

        $request ??= request();

        $attributes = [
            'organisation_id' => $organisation?->id,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            // Falls through an empty name to the email, so an account created without a display
            // name still produces a legible audit line.
            'actor_label' => $actorLabel ?: ($actor?->name ?: $actor?->email),
            'site_id' => $site?->id,
            'site_label' => $site?->name,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $request?->ip(),
            'user_agent' => $this->truncate($request?->userAgent()),
            'correlation_id' => $this->correlationId->get(),
            'before' => $before,
            'after' => $after,
            'outcome' => $outcome,
            'failure_reason' => $failureReason,
        ];

        return DB::transaction(function () use ($attributes, $organisation): AuditEvent {
            $this->lockChain($organisation);

            $previous = AuditEvent::query()
                ->where('organisation_id', $organisation?->id)
                ->orderByDesc('seq')
                ->first();

            $attributes['seq'] = $previous === null ? 1 : $previous->seq + 1;
            $attributes['prev_hash'] = $previous === null ? AuditEvent::GENESIS_HASH : $previous->hash;
            $attributes['created_at'] = Carbon::now();
            $attributes['hash'] = self::hashFor($attributes);

            return AuditEvent::query()->create($attributes);
        });
    }

    /**
     * Compute the chain hash for an event.
     *
     * Public and static so the verifier recomputes hashes with exactly the same code that wrote
     * them. Two implementations of "canonical" would drift and produce false tampering reports.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function hashFor(array $attributes): string
    {
        $material = [
            'organisation_id' => $attributes['organisation_id'] ?? null,
            'seq' => $attributes['seq'],
            'actor_type' => $attributes['actor_type'] ?? null,
            'actor_id' => $attributes['actor_id'] ?? null,
            'actor_label' => $attributes['actor_label'] ?? null,
            'site_id' => $attributes['site_id'] ?? null,
            'site_label' => $attributes['site_label'] ?? null,
            'action' => $attributes['action'],
            'target_type' => $attributes['target_type'] ?? null,
            'target_id' => $attributes['target_id'] ?? null,
            'ip' => $attributes['ip'] ?? null,
            'user_agent' => $attributes['user_agent'] ?? null,
            'correlation_id' => $attributes['correlation_id'] ?? null,
            'before' => self::sortRecursively($attributes['before'] ?? null),
            'after' => self::sortRecursively($attributes['after'] ?? null),
            'outcome' => $attributes['outcome'] ?? null,
            'failure_reason' => $attributes['failure_reason'] ?? null,
            'created_at' => self::timestamp($attributes['created_at']),
        ];

        $canonical = json_encode(
            $material,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return hash('sha256', $attributes['prev_hash'].$canonical);
    }

    /**
     * Serialise appends to one organisation's chain for the life of the transaction.
     *
     * An advisory lock rather than a row lock, because the first event in a chain has no
     * predecessor row to lock. Postgres releases it automatically at commit or rollback.
     */
    private function lockChain(?Organisation $organisation): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [
            self::LOCK_NAMESPACE,
            // 0 stands for the platform chain, which has no organisation.
            $organisation === null ? 0 : $organisation->id,
        ]);
    }

    /**
     * Sort nested structures by key so that hashing is insensitive to insertion order.
     */
    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(static fn (mixed $item): mixed => self::sortRecursively($item), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    private static function timestamp(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->toIso8601String()
            : Carbon::parse((string) $value)->toIso8601String();
    }

    private function truncate(?string $value, int $limit = 512): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
