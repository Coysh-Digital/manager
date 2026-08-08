<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\FailedBackupJob;
use App\Domain\Backup\FailedBackupJobs;
use App\Domain\Capability\CapabilityService;
use App\Domain\Connector\NudgeDispatcher;
use App\Domain\Health\SiteUptime;
use App\Domain\Health\UptimeWindow;
use App\Domain\Job\JobRejectedException;
use App\Domain\Job\JobService;
use App\Domain\Pairing\EnrolmentService;
use App\Domain\Site\SiteRemovalService;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\AuditEvent;
use App\Models\BackupArtifact;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\RuntimeReport;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The fleet.
 *
 * Filters live in the query string rather than the session, so a filtered view can be linked to,
 * bookmarked and reloaded - which is what people actually do with a screen they leave open.
 */
final class SiteController
{
    use ResolvesSiteContext;

    /**
     * The window the fleet's Reporting column covers.
     *
     * Seven days rather than the thirty the site's own Health tab offers, and the trade is
     * deliberate. A day is too jumpy to trust on a list — one twenty-minute gap overnight reads as
     * 98.6% and is gone by tomorrow — and a month is roughly 8,600 heartbeats per site to pull back
     * for a single percentage. A week is stable enough to act on and about 2,000 rows.
     *
     * It is one of the windows SiteUptime::WINDOWS already offers, so the figure here and the figure
     * on the site's own screen are the same measurement over the same span rather than two numbers
     * that nearly agree.
     */
    public const REPORTING_WINDOW_HOURS = 168;

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly NudgeDispatcher $nudges,
    ) {}

    public function index(Request $request, Organisation $organisation): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'environment' => (string) $request->query('environment', ''),
        ];

        $sort = (string) $request->query('sort', '');
        $sites = $this->query($organisation, $filters)->get();

        // The latest runtime report per site, in one query rather than one per row. Only the two
        // figures the fleet table shows - a month of reports is a lot of jsonb to drag back for a
        // disk percentage.
        $runtime = $this->latestRuntimeFor($sites);

        // Both of these are one query for the whole page, for the reason latestRuntimeFor() gives.
        $backups = $this->latestBackupFor($sites);
        $reporting = app(SiteUptime::class)->forMany($sites, self::REPORTING_WINDOW_HOURS);

        /*
         | Grouped by what needs doing, not alphabetically. The whole point of the screen is to
         | answer "what needs me today", and sorting by name buries that.
         |
         | Sorting deliberately does not dissolve the groups: it orders *within* them. A fleet sorted
         | by disk with a healthy site at the top and a silent one buried underneath would be a
         | table that had forgotten what it was for. The one exception is that choosing a sort at all
         | is a signal somebody is looking for something specific, so the groups stay and the rows
         | inside them move.
         */
        $groups = [
            'Needs attention' => $sites->filter(fn (Site $site): bool => $this->needsAttention($site))->values(),
            'Steady' => $sites->filter(fn (Site $site): bool => $this->isSteady($site))->values(),
            'Not monitored' => $sites->filter(fn (Site $site): bool => $this->isUnmonitored($site))->values(),
        ];

        if (self::sortable()[$sort] ?? false) {
            $groups = array_map(
                fn (Collection $group): Collection => $this->sortGroup($group, $sort, $runtime, $backups, $reporting),
                $groups,
            );
        }

        return view('sites.index', [
            'groups' => $groups,
            'filters' => $filters,
            'sort' => $sort,
            'sortable' => self::sortable(),
            'runtime' => $runtime,
            'backups' => $backups,
            'reporting' => $reporting,
            'membership' => app(Membership::class),
            'grantableCapabilities' => CapabilityService::grantableFromInterface(),
            'totalSites' => $organisation->sites()->active()->count(),
            'shown' => $sites->count(),
            'summary' => $this->summary($organisation),
        ]);
    }

    /**
     * The Overview tab.
     *
     * What the site is and how it is behaving, and nothing else. Setting up a connector moved to
     * Settings, plugin detail to Updates, configuration flags to Security: this page used to hold
     * all of it, and opening a healthy site began with a re-pairing form.
     */
    public function show(Site $site): View
    {
        return view('sites.show', [
            ...$this->siteContext($site),
            'membership' => app(Membership::class),
            'latestReport' => $this->latestInventoryReport($site),
            'updateReport' => $this->latestUpdateReport($site),
            'latestBackup' => BackupArtifact::query()
                ->where('site_id', $site->id)
                ->stored()
                ->latest('taken_at')
                ->first(),
            'notes' => $site->notes()->with('author')->limit(25)->get(),
            'recentActivity' => AuditEvent::query()
                ->where('site_id', $site->id)
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    /**
     * Ask a site to report again now.
     *
     * Queues the work rather than doing it: the platform never calls out to a site, so a refresh is a
     * job the connector collects on its next check-in. The wording says so, because a button that
     * looked instantaneous and was not would be worse than no button.
     *
     * Any member may press it. It asks a site to re-send what it already sends on a schedule, which is
     * the least privileged useful thing in the interface, and the idempotency key means pressing it
     * repeatedly finds the outstanding job rather than queuing another.
     */
    public function refresh(Request $request, Site $site, JobService $jobs): RedirectResponse
    {
        $queued = $this->queueRefresh($site, $jobs, $request->user());

        if ($queued === []) {
            return back()->withErrors([
                'refresh' => 'Nothing to refresh: this site has no active connector.',
            ]);
        }

        return back()->with('status', $this->refreshMessage($site, $queued));
    }

    /**
     * Ask every connected site to report again.
     */
    public function refreshAll(Request $request, Organisation $organisation, JobService $jobs): RedirectResponse
    {
        $sites = $organisation->sites()->active()->with('capabilityGrants')->get();

        $touched = 0;
        $skipped = 0;
        $nudged = 0;

        foreach ($sites as $site) {
            if ($this->queueRefresh($site, $jobs, $request->user()) === []) {
                $skipped++;

                continue;
            }

            $touched++;

            if ($this->nudges->canReach($site)) {
                $nudged++;
            }
        }

        if ($touched === 0) {
            return back()->withErrors([
                'refresh' => 'No site has an active connector, so there is nothing to refresh.',
            ]);
        }

        // The skipped count is reported rather than swallowed. Silently refreshing four of twelve sites
        // and saying "refreshed" would be the sort of half-truth an operator only discovers later.
        $skippedNote = $skipped > 0
            ? sprintf(' %d skipped, having no active connector.', $skipped)
            : '';

        // Three shapes rather than one with a clause. A fleet nothing can be reached in reads exactly
        // as it always has, rather than being told that none of it is being asked to hurry.
        $outcome = match (true) {
            $nudged === 0 => 'They will report when each next checks in.',
            $nudged === $touched => 'Each is being asked to report now.',
            default => sprintf(
                '%d %s being asked to report now; the rest when each next checks in.',
                $nudged,
                $nudged === 1 ? 'is' : 'are',
            ),
        };

        return back()->with('status', sprintf(
            'Refresh queued for %d %s.%s %s',
            $touched,
            $touched === 1 ? 'site' : 'sites',
            $skippedNote,
            $outcome,
        ));
    }

    /**
     * Queue whatever this site is permitted to refresh.
     *
     * @return list<string> the job types queued
     */
    private function queueRefresh(Site $site, JobService $jobs, ?User $actor): array
    {
        $queued = [];

        // Inventory first, because it is what the fleet table shows. Updates only if permitted —
        // enqueue() would refuse it anyway, but asking for something a site cannot do would put a
        // refusal in the audit log on every press.
        foreach ([Jobs::INVENTORY_REFRESH => 'inventory:read', Jobs::UPDATES_CHECK => 'updates:read'] as $type => $capability) {
            if (! $site->hasCapability($capability)) {
                continue;
            }

            try {
                // The idempotency key is what makes repeated presses harmless. Without one,
                // outstandingFor() returns null and every press inserts another job - which is what
                // this did until a test pressed the button four times and found four jobs.
                //
                // Keyed on the type rather than the moment, so a second press finds the outstanding job
                // and a press after it has run queues a fresh one.
                $jobs->enqueue($site, $type, actor: $actor, idempotencyKey: 'refresh:'.$type);
                $queued[] = $type;
            } catch (JobRejectedException) {
                // No active connector, most likely. Reported to the caller as "nothing queued" rather
                // than as an error, because it is a state rather than a fault.
            }
        }

        return $queued;
    }

    /**
     * @param  list<string>  $queued
     */
    private function refreshMessage(Site $site, array $queued): string
    {
        $including = in_array(Jobs::UPDATES_CHECK, $queued, true) ? ', including an update check' : '';

        // "Being asked" rather than "will report": the nudge is queued, not delivered, and a site
        // behind a firewall answers nothing. That site gets the sentence it has always got.
        return $this->nudges->canReach($site)
            ? sprintf('Refresh queued for %s%s. The site is being asked to report now.', $site->name, $including)
            : sprintf('Refresh queued for %s. It will report when it next checks in%s.', $site->name, $including);
    }

    /**
     * Add a site and issue its first enrolment code.
     *
     * The code is shown once, on the redirect, and never again - only its hash is stored. Anybody who
     * misses it issues another, which is cheaper than having somewhere a live credential can be looked
     * up.
     */
    public function store(Request $request, Organisation $organisation, EnrolmentService $enrolment): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expected_domain' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:production,staging,development'],

            // The read-only set only. backups:create is absent from the rule, not merely from the form,
            // so a crafted request cannot reach it either - it has its own confirmation flow, and
            // invariant 7 is the reason.
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'in:'.implode(',', CapabilityService::grantableFromInterface())],
        ]);

        $domain = $enrolment->normaliseDomain($validated['expected_domain']);

        if ($domain === '' || ! str_contains($domain, '.')) {
            return back()->withErrors([
                'expected_domain' => 'That does not look like a domain. Use the host the site is served from, such as example.org.',
            ])->withInput();
        }

        ['site' => $site, 'code' => $code] = $enrolment->createSite(
            organisation: $organisation,
            name: $validated['name'],
            expectedDomain: $domain,
            environment: $validated['environment'],
            actor: $request->user(),
        );

        // Granted now rather than waiting for somebody to visit the Capabilities screen. All read-only,
        // all revocable, and a fleet table with nothing in it is not much of a fleet table. The
        // connector picks the set up on its next check-in.
        foreach ($validated['capabilities'] ?? [] as $capability) {
            $this->capabilities->grant($site, $capability, $request->user(), 'Selected when the site was added');
        }

        return to_route('sites.show', $site)
            ->with('status', "Added {$site->name}.")
            ->with('enrolmentCode', $code);
    }

    /**
     * Issue a fresh enrolment code for a site.
     *
     * Replacing a working connector needs its own confirmation. Without it a code cannot displace an
     * active one however it is used, which is what stops a compromised site re-pairing itself quietly.
     */
    public function issueCode(Request $request, Site $site, EnrolmentService $enrolment): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $replacing = $enrolment->wouldReplaceConnector($site);

        $validated = $request->validate([
            'authorise_replacement' => [$replacing ? 'accepted' : 'nullable'],
        ]);

        $code = $enrolment->issue(
            site: $site,
            actor: $request->user(),
            authoriseReplacement: $replacing && (bool) ($validated['authorise_replacement'] ?? false),
        );

        return back()
            ->with('status', $replacing
                ? 'New code issued, authorised to replace the current connector. The old credentials stop working once it is used.'
                : 'New code issued. Any previous code for this site no longer works.')
            ->with('enrolmentCode', $code);
    }

    public function destroy(Request $request, Site $site, SiteRemovalService $removal): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate([
            // Typing the domain is the confirmation. A dialog people click through is not one.
            'confirm_domain' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (strcasecmp(trim($validated['confirm_domain']), $site->expected_domain) !== 0) {
            return back()->withErrors(['confirm_domain' => 'That does not match this site\'s domain.']);
        }

        $removal->remove($site, $request->user(), $validated['reason'] ?? null);

        return redirect()
            ->route('sites.index')
            ->with('status', "{$site->name} has been removed and its connector revoked.");
    }

    /**
     * Columns the fleet table may be ordered by, and how each reads in a heading.
     *
     * A closed set, because the value arrives in a query string. Ordering by an arbitrary column
     * name from a URL is how a sort control becomes a way to probe the schema.
     *
     * @return array<string, string>
     */
    public static function sortable(): array
    {
        return [
            'name' => 'Site',
            'seen' => 'Last seen',
            'craft' => 'Craft',
            'updates' => 'Updates',
            'disk' => 'Disk',
            'backup' => 'Backup',
            'response' => 'Response',
            'reporting' => 'Reporting',
        ];
    }

    /**
     * The latest runtime report per site, as the two figures the fleet shows.
     *
     * One query for the whole page. The obvious implementation - `$site->runtimeReports()->latest()`
     * inside the loop - is a query per row, which is invisible on a fleet of three and painful on a
     * fleet of two hundred.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{disk: float|null, p95: int|null, at: Carbon|null}>
     */
    private function latestRuntimeFor(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $latest = RuntimeReport::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderByDesc('received_at')
            ->get(['site_id', 'disk_free_bytes', 'disk_total_bytes', 'response_p95_ms', 'received_at'])
            ->groupBy('site_id');

        $figures = [];

        foreach ($latest as $siteId => $reports) {
            /** @var RuntimeReport $report */
            $report = $reports->first();

            $figures[(int) $siteId] = [
                'disk' => $report->diskUsedPercent(),
                'p95' => $report->response_p95_ms,
                'at' => $report->received_at,
            ];
        }

        return $figures;
    }

    /**
     * The most recent stored backup per site, and whether the last attempt failed.
     *
     * One query for the whole page, like the runtime figures above.
     *
     * **Two states, not one, and the second is why this is not simply "the latest stored artifact".**
     * A backup that was refused before it declared an artifact leaves no `backup_artifacts` row at
     * all — the record is a `remote_jobs` failure, which is what FailedBackupJobs exists to surface.
     * So a site can have a healthy "3 hours ago" *and* a failure since, and a column showing only
     * the first would report a fleet as fine on the morning it stopped backing up.
     *
     * The date shown is still the last *stored* one, because "when could I last restore from" is the
     * question the column answers. The failure rides alongside it rather than replacing it.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{at: Carbon|null, failed: bool}>
     */
    private function latestBackupFor(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $ids = $sites->pluck('id');

        // Grouped in the database rather than pulled back and reduced in PHP: unlike the runtime
        // reports, nothing here needs a whole row — one timestamp per site is the entire answer.
        $stored = BackupArtifact::query()
            ->whereIn('site_id', $ids)
            ->where('state', BackupArtifact::STATE_STORED)
            ->groupBy('site_id')
            ->selectRaw('site_id, max(taken_at) as last_taken_at')
            ->pluck('last_taken_at', 'site_id');

        $failed = app(FailedBackupJobs::class)
            ->forOrganisation((int) $sites->first()->organisation_id)
            ->keyBy(fn (FailedBackupJob $job): int => $job->site->id);

        $figures = [];

        foreach ($sites as $site) {
            $at = $stored->get($site->id);

            $figures[$site->id] = [
                'at' => $at === null ? null : Carbon::parse($at),
                'failed' => $failed->has($site->id),
            ];
        }

        return $figures;
    }

    /**
     * Order one group.
     *
     * Sites with nothing to sort on sink to the bottom rather than sorting as zero. A site that has
     * never reported its disk is not the emptiest disk in the fleet, and putting it at the top of a
     * "fullest first" list would be the table lying about which sites it knows anything about.
     *
     * @param  Collection<int, Site>  $group
     * @param  array<int, array{disk: float|null, p95: int|null, at: Carbon|null}>  $runtime
     * @param  array<int, array{at: Carbon|null, failed: bool}>  $backups
     * @param  array<int, UptimeWindow>  $reporting
     * @return Collection<int, Site>
     */
    private function sortGroup(Collection $group, string $sort, array $runtime, array $backups, array $reporting): Collection
    {
        return $group
            ->sortBy(function (Site $site) use ($sort, $runtime, $backups, $reporting): array {
                $figures = $runtime[$site->id] ?? ['disk' => null, 'p95' => null];
                $backup = $backups[$site->id] ?? ['at' => null, 'failed' => false];
                $window = $reporting[$site->id] ?? null;

                return match ($sort) {
                    // Descending, by negating: the interesting end of each of these is the top.
                    'disk' => [$figures['disk'] === null ? 1 : 0, -($figures['disk'] ?? 0)],
                    'response' => [$figures['p95'] === null ? 1 : 0, -($figures['p95'] ?? 0)],

                    /*
                     | A security release first, then the largest backlog.
                     |
                     | Not simply "most updates". Nine ordinary plugin updates and one Craft security
                     | release are not the same job, and a column sorted purely by count would bury
                     | the second under the first. The badge already makes that distinction in
                     | colour; the sort has to make it too, or clicking the heading argues with what
                     | the column is showing.
                     */
                    'updates' => [
                        $site->has_security_release ? 0 : 1,
                        -(int) $site->available_updates,
                    ],

                    /*
                     | Failures first, then oldest backup first. Both halves are the same question -
                     | "which of these can I least rely on restoring" - and a failure outranks any
                     | date, because a site that failed this morning is a worse position than one
                     | whose last good copy is a fortnight old and still succeeding.
                     |
                     | A site that has never stored one sorts with the failures rather than at the
                     | bottom: never having backed up is not a neutral absence on this column.
                     */
                    'backup' => [
                        $backup['failed'] ? 0 : 1,
                        $backup['at'] === null ? 0 : $backup['at']->getTimestamp(),
                    ],

                    // Worst first, and sites with too little evidence to judge sink rather than
                    // sorting as zero - the same rule the disk column follows.
                    'reporting' => [
                        $window === null || ! $window->hasEvidence() ? 1 : 0,
                        $window === null ? 0.0 : $window->availability,
                    ],

                    // Oldest first: "who have we not heard from" is the question being asked.
                    'seen' => [$site->last_seen_at === null ? 1 : 0, $site->last_seen_at?->getTimestamp() ?? 0],

                    'craft' => [$site->craft_version === null ? 1 : 0, 0],
                    default => [0, 0],
                };
            }, SORT_REGULAR)
            ->when(
                $sort === 'craft',
                // Version strings do not sort as strings: 5.10.1 is newer than 5.9.9 and sorts
                // before it. Oldest first, because an old Craft is the one that needs somebody.
                fn (Collection $sorted): Collection => $sorted->sortBy(
                    fn (Site $site): string => $site->craft_version === null
                        ? 'zzz'
                        : implode('.', array_map(
                            static fn (string $part): string => str_pad($part, 5, '0', STR_PAD_LEFT),
                            explode('.', $site->craft_version),
                        )),
                ),
            )
            ->values();
    }

    /**
     * @param  array{q: string, status: string, environment: string}  $filters
     * @return Builder<Site>
     */
    private function query(Organisation $organisation, array $filters): Builder
    {
        return Site::query()
            // Every query starts scoped. Nothing relies on a caller remembering to add it.
            ->where('organisation_id', $organisation->id)
            ->active()
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('expected_domain', 'ilike', $term);
                });
            })
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['environment'] !== '', fn (Builder $query) => $query->where('environment', $filters['environment']))
            ->orderBy('name');
    }

    /**
     * @return array{total: int, needingAttention: int, connected: int, neverConnected: int}
     */
    private function summary(Organisation $organisation): array
    {
        $sites = $organisation->sites()->active()->get();

        return [
            'total' => $sites->count(),
            'needingAttention' => $sites->filter(fn (Site $site): bool => $this->needsAttention($site))->count(),
            'connected' => $sites->where('status', Site::STATUS_CONNECTED)->count(),
            'neverConnected' => $sites->where('status', Site::STATUS_NEVER_CONNECTED)->count(),
        ];
    }

    /**
     * A site that has reported, but has stopped, or has never managed to.
     *
     * "Last seen a while ago" is the signal that matters most in Phase 1: with only inventory
     * reporting implemented, a site falling silent is the one thing that genuinely needs a person.
     */
    private function needsAttention(Site $site): bool
    {
        if ($site->status === Site::STATUS_NOT_CONNECTED) {
            return true;
        }

        return $site->status === Site::STATUS_CONNECTED
            && $site->last_seen_at !== null
            && $site->last_seen_at->lt(now()->subHour());
    }

    private function isSteady(Site $site): bool
    {
        return $site->status === Site::STATUS_CONNECTED && ! $this->needsAttention($site);
    }

    private function isUnmonitored(Site $site): bool
    {
        return in_array($site->status, [Site::STATUS_NEVER_CONNECTED, Site::STATUS_PAUSED], true)
            && ! $this->needsAttention($site);
    }
}
