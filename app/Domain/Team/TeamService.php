<?php

declare(strict_types=1);

namespace App\Domain\Team;

use App\Domain\Audit\AuditRecorder;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Who has access to this installation, and how they get it.
 *
 * There was no way to add a second person before this: accounts came from the one-time setup flow or
 * from `manager:user:password` on the server, which meant every installation was either a single
 * account or a shell session away from one.
 *
 * The invitation is deliberately not a password. An administrator creates the account with a random
 * secret nobody ever sees - not even them - and the new user sets their own through the ordinary
 * reset flow. Two things follow from that: no administrator ever handles a colleague's credential,
 * and there is no "temporary password" sitting in a chat log waiting to be found. The token is
 * Laravel's own broker: single-use, expiring and stored hashed.
 *
 * Everything here is audited, including the failures, because "who let this person in" is a question
 * an audit log has to be able to answer.
 */
final class TeamService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Roles somebody may be given, worst-privilege first so an interface listing them reads honestly.
     *
     * @return array<string, string>
     */
    public static function assignableRoles(): array
    {
        return [
            Membership::ROLE_OWNER => 'Owner - everything, including billing, removal and other owners',
            Membership::ROLE_ADMIN => 'Administrator - sites, capabilities, backups and people',
            Membership::ROLE_MEMBER => 'Member - read the fleet and ask sites to report',
        ];
    }

    /**
     * Invite somebody, creating their account and sending them a link to set a password.
     *
     * @return array{membership: Membership, existed: bool}
     */
    public function invite(Organisation $organisation, string $email, string $name, string $role, User $actor): array
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($organisation, $email, $name, $role, $actor): array {
            $user = User::query()->where('email', $email)->first();
            $existed = $user !== null;

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,

                    // Random, never shown, never recoverable. The account is unusable until the
                    // invitee sets their own through the reset link - which is the point: an
                    // administrator must not be able to sign in as somebody they invited.
                    'password' => Str::random(64),
                ]);
            }

            // Re-inviting somebody whose access was revoked reinstates the same membership rather
            // than creating a second one. Two membership rows for one person in one organisation is
            // a state every access check would then have to have an opinion about.
            $membership = Membership::query()->updateOrCreate(
                ['organisation_id' => $organisation->id, 'user_id' => $user->id],
                ['role' => $role, 'revoked_at' => null],
            );

            $this->audit->record(
                action: 'member.invited',
                organisation: $organisation,
                actor: $actor,
                targetType: 'user',
                targetId: $user->external_id,
                after: ['email' => $email, 'role' => $role, 'existing_account' => $existed],
            );

            return ['membership' => $membership, 'existed' => $existed];
        });
    }

    /**
     * Send the set-a-password link.
     *
     * Outside the transaction on purpose: a mail server that is slow, or down, must not roll back an
     * invitation that was otherwise valid. The interface reports which of the two happened, and the
     * link can be sent again.
     */
    public function sendInvitationLink(User $user): bool
    {
        return Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
    }

    /**
     * Change what somebody may do.
     */
    public function changeRole(Membership $membership, string $role, User $actor): void
    {
        $previous = $membership->role;

        if ($previous === $role) {
            return;
        }

        $membership->forceFill(['role' => $role])->save();

        $this->audit->record(
            action: 'member.role.changed',
            organisation: $membership->organisation,
            actor: $actor,
            targetType: 'user',
            targetId: $membership->user->external_id,
            before: ['role' => $previous],
            after: ['role' => $role],
        );
    }

    /**
     * Revoke access.
     *
     * A timestamp rather than a delete, so every audit record still points at something real, and
     * because access checks read live membership on each request - revocation takes effect on the
     * next one rather than whenever a session happens to expire.
     */
    public function revoke(Membership $membership, User $actor): void
    {
        DB::transaction(function () use ($membership): void {
            $membership->forceFill(['revoked_at' => now()])->save();

            // Their sessions go with it. Leaving somebody signed in after their access is revoked
            // makes "immediate removal of access" a claim rather than a fact.
            DB::table('sessions')->where('user_id', $membership->user_id)->delete();
        });

        $this->audit->record(
            action: 'member.revoked',
            organisation: $membership->organisation,
            actor: $actor,
            targetType: 'user',
            targetId: $membership->user->external_id,
            before: ['role' => $membership->role, 'state' => 'active'],
            after: ['state' => 'revoked'],
        );
    }

    /**
     * Whether removing or demoting this membership would leave nobody in charge.
     *
     * An organisation with no owner cannot grant a capability, remove a site or invite anybody - it
     * is a locked installation needing a shell to recover. Cheaper to refuse the last step.
     */
    public function isLastOwner(Membership $membership): bool
    {
        if ($membership->role !== Membership::ROLE_OWNER) {
            return false;
        }

        return Membership::query()
            ->where('organisation_id', $membership->organisation_id)
            ->where('role', Membership::ROLE_OWNER)
            ->whereNull('revoked_at')
            ->count() <= 1;
    }
}
