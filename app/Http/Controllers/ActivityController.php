<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogQuery;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The organisation's audit history, read-only.
 *
 * There is no delete, no edit and no bulk action, because there is nothing to offer: the table
 * rejects mutation at the database. This screen is a window onto it.
 */
final class ActivityController
{
    public function __construct(private readonly AuditLogQuery $log) {}

    public function index(Request $request, Organisation $organisation): View
    {
        return view('activity.index', [
            'events' => $this->log->forOrganisation(
                organisation: $organisation,
                siteExternalId: $request->query('site') ? (string) $request->query('site') : null,
                outcome: $request->query('outcome') ? (string) $request->query('outcome') : null,
            ),
            'filters' => [
                'site' => (string) $request->query('site', ''),
                'outcome' => (string) $request->query('outcome', ''),
            ],

            // Every site in this organisation, whether or not it has been written about yet: the
            // fleet log is reached from a site's own page, so the site it was opened for has to be
            // in the list. Scoped by organisation, so another tenant's site is never offered.
            'filterSites' => Site::query()
                ->where('organisation_id', $organisation->id)
                ->orderBy('name')
                ->get(['id', 'external_id', 'name']),
        ]);
    }
}
