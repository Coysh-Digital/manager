<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityPanel;
use App\Domain\Pairing\EnrolmentService;
use App\Http\Controllers\Concerns\ResolvesSiteContext;
use App\Models\Membership;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Everything about a site that a person sets, rather than a connector reports.
 *
 * Four sections on one page: what the site is called and where it lives, what Manager is permitted
 * to do on it, how its connector is paired, and how to remove it. They were previously spread across
 * three screens and the bottom of the Overview, and the pairing form in particular opened every
 * visit to a perfectly healthy site.
 */
final class SiteSettingsController
{
    use ResolvesSiteContext;

    public function __construct(
        private readonly CapabilityPanel $panel,
        private readonly AuditRecorder $audit,
    ) {}

    public function show(Site $site): View
    {
        return view('sites.settings', [
            ...$this->siteContext($site),
            'membership' => app(Membership::class),
            'capabilities' => $this->panel->for($site),
            'history' => $this->panel->history($site),
        ]);
    }

    /**
     * Change what this site is called, where it is expected to pair from, and which environment it is.
     *
     * None of these was editable before, which meant a renamed client or a moved domain was fixed by
     * removing the site and pairing it again — losing its findings, its history and its audit trail
     * to a typo.
     *
     * Behind recent authentication and restricted to administrators, and the previous values go into
     * the audit log. `expected_domain` in particular is the value a pairing is checked against: a
     * connector presenting a different host is held for confirmation rather than adopted, so changing
     * it silently would defeat a control rather than merely edit a label.
     */
    public function update(Request $request, Site $site, EnrolmentService $enrolment): RedirectResponse
    {
        abort_unless(app(Membership::class)->canAdminister(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expected_domain' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'in:production,staging,development'],

            /*
             | The backup schedule decides *when* to ask for a backup and nothing else.
             |
             | There is deliberately no field here naming a destination, a recipient or a format. Those
             | are decided by the organisation's recovery keys and by the site's own configuration
             | file, and a schedule form that could influence any of them would be a way to change who
             | can read a backup from a screen that looks like it is about timing.
             */
            // Optional, falling back to what is already set. A caller that only means to rename a
            // site should not have to restate its backup schedule, and a missing field must never be
            // read as "turn backups off".
            'backup_schedule' => ['sometimes', 'in:off,daily,weekly'],
            'backup_schedule_hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'backup_schedule_day' => ['sometimes', 'integer', 'min:1', 'max:7'],
        ]);

        $domain = $enrolment->normaliseDomain($validated['expected_domain']);

        if ($domain === '' || ! str_contains($domain, '.')) {
            return back()->withErrors([
                'expected_domain' => 'That does not look like a domain. Use the host the site is served from, such as example.org.',
            ])->withInput();
        }

        $before = [
            'name' => $site->name,
            'expected_domain' => $site->expected_domain,
            'environment' => $site->environment,
            'backup_schedule' => $site->backup_schedule,
            'backup_schedule_hour' => $site->backup_schedule_hour,
            'backup_schedule_day' => $site->backup_schedule_day,
        ];

        $after = [
            'name' => $validated['name'],
            'expected_domain' => $domain,
            'environment' => $validated['environment'],
            'backup_schedule' => $validated['backup_schedule'] ?? $site->backup_schedule,
            'backup_schedule_hour' => (int) ($validated['backup_schedule_hour'] ?? $site->backup_schedule_hour),
            'backup_schedule_day' => (int) ($validated['backup_schedule_day'] ?? $site->backup_schedule_day),
        ];

        if ($before === $after) {
            // Nothing changed. Recording it anyway would put a row in an append-only log that says a
            // site was edited when it was not.
            return back()->with('status', 'Nothing to change.');
        }

        $site->forceFill($after)->save();

        $this->audit->record(
            action: 'site.updated',
            site: $site,
            actor: $request->user(),
            targetType: 'site',
            targetId: $site->external_id,
            before: $before,
            after: $after,
        );

        return back()->with('status', $before['environment'] !== $after['environment']
            ? 'Saved. Changing the environment changes which findings apply — several rules only fire in production.'
            : 'Saved.');
    }
}
