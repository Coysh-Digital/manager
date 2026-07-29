<?php

declare(strict_types=1);

namespace App\Domain\Capability;

use App\Domain\Audit\AuditRecorder;
use App\Models\CapabilityEvent;
use App\Models\CapabilityGrant;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grants and revokes what Manager may do on a site.
 *
 * Every transition writes two records: a {@see CapabilityEvent} carrying the full before-and-after
 * detail the specification asks for, and an audit event. The grants table only knows where things
 * stand now, which is not enough to answer "who turned this on, and when".
 *
 * Capabilities that modify a site, or read its content, can never be granted here automatically —
 * see {@see self::grantDefaultsForPairing()}.
 */
final class CapabilityService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * The capabilities a newly paired site receives.
     *
     * Invariant 6: monitoring access is read-only by default. Phase 1 registers `inventory:read`
     * only; the rest of the read set joins it as each is implemented. Nothing that writes to a
     * site, and nothing that reads its content, ever appears here — those need a separate,
     * deliberate confirmation.
     *
     * @return list<string>
     */
    public static function pairingDefaults(): array
    {
        return ['inventory:read'];
    }

    /**
     * Grant the default read-only set to a freshly paired site.
     *
     * @return list<string>
     */
    public function grantDefaultsForPairing(Site $site): array
    {
        $granted = [];

        foreach (self::pairingDefaults() as $capability) {
            // Belt and braces. If someone ever adds a write capability to the defaults, this stops
            // it reaching a site rather than trusting the list to have stayed honest.
            if (! Protocol::isReadOnlyCapability($capability)) {
                continue;
            }

            $this->apply(
                site: $site,
                capability: $capability,
                newState: CapabilityGrant::STATE_GRANTED,
                actor: null,
                actorLabel: 'Pairing',
                reason: 'Granted automatically when the site was paired',
            );

            $granted[] = $capability;
        }

        return $granted;
    }

    /**
     * Grant a capability, recording who asked and why.
     */
    public function grant(Site $site, string $capability, User $actor, ?string $reason = null): void
    {
        $this->assertKnown($capability);

        $this->apply($site, $capability, CapabilityGrant::STATE_GRANTED, $actor, null, $reason);
    }

    /**
     * Revoke a capability.
     */
    public function revoke(Site $site, string $capability, ?User $actor, ?string $reason = null, ?string $actorLabel = null): void
    {
        $this->assertKnown($capability);

        $this->apply($site, $capability, CapabilityGrant::STATE_REVOKED, $actor, $actorLabel, $reason);
    }

    /**
     * Revoke everything a site holds, in one transaction.
     *
     * Used when a site is removed or its connector is revoked: the credentials going away and the
     * permissions going away must not be separable.
     */
    public function revokeAll(Site $site, ?User $actor, string $reason): void
    {
        DB::transaction(function () use ($site, $actor, $reason): void {
            foreach ($site->grantedCapabilities() as $capability) {
                $this->apply($site, $capability, CapabilityGrant::STATE_REVOKED, $actor, null, $reason);
            }
        });
    }

    /**
     * Write the transition, its history entry and its audit event as one unit.
     */
    private function apply(
        Site $site,
        string $capability,
        string $newState,
        ?User $actor,
        ?string $actorLabel,
        ?string $reason,
    ): void {
        DB::transaction(function () use ($site, $capability, $newState, $actor, $actorLabel, $reason): void {
            $grant = CapabilityGrant::query()
                ->where('site_id', $site->id)
                ->where('capability', $capability)
                ->lockForUpdate()
                ->first();

            $previousState = $grant?->state;

            if ($previousState === $newState) {
                // Nothing changed. Recording it anyway would fill the history with noise and make
                // the entries that do matter harder to find.
                return;
            }

            $now = Carbon::now();

            $attributes = [
                'site_id' => $site->id,
                'capability' => $capability,
                'state' => $newState,
                'reason' => $reason,
            ];

            if ($newState === CapabilityGrant::STATE_GRANTED) {
                $attributes['granted_by'] = $actor?->id;
                $attributes['granted_at'] = $now;
            } else {
                $attributes['revoked_by'] = $actor?->id;
                $attributes['revoked_at'] = $now;
            }

            CapabilityGrant::query()->updateOrCreate(
                ['site_id' => $site->id, 'capability' => $capability],
                $attributes,
            );

            CapabilityEvent::query()->create([
                'site_id' => $site->id,
                'capability' => $capability,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'actor_id' => $actor?->id,
                'actor_label' => $actorLabel ?: ($actor?->name ?: $actor?->email),
                'reason' => $reason,
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'correlation_id' => $this->correlationId->get(),
                'created_at' => $now,
            ]);

            $this->audit->record(
                action: $newState === CapabilityGrant::STATE_GRANTED ? 'capability.granted' : 'capability.revoked',
                site: $site,
                actor: $actor,
                actorType: $actor === null ? 'system' : 'user',
                actorLabel: $actorLabel,
                targetType: 'capability',
                targetId: $capability,
                before: ['state' => $previousState],
                after: ['state' => $newState, 'reason' => $reason],
            );
        });
    }

    private function assertKnown(string $capability): void
    {
        if (! in_array($capability, Protocol::capabilities(), true)) {
            throw new UnknownCapabilityException("'{$capability}' is not a capability this platform recognises.");
        }
    }
}
