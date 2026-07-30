<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Team\TeamService;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * People with access to this installation.
 *
 * Owners only. An administrator manages sites; deciding who else can reach a control plane at all is
 * a different kind of decision and sits one level up.
 *
 * There is no screen of its own — this is the People section of Settings, and these are its actions.
 */
final class TeamController
{
    public function __construct(private readonly TeamService $team) {}

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

        ['membership' => $membership] = $this->team->invite(
            organisation: $organisation,
            email: $validated['email'],
            name: $validated['name'],
            role: $validated['role'],
            actor: $request->user(),
        );

        // Sent after the invitation is committed, and reported honestly either way: a mail server
        // being down is not a reason to pretend nothing happened, nor to claim an email was sent.
        $sent = $this->team->sendInvitationLink($membership->user);

        return back()->with($sent ? 'status' : 'warning', $sent
            ? "Invited {$validated['email']}. They have been emailed a link to set their own password — no password passes through you."
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

        $sent = $this->team->sendInvitationLink($membership->user);

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
