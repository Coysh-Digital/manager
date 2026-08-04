<?php

declare(strict_types=1);

namespace App\Domain\Findings;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Findings\Rules\AbandonedPlugin;
use App\Domain\Findings\Rules\AccountsLockedOut;
use App\Domain\Findings\Rules\AdminChangesInProduction;
use App\Domain\Findings\Rules\CertificateExpiring;
use App\Domain\Findings\Rules\CraftSecurityRelease;
use App\Domain\Findings\Rules\DevModeInProduction;
use App\Domain\Findings\Rules\DiskAlmostFull;
use App\Domain\Findings\Rules\FailedQueueJobs;
use App\Domain\Findings\Rules\HttpsNotEnforced;
use App\Domain\Findings\Rules\InvalidLicence;
use App\Domain\Findings\Rules\OpcacheDisabledInProduction;
use App\Domain\Findings\Rules\PendingMigrations;
use App\Domain\Findings\Rules\PhpEndOfLife;
use App\Domain\Findings\Rules\PluginSecurityRelease;
use App\Domain\Findings\Rules\RepeatedFailedLogins;
use App\Domain\Findings\Rules\SiteNotReporting;
use App\Domain\Findings\Rules\SlowResponseTimes;
use App\Domain\Findings\Rules\UpdatesAllowedInProduction;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns the latest reports into findings, and keeps them honest.
 *
 * Reconciliation rather than insertion. Each run evaluates every applicable rule, then closes the gap
 * between what matched and what is already open: new matches open, continuing matches update, and
 * anything that no longer matches resolves itself.
 *
 * That self-resolving property is the whole design. A findings list that only ever grows becomes a
 * list nobody reads, and requiring somebody to tick off a fixed problem is asking them to do the
 * platform's job.
 *
 * Two things deliberately survive a run:
 *
 *  - **Acknowledgement.** Somebody saying "we know, it is deliberate here" must not be undone by the
 *    next report five minutes later.
 *  - **first_seen_at.** How long a problem has been true is often the most useful column on the
 *    screen, and it is lost the moment a finding is deleted and recreated.
 *
 * Rules whose capability is not granted are **skipped, not passed**. "We were not allowed to look"
 * and "there is nothing wrong" are different answers, and conflating them is how a real problem gets
 * a clean bill of health.
 */
