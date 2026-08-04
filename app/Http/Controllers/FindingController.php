<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Findings\FindingsEvaluator;
use App\Domain\Findings\Severity;
use App\Models\Finding;
use App\Models\Membership;
use App\Models\Organisation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Findings across the fleet.
 *
 * Ordered by severity and then by age, because a critical finding that has been true for a month is
 * worse than one raised this morning and should read that way.
 *
 * Acknowledged findings are shown, not hidden. Acknowledgement means somebody has decided not to act
 * yet; filing it out of sight turns that decision into a permanent one nobody revisits.
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
     * @return array<string, int>
     */
    private function counts(Organisation $organisation): array
    {
        $counts = array_fill_keys(Severity::ordered(), 0);

        $rows = Finding::query()
            ->whereHas('site', fn ($query) => $query
                ->where('organisation_id', $organisation->id)
                ->whereNull('archived_at'))
            ->whereIn('state', [Finding::STATE_OPEN, Finding::STATE_ACKNOWLEDGED])
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        foreach ($rows as $severity => $total) {
            $counts[$severity] = (int) $total;
        }

        return $counts;
    }

    private function authorise(Finding $finding): void
    {
        abort_if($finding->site->organisation_id !== app(Organisation::class)->id, 404);
        abort_unless(app(Membership::class)->canAdminister(), 403);
    }
}
