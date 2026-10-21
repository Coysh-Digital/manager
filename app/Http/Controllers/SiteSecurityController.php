<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Security\SitePosture;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

/**
 * One site's security posture: what is wrong with it, and what has not been looked at.
 *
 * The second half is the part an audit screen usually omits. A rule whose capability is not granted
 * is skipped rather than passed - "we were not allowed to look" and "there is nothing wrong" are
 * different answers - so the screen names the rules that could not run rather than letting an empty
 * list read as a clean bill of health.
 */
final class SiteSecurityController
{
    use ResolvesSiteContext;

    public function __construct(
        private readonly FindingsEvaluator $evaluator,
        private readonly SitePosture $posture,
    ) {}

    public function show(Request $request, Site $site): View
    {
        $showHistory = $request->query('history') === 'all';

        $resolved = $site->findings()->where('state', Finding::STATE_RESOLVED);

        $context = $this->siteContext($site);
        $granted = $site->grantedCapabilities();

        // Rules grouped by the capability they need, keeping only the capabilities this site does not
        // have. Read off the evaluator's own list rather than a hand-kept copy: a rule added there and
        // forgotten here would quietly become something this screen claims to check and does not.
        $unchecked = [];

        foreach ($this->evaluator->rules() as $rule) {
            $capability = $rule->requiresCapability();

            if ($capability === null || in_array($capability, $granted, true)) {
                continue;
            }

            $unchecked[$capability][] = $rule->key();
        }

        return view('sites.security', [
            ...$context,
            'findings' => $this->outstandingFindings($site),

            // The three things a findings list does not say: is the thing reporting to us still the
            // thing we paired with, is this getting better or worse, and what does Manager itself
            // hold on this site.
            'trust' => $this->posture->trust($site, $context['connector']),
            'timeline' => $this->posture->timeline($site),
            'exposure' => $this->posture->exposure($site),

            // What the site serves to somebody outside it. Null until the daily sweep has reached
            // this site, and the screen says which rather than rendering an empty panel that reads
            // as an all-clear.
            'probe' => $site->probeReports()->latest('probed_at')->first(),

            // What has been fixed. A short tail by default, so that a screen showing nothing
            // outstanding still shows evidence that something ever ran; `?history=all` pages through
            // the lot. The acknowledgement columns survive resolution, which is what lets a closed
            // finding still say who accepted it and why.
            'resolved' => $showHistory
                ? $this->resolvedOrdered($resolved)->paginate(25)->withQueryString()
                : $this->resolvedOrdered($resolved)->limit(5)->get(),
            'resolvedTotal' => $resolved->count(),
            'showHistory' => $showHistory,
            'latestReport' => $this->latestInventoryReport($site),
            'updateReport' => $this->latestUpdateReport($site),
            'unchecked' => $unchecked,
            'loginReport' => $this->latestLoginReport($site),
            'canAcknowledge' => app(Membership::class)->canAdminister(),
        ]);
    }

    /**
     * Newest first, with the id breaking ties so a page boundary cannot repeat or drop a finding
     * resolved in the same second.
     *
     * @param  HasMany<Finding, Site>  $resolved
     * @return HasMany<Finding, Site>
     */
    private function resolvedOrdered(HasMany $resolved): HasMany
    {
        return (clone $resolved)->latest('resolved_at')->latest('id');
    }
}
