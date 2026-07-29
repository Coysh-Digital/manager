<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityService;
use App\Models\Connector;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removing a site.
 *
 * Invariant 14: removing a site must immediately revoke its credentials. "Immediately" is doing the
 * work in that sentence — the revocation happens in the same transaction as the removal, so there
 * is no window, however brief, in which a site is gone from the interface but its connector can
 * still authenticate.
 *
 * A site is archived rather than deleted. Its audit history has to stay readable, and a row that no
 * longer exists cannot explain what happened to it. Every enrolment code is consumed on the way out
 * as well: an unused code left behind would be a way back in to a site nobody is watching.
 */
final class SiteRemovalService
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    public function remove(Site $site, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($site, $actor, $reason): void {
            $connectors = $site->connectors()
                ->whereNotIn('state', [Connector::STATE_REVOKED])
                ->get();

            foreach ($connectors as $connector) {
                $connector->forceFill([
                    'state' => Connector::STATE_REVOKED,
                    'revoked_at' => now(),
                    'revoked_reason' => 'Site removed',
                ])->save();
            }

            // Credentials and permissions go together. Either alone leaves a state nobody can
            // reason about.
            $this->capabilities->revokeAll($site, $actor, 'Site removed');

            // An unconsumed code would let the site pair again after removal.
            $site->enrolmentCodes()
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'consumed_ip' => null]);

            $site->forceFill([
                'archived_at' => now(),
                'status' => Site::STATUS_NOT_CONNECTED,
            ])->save();

            $this->audit->record(
                action: 'site.removed',
                site: $site,
                actor: $actor,
                targetType: 'site',
                targetId: $site->external_id,
                before: ['status' => Site::STATUS_CONNECTED],
                after: [
                    'archived' => true,
                    'connectors_revoked' => $connectors->count(),
                    'reason' => $reason,
                ],
            );
        });
    }
}
