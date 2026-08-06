<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\Severity;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The security posture of the whole fleet, site by site.
 *
 * Grouped by site rather than by rule, which is the opposite of {@see FindingController}, and the
 * difference is the reason both screens exist. Findings answers "what is wrong across the fleet", so
 * grouping twelve copies of one misconfiguration under one heading is what makes it readable.
 * Security answers "is this site safe", and that question is asked one client at a time.
 *
 * **Every site is listed, including the clean ones.** A screen that lists only sites with problems
 * cannot distinguish "nothing is wrong here" from "nothing has been checked here", and those are the
 * two answers an operator most needs to tell apart - a rule whose capability is not granted is
 * skipped, not passed. A site with no findings says which of the two it is.
 */
final class SecurityController
{
    public function __construct(private readonly FindingsEvaluator $evaluator) {}

    public function index(Request $request, Organisation $organisation): View
    {
        $showResolved = $request->boolean('resolved');

        $sites = Site::query()
            ->where('organisation_id', $organisation->id)
            ->active()
            ->with('capabilityGrants')
            ->orderBy('name')
            ->get();

        $findings = Finding::query()
            ->with('site')
            ->whereIn('site_id', $sites->pluck('id'))
            ->whereIn('rule', RuleCategory::keysFor(RuleCategory::SECURITY))
            ->when(
                ! $showResolved,
                fn ($query) => $query->whereIn('state', [Finding::STATE_OPEN, Finding::STATE_ACKNOWLEDGED]),
                fn ($query) => $query->where('state', Finding::STATE_RESOLVED),
            )
            ->get()
            ->sortBy([
                fn (Finding $a, Finding $b): int => Severity::rank($a->severity) <=> Severity::rank($b->severity),
                fn (Finding $a, Finding $b): int => $a->first_seen_at <=> $b->first_seen_at,
            ])
            ->groupBy('site_id');

        /*
         | Two tallies, walked once rather than derived per site inside a sort comparator.
         |
         | `$worst` is a rank per site: Severity::rank() returns a position, so lower is worse, and a
         | site with nothing outstanding takes the size of the scale and lands at the end.
         */
        $counts = array_fill_keys(Severity::ordered(), 0);
        $worst = [];

        foreach ($findings as $siteId => $group) {
            $severity = null;

            foreach ($group as $finding) {
                $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
                $severity = Severity::worst($severity, $finding->severity);
            }

            $worst[$siteId] = $severity === null ? count(Severity::ordered()) : Severity::rank($severity);
        }

        $rank = static fn (Site $site): int => $worst[$site->id] ?? count(Severity::ordered());

        return view('security.index', [
            /*
             | Worst first, then by name.
             |
             | Ordering by name alone puts a site with an expired certificate below eleven clean ones
             | on a screen somebody opened *because* something was wrong.
             */
            'sites' => $sites->sortBy([
                fn (Site $a, Site $b): int => $rank($a) <=> $rank($b),
                fn (Site $a, Site $b): int => strcasecmp($a->name, $b->name),
            ])->values(),
            'findings' => $findings,
            'showResolved' => $showResolved,
            'counts' => $counts,

            // How many security rules could not run per site, so an empty list is never allowed to
            // read as a clean bill of health. The same distinction the single-site screen makes.
            'unchecked' => $sites->mapWithKeys(
                fn (Site $site): array => [$site->id => $this->uncheckedFor($site)]
            ),
            'canAcknowledge' => app(Membership::class)->canAdminister(),
        ]);
    }

    /**
     * The security rules this site has not granted the capability for.
     *
     * Read off the evaluator's own list rather than a hand-kept copy, exactly as
     * {@see SiteSecurityController} does - a rule added there and forgotten here would quietly become
     * something this screen claims to check and does not.
     *
     * @return list<string>
     */
    private function uncheckedFor(Site $site): array
    {
        $granted = $site->grantedCapabilities();
        $unchecked = [];

        foreach ($this->evaluator->rules() as $rule) {
            if ($rule->category() !== RuleCategory::SECURITY) {
                continue;
            }

            $capability = $rule->requiresCapability();

            if ($capability === null || in_array($capability, $granted, true)) {
                continue;
            }

            $unchecked[] = $rule->key();
        }

        return $unchecked;
    }
}
