<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reading the audit log.
 *
 * Two screens want the same rows filtered slightly differently — the organisation's whole log, and
 * one site's — and both must be ordered by `seq` rather than by time. Sequence is the chain's own
 * order and the only one that cannot disagree with it: two events written in the same second sort
 * arbitrarily by timestamp, and an audit log whose order is arbitrary is not much of a chain.
 *
 * There is no delete and no edit here because there is nothing to offer: the table rejects mutation
 * at the database.
 */
final class AuditLogQuery
{
    /**
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    public function forOrganisation(Organisation $organisation, ?string $siteExternalId, ?string $outcome, int $perPage = 50): LengthAwarePaginator
    {
        return AuditEvent::query()
            ->where('organisation_id', $organisation->id)
            ->when($siteExternalId, fn ($query, $site) => $query->whereHas('site', fn ($q) => $q->where('external_id', $site)))
            ->when($outcome, fn ($query, $value) => $query->where('outcome', $value))
            ->latest('seq')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    public function forSite(Site $site, ?string $outcome, int $perPage = 50): LengthAwarePaginator
    {
        return AuditEvent::query()
            ->where('site_id', $site->id)
            ->when($outcome, fn ($query, $value) => $query->where('outcome', $value))
            ->latest('seq')
            ->paginate($perPage)
            ->withQueryString();
    }
}
