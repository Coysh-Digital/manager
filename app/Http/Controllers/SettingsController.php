<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Backup\RetentionPolicy;
use App\Domain\Capability\CapabilityService;
use App\Domain\Health\Diagnostics;
use App\Domain\Notifications\EmailTransport;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Team\TeamService;
use App\Models\AuditEvent;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\NotificationDestination;
use App\Models\Organisation;
use App\Models\RecoveryKey;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Platform settings.
 *
 * The health panel is the same {@see Diagnostics} that `manager:doctor` runs, not a second set of
 * checks written for the screen. Two implementations would eventually disagree, and the one somebody
 * is looking at would be the wrong one.
 *
 * Everything that changes state here needs recent authentication, and the irreversible actions need
 * the organisation name typed as well.
 */
final class SettingsController
{
    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly CapabilityService $capabilities,
        private readonly AuditRecorder $audit,
        private readonly Notifier $notifier,
    ) {}

    public function show(Organisation $organisation): View
    {
        return view('settings.index', [
            'organisation' => $organisation,
            'checks' => $this->diagnostics->all(),
            'membership' => app(Membership::class),

            // Null on an installation that has no way to know, which is most of them. See
            // config/manager.php.
            'version' => config('manager.version'),

            'siteCount' => $organisation->sites()->active()->count(),
            'connectorCount' => Connector::query()
                ->whereIn('site_id', $organisation->sites()->select('id'))
                ->where('state', Connector::STATE_ACTIVE)
                ->count(),

            // Shown so an operator can see what a new site will and will not be able to do before
            // they pair one, rather than discovering it afterwards.
            'pairingDefaults' => CapabilityService::pairingDefaults(),
            'grantable' => CapabilityService::grantableFromInterface(),

            /*
             | Recovery keys, including revoked ones.
             |
             | Revoked keys are shown rather than hidden, for the same reason revoked memberships are:
             | "which keys used to open our backups" is a question this screen should answer without
             | anybody opening the audit log. It also keeps a fingerprint appearing in an artifact's
             | manifest explicable a year after the key stopped being used.
             */
            // Rendered as a sentence beside the form, so somebody can check the policy against what
            // they meant rather than reading three numbers back.
            'retention' => RetentionPolicy::forOrganisation($organisation),
            'timezones' => timezone_identifiers_list(),

            'recoveryKeys' => RecoveryKey::query()
                ->where('organisation_id', $organisation->id)
                ->orderByRaw("case state when 'active' then 0 when 'pending_proof' then 1 else 2 end")
                ->orderBy('id')
                ->get(),

            // Revoked memberships are shown too, greyed: "who used to have access" is a question an
            // access screen should answer without anybody opening the audit log.
            'members' => Membership::query()
                ->where('organisation_id', $organisation->id)
                ->with('user')
                ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 else 2 end")
                ->orderBy('id')
                ->get(),
            'assignableRoles' => TeamService::assignableRoles(),

            // Addresses with an unused password link outstanding. The broker deletes the row when a
            // link is used, so its presence means somebody has a live invitation — or asked for a
            // reset and has not finished it. The screen says the former, because it cannot tell them
            // apart and offering to resend is the right answer to both.
            'awaitingPassword' => DB::table('password_reset_tokens')->pluck('email')->all(),

            'destinations' => NotificationDestination::query()
                ->where('organisation_id', $organisation->id)
                ->with(['deliveries' => fn ($query) => $query->latest('created_at')->limit(3)])
                ->orderBy('label')
                ->get(),
            'eventCatalogue' => NotificationEvent::catalogue(),
        ]);
    }

    /**
     * Require every member of this organisation to hold a second factor.
     *
     * Existing accounts without one are not locked out immediately; they are required to enrol before
     * they can do anything else. Locking people out of a control plane to improve its security is a
     * trade that tends to be made once and regretted at 2am.
     */
    public function updateMfa(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate(['mfa_required' => ['required', 'boolean']]);

        $was = $organisation->mfa_required;
        $now = (bool) $validated['mfa_required'];

        if ($was === $now) {
            return back();
        }

        $organisation->forceFill(['mfa_required' => $now])->save();

        $this->audit->record(
            action: $now ? 'organisation.mfa.required' : 'organisation.mfa.optional',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: ['mfa_required' => $was],
            after: ['mfa_required' => $now],
        );

        $withoutSecondFactor = $organisation->activeMemberships()
            ->with('user')
            ->get()
            ->reject(fn (Membership $membership): bool => $membership->user->hasConfirmedTotp())
            ->count();

        return back()->with(
            'status',
            $now
                ? ($withoutSecondFactor > 0
                    ? "Two-factor authentication is now required. {$withoutSecondFactor} ".
                      ($withoutSecondFactor === 1 ? 'member has' : 'members have').
                      ' not enrolled yet and will be asked to before they can continue.'
                    : 'Two-factor authentication is now required. Every member already has it.')
                : 'Two-factor authentication is no longer required.',
        );
    }

    /**
     * Revoke every connector in the organisation.
     *
     * The blunt instrument, for when a platform credential is suspected of having leaked. Every site
     * stops reporting until each is re-paired with a fresh enrolment code, which is the point:
     * whatever the old keys were trusted for, they no longer are.
     */
    public function rotateAllConnectors(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate(['confirm_organisation' => ['required', 'string']]);

        if (strcasecmp(trim($validated['confirm_organisation']), $organisation->name) !== 0) {
            return back()->withErrors([
                'confirm_organisation' => 'That does not match this organisation\'s name.',
            ]);
        }

        $revoked = 0;

        DB::transaction(function () use ($organisation, $request, &$revoked): void {
            $sites = $organisation->sites()->active()->get();

            foreach ($sites as $site) {
                $connector = $site->activeConnector()->first();

                if ($connector === null) {
                    continue;
                }

                $connector->forceFill([
                    'state' => Connector::STATE_REVOKED,
                    'revoked_at' => now(),
                    'revoked_reason' => 'Organisation-wide key rotation',
                ])->save();

                // Credentials and permissions go together, as everywhere else.
                $this->capabilities->revokeAll($site, $request->user(), 'Organisation-wide key rotation');

                $site->forceFill(['status' => Site::STATUS_NOT_CONNECTED])->save();

                $revoked++;
            }

            $this->audit->record(
                action: 'organisation.connectors.rotated',
                organisation: $organisation,
                actor: $request->user(),
                targetType: 'organisation',
                targetId: $organisation->external_id,
                after: ['connectors_revoked' => $revoked, 'sites' => $sites->count()],
            );
        });

        // After the transaction, so a rotation that rolled back does not announce itself. Notified for
        // the same reason a single revocation is, only more so: this silences the entire fleet, and if
        // the account that did it was compromised, the notification is the one thing the attacker
        // cannot suppress from in here.
        if ($revoked > 0) {
            $this->notifier->dispatch(new NotificationEvent(
                type: NotificationEvent::CONNECTOR_REVOKED,
                subject: "Every connector in {$organisation->name} was revoked",
                summary: "All {$revoked} active ".($revoked === 1 ? 'connector was' : 'connectors were').
                    ' revoked at once, so the whole fleet has stopped reporting. Each site needs a '
                    .'fresh enrolment code. If you did not expect this, treat it as a possible '
                    .'compromise of the account that did it.',
                context: ['connectors_revoked' => $revoked],
            ), $organisation);
        }

        return back()->with(
            'warning',
            $revoked === 0
                ? 'There were no active connectors to revoke.'
                : "Revoked {$revoked} ".($revoked === 1 ? 'connector' : 'connectors').
                  '. Each site needs a fresh enrolment code before it will report again.',
        );
    }

    /**
     * Retention, and the time zone the schedule reads.
     *
     * Owner-level rather than administrator, because shortening retention decides how far back this
     * organisation can recover from — which is a different kind of decision from managing a site.
     *
     * Two things are deliberately not on this form. It cannot lengthen an existing artifact's life:
     * expiry is computed when a backup is stored, from the policy in force then, so changing this
     * governs future backups only. And it cannot set a "keep the most recent N", because that is the
     * rule this replaced — a site producing bad backups on a schedule pushes out the last known-good
     * copy, the count never drops below N, and nothing looks wrong.
     */
    public function updateRetention(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate([
            // Zero is meaningful in each of these and means "no window of this kind", not "unset".
            // The policy keeps the newest artifact regardless, so all three at zero still leaves an
            // organisation with its most recent backup rather than with nothing.
            'backup_retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'backup_retention_weeks' => ['required', 'integer', 'min:0', 'max:520'],
            'backup_retention_months' => ['required', 'integer', 'min:0', 'max:120'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        $before = [
            'backup_retention_days' => $organisation->backup_retention_days,
            'backup_retention_weeks' => $organisation->backup_retention_weeks,
            'backup_retention_months' => $organisation->backup_retention_months,
            'timezone' => $organisation->timezone,
        ];

        $after = [
            'backup_retention_days' => (int) $validated['backup_retention_days'],
            'backup_retention_weeks' => (int) $validated['backup_retention_weeks'],
            'backup_retention_months' => (int) $validated['backup_retention_months'],
            'timezone' => $validated['timezone'],
        ];

        if ($before === $after) {
            return back()->with('status', 'Nothing to change.');
        }

        $organisation->forceFill($after)->save();

        $this->audit->record(
            action: 'backup.retention.changed',
            organisation: $organisation,
            actor: $request->user(),
            targetType: 'organisation',
            targetId: $organisation->external_id,
            before: $before,
            after: $after,
        );

        return back()->with(
            'status',
            'Retention updated. Backups already taken keep the expiry they were given.',
        );
    }

    /**
     * Send a test message to the signed-in owner's own address.
     *
     * Deliberately to *their* address and nowhere else. A field here would be a form on an
     * authenticated page that sends arbitrary mail to an arbitrary address from this installation's
     * relay, which is an open relay with extra steps and a reputation problem waiting to happen.
     *
     * Not queued. A queued send reports success the moment the job is accepted, which proves the
     * queue works and says nothing about mail. The point is to fail here, now, where somebody is
     * looking.
     *
     * The failure is reported as a class name rather than a message, matching
     * {@see EmailTransport}: a mail exception can carry the transport
     * configuration, credentials included, and this response is a web page. `manager:mail-test`
     * prints the whole thing, because a shell on the server is a different audience.
     */
    public function testMail(Request $request): RedirectResponse
    {
        $this->authoriseOwner();

        $user = $request->user();

        try {
            Mail::raw(
                "This is a test message from Manager.\n\n"
                    .'If you are reading it, this installation can send email — which means password '
                    .'resets, invitations and notification emails will reach people.',
                static fn (Message $message) => $message->to($user->email)->subject('Manager test message'),
            );
        } catch (Throwable $e) {
            $this->audit->record(
                action: 'settings.mail.tested',
                organisation: app(Organisation::class),
                actor: $user,
                outcome: AuditEvent::OUTCOME_FAILURE,
                failureReason: $e::class,
            );

            return back()->with('warning', sprintf(
                'The message was not sent (%s). Check the MAIL_* variables in .env, or run '
                    .'`php artisan manager:mail-test %s` for the full error.',
                class_basename($e),
                $user->email,
            ));
        }

        $this->audit->record(
            action: 'settings.mail.tested',
            organisation: app(Organisation::class),
            actor: $user,
        );

        return back()->with(
            'status',
            "Sent to {$user->email}. The transport accepted it, which is not the same as it arriving — check your inbox.",
        );
    }

    private function authoriseOwner(): void
    {
        abort_unless(app(Membership::class)->isOwner(), 403);
    }
}
