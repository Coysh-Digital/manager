<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\Severity;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Findings across the fleet, apart from the security ones.
 *
 * Ordered by severity and then by age, because a critical finding that has been true for a month is
 * worse than one raised this morning and should read that way.
 *
 * Acknowledged findings are shown, not hidden. Acknowledgement means somebody has decided not to act
 * yet; filing it out of sight turns that decision into a permanent one nobody revisits.
 *
 * Security findings live on {@see SecurityController} instead. This screen excludes them rather than
 * showing everything with a filter, because the two are read for different reasons - one is "what is
 * exposed", the other is "what is owed" - and a single list ordered by severity interleaves them so
 * that neither question gets answered.
 *
 * What it does **not** do is drop them silently. A count and a link to the other screen are passed
 * through whenever any are outstanding: a number going down because a screen stopped counting it is
 * the failure this whole split has to avoid.
 *
 * The filter is `whereNotIn` the security keys rather than `whereIn` the other two categories, and
 * that is load-bearing for the same reason. A rule removed from the evaluator leaves its open
 * findings behind - reconciliation cannot resolve what it no longer runs - and a positive list would
 * put those rows on no screen at all. This screen is the one that shows anything uncategorised.
 */
final class FindingController
{
    public function __construct(
        private readonly FindingsEvaluator $evaluator,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request, Organisation $organisation): View
    {
        $showResolved = $request->boolean('resolved');

        $findings = Finding::query()
            ->with('site')
            ->whereHas('site', fn ($query) => $query
                ->where('organisation_id', $organisation->id)
                ->whereNull('archived_at'))
            ->whereNotIn('rule', RuleCategory::keysFor(RuleCategory::SECURITY))
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
            ->values();

        return view('findings.index', [
            'findings' => $findings,
            'showResolved' => $showResolved,
            'counts' => $this->counts($organisation),
            'securityCount' => $this->securityCount($organisation),
            'canAcknowledge' => app(Membership::class)->canAdminister(),
        ]);
    }

    /**
     * Acknowledge a finding.
     *
     * A reason is required. "Acknowledged by Tim, three weeks ago" with no explanation is barely more
     * useful than an unread finding - the next person still has to work out whether it was a decision
     * or a shrug.
     */
    public function acknowledge(Request $request, Finding $finding): RedirectResponse
    {
        $this->authorise($finding);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $finding->forceFill([
            'state' => Finding::STATE_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'acknowledged_label' => $request->user()->name ?: $request->user()->email,
            'acknowledgement_reason' => $validated['reason'],
        ])->save();

        $this->audit->record(
            action: 'finding.acknowledged',
            site: $finding->site,
            actor: $request->user(),
            targetType: 'finding',
            targetId: $finding->rule,
            before: ['state' => Finding::STATE_OPEN],
            after: ['state' => Finding::STATE_ACKNOWLEDGED, 'reason' => $validated['reason']],
        );

        // Recounted, because an acknowledged finding is still outstanding and the site summary has to
        // keep saying so.
        $this->evaluator->evaluate($finding->site);

        return back()->with('status', 'Acknowledged. It stays on the list until it is actually fixed.');
    }

    /**
     * Withdraw an acknowledgement.
     */
    public function reopen(Request $request, Finding $finding): RedirectResponse
    {
        $this->authorise($finding);

        $finding->forceFill([
            'state' => Finding::STATE_OPEN,
            'acknowledged_at' => null,
            'acknowledged_by' => null,
            'acknowledged_label' => null,
            'acknowledgement_reason' => null,
        ])->save();

        $this->audit->record(
            action: 'finding.reopened',
            site: $finding->site,
            actor: $request->user(),
            targetType: 'finding',
            targetId: $finding->rule,
            after: ['state' => Finding::STATE_OPEN],
        );

        return back()->with('status', 'Acknowledgement withdrawn.');
    }

    /**
     * The severity tally for what this screen actually lists.
     *
     * Scoped the same way the list is. A tally that counted security findings while the list below it
     * did not would be the one number on the page nobody could reconcile.
     *
     * @return array<string, int>
     */
    private function counts(Organisation $organisation): array
    {
        $counts = array_fill_keys(Severity::ordered(), 0);

        $rows = $this->outstanding($organisation)
            ->whereNotIn('rule', RuleCategory::keysFor(RuleCategory::SECURITY))
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        foreach ($rows as $severity => $total) {
            $counts[$severity] = (int) $total;
        }

        return $counts;
    }

    /**
     * How many security findings are outstanding on the other screen.
     *
     * Only ever rendered as "n security findings are on the Security page". The number is the whole
     * point of it: somebody who used to see everything here needs to know that what is missing is
     * somewhere, not gone.
     */
    private function securityCount(Organisation $organisation): int
    {
        return $this->outstanding($organisation)
            ->whereIn('rule', RuleCategory::keysFor(RuleCategory::SECURITY))
            ->count();
    }

    /**
     * @return Builder<Finding>
     */
    private function outstanding(Organisation $organisation): Builder
    {
        return Finding::query()
            ->whereHas('site', fn ($query) => $query
                ->where('organisation_id', $organisation->id)
                ->whereNull('archived_at'))
            ->whereIn('state', [Finding::STATE_OPEN, Finding::STATE_ACKNOWLEDGED]);
    }

    private function authorise(Finding $finding): void
    {
        abort_if($finding->site->organisation_id !== app(Organisation::class)->id, 404);
        abort_unless(app(Membership::class)->canAdminister(), 403);
    }
}
