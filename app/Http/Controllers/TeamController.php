<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Team\TeamService;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * People with access to this installation.
 *
 * Owners only. An administrator manages sites; deciding who else can reach a control plane at all is
 * a different kind of decision and sits one level up.
 *
 * The People tab of Settings, and its actions. The reads sit beside the writes they describe rather
 * than in a settings controller of their own, so neither drifts from the other.
 */
final class TeamController
{
    public function __construct(private readonly TeamService $team) {}

    /*
     | Readable by any member, writable only by an owner - which is what this was when it was a
     | section of the one settings screen, and moving it to a route of its own is not a reason to
     | change it. "Who else can reach this installation" is a question an administrator should be
     | able to answer without asking somebody; acting on the answer is the owner's call, and each
     | control inside is gated on that individually.
     */
    public function show(Organisation $organisation): View
    {
        return view('settings.people', [
            'organisation' => $organisation,
            'membership' => app(Membership::class),

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
            // link is used, so its presence means somebody has a live invitation - or asked for a
            // reset and has not finished it. The screen says the former, because it cannot tell them
            // apart and offering to resend is the right answer to both.
            'awaitingPassword' => DB::table('password_reset_tokens')->pluck('email')->all(),
        ]);
    }

    public function invite(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(TeamService::assignableRoles()))],
        ]);

        $existing = User::query()->where('email', mb_strtolower(trim($validated['email'])))->first();

        if ($existing !== null && $existing->memberships()
            ->where('organisation_id', $organisation->id)
            ->whereNull('revoked_at')
            ->exists()) {
            return back()->withErrors([
                'email' => 'That address already has access. Change their role below instead.',
            ])->withInput();
        }

        /*
         | Somebody who already belongs to another organisation, refused rather than half-admitted.
         |
         | An account reaches exactly one organisation: ResolveOrganisation takes the lowest-id live
         | membership and there is no switcher. So inviting a person who already belongs elsewhere
         | used to *succeed* - a membership row was written, the invitation email went out, and this
         | screen listed them as having access - while they carried on seeing only the organisation
         | they joined first. The person who invited them had no way to tell, because everything they
         | could see said it had worked.
         |
         | Refusing is the honest half of that. It is a real limitation of the product and it is
         | stated here rather than discovered later by two people who cannot work out why one of them
         | sees nothing.
        */
        if ($existing !== null && $existing->memberships()
            ->where('organisation_id', '!=', $organisation->id)
            ->whereNull('revoked_at')
            ->exists()) {
            return back()->withErrors([
                'email' => 'That address already belongs to another organisation on this installation, '
                    .'and an account can only reach one. Invite them on a different address.',
            ])->withInput();
        }

        ['membership' => $membership] = $this->team->invite(
            organisation: $organisation,
            email: $validated['email'],
            name: $validated['name'],
            role: $validated['role'],
            actor: $request->user(),
        );

        // Sent after the invitation is committed, and reported honestly either way: a mail server
        // being down is not a reason to pretend nothing happened, nor to claim an email was sent.
        $sent = $this->team->sendInvitationLink($membership->user, $organisation, $request->user());

        return back()->with($sent ? 'status' : 'warning', $sent
            ? "Invited {$validated['email']}. They have been emailed a link to set their own password - no password passes through you."
            : "Access granted to {$validated['email']}, but the invitation email could not be sent. "
              .'Check the mail configuration, then use Resend below.');
    }

    /**
     * Send the set-a-password link again.
     */
    public function resend(Request $request, Membership $membership, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();
        $this->authoriseMembership($membership, $organisation);

        $sent = $this->team->sendInvitationLink($membership->user, $organisation, $request->user());

        return back()->with($sent ? 'status' : 'warning', $sent
            ? "A new link has been sent to {$membership->user->email}. Any previous link stops working."
            : 'That link could not be sent. Check the mail configuration.');
    }

    public function updateRole(Request $request, Membership $membership, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();
        $this->authoriseMembership($membership, $organisation);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(TeamService::assignableRoles()))],
        ]);

        if ($validated['role'] !== Membership::ROLE_OWNER && $this->team->isLastOwner($membership)) {
            return back()->withErrors([
                'role' => 'This is the only owner. Make somebody else an owner first, or the installation is left with nobody who can grant access.',
            ]);
        }

        $this->team->changeRole($membership, $validated['role'], $request->user());

        return back()->with('status', 'Role updated. It takes effect on their next request.');
    }

    public function revoke(Request $request, Membership $membership, Organisation $organisation): RedirectResponse
    {
        $this->authoriseOwner();
        $this->authoriseMembership($membership, $organisation);

        if ($this->team->isLastOwner($membership)) {
            return back()->withErrors([
                'membership' => 'This is the only owner, and an organisation with no owner cannot grant access, add a site or invite anybody. Promote somebody else first.',
            ]);
        }

        if ($membership->user_id === $request->user()->id) {
            return back()->withErrors([
                'membership' => 'You cannot revoke your own access. Ask another owner to do it.',
            ]);
        }

        $this->team->revoke($membership, $request->user());

        return back()->with('status', "Access revoked for {$membership->user->email}, and their sessions have been signed out.");
    }

    /**
     * A membership from another organisation is a 404, not a 403.
     */
    private function authoriseMembership(Membership $membership, Organisation $organisation): void
    {
        abort_if($membership->organisation_id !== $organisation->id, 404);
    }

    private function authoriseOwner(): void
    {
        abort_unless(app(Membership::class)->isOwner(), 403);
    }
}
