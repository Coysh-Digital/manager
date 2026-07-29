<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Organisation;
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
    public function index(Request $request, Organisation $organisation): View
    {
        $events = AuditEvent::query()
            ->where('organisation_id', $organisation->id)
            ->when($request->query('site'), fn ($query, $site) => $query->whereHas('site', fn ($q) => $q->where('external_id', $site)))
            ->when($request->query('outcome'), fn ($query, $outcome) => $query->where('outcome', $outcome))
            ->latest('seq')
            ->paginate(50)
            ->withQueryString();

        return view('activity.index', [
            'events' => $events,
            'filters' => [
                'site' => (string) $request->query('site', ''),
                'outcome' => (string) $request->query('outcome', ''),
            ],
        ]);
    }
}