final class FindingsEvaluator
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Every rule, worst-consequence first for readability rather than for behaviour.
     *
     * @return list<Rule>
     */
    public function rules(): array
    {
        return [
            new CraftSecurityRelease,
            new PluginSecurityRelease,
            new PhpEndOfLife,
            new DiskAlmostFull,
            new SiteNotReporting,

            // Needs no capability and comes from the platform's own observation, like the rule above.
            // Unlike every other rule here that observation is one the platform went and made itself,
            // because the connector cannot see the certificate a visitor validates.
            new CertificateExpiring,
            new RepeatedFailedLogins,
            new DevModeInProduction,
            new HttpsNotEnforced,
            new InvalidLicence,
            new AbandonedPlugin,
            new AdminChangesInProduction,
            new AccountsLockedOut,
            new PendingMigrations,
            new FailedQueueJobs,
            new SlowResponseTimes,
            new UpdatesAllowedInProduction,
            new OpcacheDisabledInProduction,
        ];
    }

    /**
     * Evaluate one site and reconcile its findings.
     *
     * @return array{opened: int, updated: int, resolved: int, skipped: int}
     */
    public function evaluate(Site $site): array
    {
        $snapshot = new Snapshot(
            site: $site,
            inventory: $site->inventoryReports()->latest('received_at')->first(),
            updates: $site->updateReports()->latest('received_at')->first(),
            capabilities: $site->grantedCapabilities(),
            runtime: $site->runtimeReports()->latest('received_at')->first(),
            logins: $site->loginReports()->latest('received_at')->first(),
        );

        $tally = ['opened' => 0, 'updated' => 0, 'resolved' => 0, 'skipped' => 0];

        DB::transaction(function () use ($site, $snapshot, &$tally): void {
            $existing = $site->findings()->get()->keyBy('rule');
            $matchedRules = [];

            foreach ($this->rules() as $rule) {
                $capability = $rule->requiresCapability();

                if ($capability !== null && ! $snapshot->hasCapability($capability)) {
                    // Skipped, not evaluated. Anything already open for this rule is left exactly as
                    // it was rather than resolved: losing the capability tells us nothing about
                    // whether the problem went away.
                    $tally['skipped']++;
                    $matchedRules[] = $rule->key();

                    continue;
                }

                $match = $rule->evaluate($snapshot);

                if ($match === null) {
                    continue;
                }

                $matchedRules[] = $rule->key();

                $tally[$this->record($site, $rule, $match, $existing->get($rule->key())) ? 'opened' : 'updated']++;
            }

            // Anything outstanding that no rule matched this time has been fixed.
            foreach ($existing as $finding) {
                if (in_array($finding->rule, $matchedRules, true) || ! $finding->isOutstanding()) {
                    continue;
                }

                $this->resolve($site, $finding);
                $tally['resolved']++;
            }

            $this->refreshSiteSummary($site);
        });

        return $tally;
    }

    /**
     * Open a new finding, or update one that is still true.
     *
     * @return bool true when newly opened
     */
    private function record(Site $site, Rule $rule, RuleMatch $match, ?Finding $existing): bool
    {
        $now = Carbon::now();

        if ($existing !== null && $existing->isOutstanding()) {
            // Still true. The severity, title and detail are refreshed because a rule may now have
            // more to say - a second plugin needing a security update, say - but the acknowledgement
            // and the first-seen date are left alone.
            $existing->forceFill([
                'severity' => $match->severity,
                'title' => $match->title,
                'detail' => $match->detail,
                'evidence' => $match->evidence,
                'last_seen_at' => $now,
            ])->save();

            return false;
        }

        // Either brand new, or previously resolved and now back. A recurrence is a fresh occurrence:
        // the acknowledgement is cleared, because "we know about this" was said about a problem that
        // then went away.
        Finding::query()->updateOrCreate(
            ['site_id' => $site->id, 'rule' => $rule->key()],
            [
                'severity' => $match->severity,
                'title' => $match->title,
                'detail' => $match->detail,
                'evidence' => $match->evidence,
                'state' => Finding::STATE_OPEN,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'resolved_at' => null,
                'acknowledged_at' => null,
                'acknowledged_by' => null,
                'acknowledged_label' => null,
                'acknowledgement_reason' => null,
            ],
        );

        $this->audit->record(
            action: 'finding.opened',
            site: $site,
            actorType: AuditEvent::ACTOR_SYSTEM,
            actorLabel: 'Findings',
            targetType: 'finding',
            targetId: $rule->key(),
            after: [
                'severity' => $match->severity,
                'title' => $match->title,
                'evidence' => $match->evidence,
            ],
        );

        // Notified only for the severities worth interrupting somebody about. A channel that fires on
        // everything gets filtered into a folder nobody opens, at which point it looks like coverage
        // while providing none.
        if (in_array($match->severity, [Severity::CRITICAL, Severity::HIGH], true)) {
            $this->notifier->dispatch(new NotificationEvent(
                type: $rule->key() === 'site_not_reporting'
                    ? NotificationEvent::SITE_SILENT
                    : NotificationEvent::FINDING_OPENED,
                subject: $match->title,
                summary: $match->detail,
                site: $site,
                context: ['severity' => $match->severity, 'rule' => $rule->key()],
            ));
        }

        return true;
    }

    private function resolve(Site $site, Finding $finding): void
    {
        $finding->forceFill([
            'state' => Finding::STATE_RESOLVED,
            'resolved_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            action: 'finding.resolved',
            site: $site,
            actorType: AuditEvent::ACTOR_SYSTEM,
            actorLabel: 'Findings',
            targetType: 'finding',
            targetId: $finding->rule,
            before: ['state' => Finding::STATE_OPEN, 'severity' => $finding->severity],
            after: ['state' => Finding::STATE_RESOLVED],
        );
    }

    /**
     * Mirror the worst outstanding finding onto the site.
     *
     * So the fleet table can rank by urgency in one scan rather than joining and aggregating per row.
     */
    private function refreshSiteSummary(Site $site): void
    {
        $outstanding = $site->findings()
            ->whereIn('state', [Finding::STATE_OPEN, Finding::STATE_ACKNOWLEDGED])
            ->get();

        $worst = null;

        foreach ($outstanding as $finding) {
            $worst = Severity::worst($worst, $finding->severity);
        }

        $site->forceFill([
            'open_findings' => $outstanding->count(),
            'worst_finding_severity' => $worst,
        ])->save();
    }
}
