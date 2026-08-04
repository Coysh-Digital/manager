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
     * site, and nothing that reads its content, ever appears here - those need a separate,
     * deliberate confirmation.
     *
     * @return list<string>
     */
    public static function pairingDefaults(): array
    {
        return ['inventory:read'];
    }

    /**
     * Capabilities an administrator may grant with a single switch.
     *
     * Read-only, every one, and every one implemented - offering a switch for something the connector
     * cannot do would be a lie told by an interface.
     *
     * The inverse is a lie too, and a noisier one. `runtime:read` and `logins:read` were implemented
     * on both sides - `SystemController` and `LoginsController` serve them, the connector schedules
     * both tasks, and `runtime_reports` and `login_reports` were created for them - but this list was
     * not extended when they landed. The screen therefore said "Not yet available" about a capability
     * that was available, no administrator could grant it, and the connector queued a task every
     * thirty minutes that could only ever fail. A site owner watching their own control panel saw a
     * permanent row of failed queue jobs from a plugin that monitors their site.
     *
     * Anything that reads a site's content is absent deliberately. `backups:create` reads the entire
     * database, including user records, so it is not a switch: see {@see self::confirmable()}.
     *
     * @return list<string>
     */
    public static function grantableFromInterface(): array
    {
        return [
            'inventory:read',
            'updates:read',
            'licences:read',
            'security:read',
            'system:read',
            'runtime:read',
            'logins:read',
        ];
    }

    /**
     * Capabilities that may be granted, but never with a switch.
     *
     * Invariant 7. These read or write something a read-only monitoring permission does not cover, so
     * granting one takes its own route, its own confirmation and its own acknowledgement - see
     * {@see self::grantConfirmed()}.
     *
     * @return list<string>
     */
    public static function confirmable(): array
    {
        return ['backups:create'];
    }

    /**
     * The acknowledgement an administrator must make before a capability in {@see self::confirmable()}
     * is granted.
     *
     * Held here rather than in a template because the exact wording is recorded in the audit log. If it
     * changes, the change is visible in a diff, and the audit log still says what was agreed to at the
     * time rather than what the current template happens to say.
     *
     * @throws UnknownCapabilityException
     */
    public static function acknowledgementFor(string $capability): string
    {
        return match ($capability) {
            'backups:create' => 'I understand that a backup contains the site\'s entire database, '
                .'including user accounts, password hashes, sessions and any personal information the '
                .'site holds, and that this organisation is responsible for it once it is stored.',
            default => throw new UnknownCapabilityException(
                "'{$capability}' is not a capability that requires an acknowledgement."
            ),
        };
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

        // Belt and braces over the route's own validation. Nothing that modifies a site can be
        // granted through this path, however it is called.
        if (! Protocol::isReadOnlyCapability($capability)) {
            throw new UnknownCapabilityException(
                "'{$capability}' modifies the site and cannot be granted here. It needs its own confirmation flow."
            );
        }

        $this->apply($site, $capability, CapabilityGrant::STATE_GRANTED, $actor, null, $reason);
    }

    /**
     * Grant a capability that needs more than a switch.
     *
     * Invariant 7, and the reason this is a separate method rather than a flag on {@see self::grant()}.
     * The two paths accept different capabilities and neither will accept the other's: a bug in a route
     * cannot turn a read-only grant into a backup permission, because the method that grants backups
     * refuses everything read-only and the method that grants read access refuses everything else.
     *
     * The acknowledgement is required and recorded verbatim. Somebody granting this is taking on
     * responsibility for a copy of every user record on the site, and the audit log should be able to
     * show exactly what they were told when they did.
     *
     * @throws UnknownCapabilityException
     */
    public function grantConfirmed(Site $site, string $capability, User $actor, string $reason): void
    {
        $this->assertKnown($capability);

        if (! in_array($capability, self::confirmable(), true)) {
            throw new UnknownCapabilityException(
                "'{$capability}' does not use the confirmation path. Grant it the ordinary way."
            );
        }

        $this->apply(
            site: $site,
            capability: $capability,
            newState: CapabilityGrant::STATE_GRANTED,
            actor: $actor,
            actorLabel: null,
            reason: $reason,
            acknowledgement: self::acknowledgementFor($capability),
        );
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
        ?string $acknowledgement = null,
    ): void {
        DB::transaction(function () use ($site, $capability, $newState, $actor, $actorLabel, $reason, $acknowledgement): void {
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
                after: array_filter([
                    'state' => $newState,
                    'reason' => $reason,
                    // Recorded verbatim when one was required, so the log shows what was agreed to
                    // rather than what the current wording happens to be.
                    'acknowledged' => $acknowledgement,
                ], static fn (mixed $value): bool => $value !== null),
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
