<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\UpdateReport;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What needs updating across the fleet.
 *
 * Ordered by urgency rather than by name: a security release somewhere is the thing that should be
 * read first, and alphabetical order buries it. Sites that have never reported are listed separately
 * rather than shown as up to date, because "no updates available" and "we do not know" are different
 * answers and conflating them is how a stale site gets ignored.
 */
final class UpdateController
{
    public function index(Organisation $organisation): View
    {
        $sites = Site::query()
            ->where('organisation_id', $organisation->id)
            ->active()
            ->orderByDesc('has_security_release')
            ->orderByDesc('available_updates')
            ->orderBy('name')
            ->get();

        // One query for the latest report per site, rather than one per row.
        $latest = UpdateReport::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderByDesc('received_at')
            ->get()
            ->unique('site_id')
            ->keyBy('site_id');

        [$reported, $unreported] = $sites->partition(
            fn (Site $site): bool => $latest->has($site->id)
        );

        return view('updates.index', [
            'reported' => $reported->values(),
            'unreported' => $unreported->values(),
            'reports' => $latest,
            'summary' => $this->summarise($reported, $latest),
            'canRequest' => app(Membership::class)->canAdminister(),
        ]);
    }

    /**
     * Ask a site to check for updates now.
     *
     * Enqueues a job rather than reaching into the site: the platform has no way to contact a
     * connector, so this waits for the site to come and ask. The interface says so, rather than
     * implying an immediate result it cannot deliver.
     */
    public function refresh(Request $request, Site $site, JobService $jobs): RedirectResponse
    {
        abort_if($site->organisation_id !== app(Organisation::class)->id, 404);
        abort_unless(app(Membership::class)->canAdminister(), 403);

        try {
            $jobs->enqueue(
                site: $site,
                type: Jobs::UPDATES_CHECK,
                parameters: ['force' => true],
                actor: $request->user(),
                // One outstanding check per site. Pressing the button twice should not queue two.
                idempotencyKey: 'updates-check-manual',
            );
        } catch (JobRejectedException $e) {
            return back()->with('warning', match ($e->reason) {
                JobRejectedException::CAPABILITY_NOT_GRANTED => "Grant updates:read to {$site->name} first.",
                JobRejectedException::SITE_NOT_CONNECTED => "{$site->name} has no connector, so it cannot be asked.",
                default => "Could not request a check for {$site->name}.",
            });
        }

        return back()->with(
            'status',
            "Queued an update check for {$site->name}. It will run the next time the site checks in.",
        );
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, UpdateReport>  $reports
     * @return array{sites: int, withUpdates: int, security: int, abandoned: int, oldestCheck: ?Carbon}
     */
    private function summarise(Collection $sites, Collection $reports): array
    {
        $withUpdates = 0;
        $security = 0;
        $abandoned = 0;
        $oldest = null;

        foreach ($sites as $site) {
            /** @var UpdateReport|null $report */
            $report = $reports->get($site->id);

            if ($report === null) {
                continue;
            }

            if ($report->totalUpdates() > 0) {
                $withUpdates++;
            }

            if ($report->craft_security_release || $report->plugin_security_releases > 0) {
                $security++;
            }

            $abandoned += $report->abandoned_plugins;

            if ($oldest === null || $report->checked_at->lt($oldest)) {
                $oldest = $report->checked_at;
            }
        }

        return [
            'sites' => $sites->count(),
            'withUpdates' => $withUpdates,
            'security' => $security,
            'abandoned' => $abandoned,
            'oldestCheck' => $oldest,
        ];
    }
}
