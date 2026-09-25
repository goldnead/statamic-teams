<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Events\InvitationAccepted;
use Goldnead\Teams\Events\InvitationRevoked;
use Goldnead\Teams\Events\InvitationSent;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\IssuedInvitation;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitations by link.
 *
 * There is one way into a team for somebody who already has an account, and
 * it starts with an invitation they accept themselves. Nobody is put into a
 * team because somebody else knows their address.
 */
class InvitationService
{
    public function __construct(
        protected MembershipService $memberships,
        protected Authorizer $authorizer,
        protected Roles $roles,
    ) {}

    /**
     * Invite an address into a team.
     *
     * Inviting the same address again replaces the open invitation: new
     * token, new expiry, and the old link stops working. Whoever invites a
     * second time usually does so because the first one said something
     * wrong.
     *
     * @param  array<string, mixed>  $meta  Copied onto the membership on accept.
     */
    public function invite(Team $team, string $email, ?string $role = null, array $meta = [], mixed $actor = null): IssuedInvitation
    {
        $this->authorizer->authorize($actor, $team, 'invite members');

        $email = Str::lower(trim($email));
        $role ??= $this->roles->defaultRole();

        if ($team->isPersonal()) {
            throw TeamsException::because(TeamsException::PERSONAL_TEAM);
        }

        if (! $this->roles->exists($role, $team)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        // Handing out the owner role is the owner's business, not an admin's.
        if ($role === $this->roles->ownerRole() && $actor !== null && ! $team->isOwner($actor)) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        $existingUser = Users::findByEmail($email);

        if ($existingUser !== null && $team->hasMember($existingUser)) {
            throw TeamsException::because(TeamsException::ALREADY_MEMBER);
        }

        $token = Str::random(48);

        $open = $team->invitations()->pending()->where('email', $email)->first();
        $attributes = [
            'role' => $role,
            'meta' => $meta === [] ? null : $meta,
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $this->authorizer->actorKey($actor),
            'expires_at' => $this->expiry(),
        ];

        if ($open !== null) {
            $open->update($attributes);
            $invitation = $open;
        } else {
            $invitation = $team->invitations()->create(['email' => $email] + $attributes);
        }

        $url = $this->acceptUrl($token);

        event(new InvitationSent($invitation, $open !== null, $this->authorizer->actorKey($actor), $url));

        return new IssuedInvitation($invitation, $token, $url);
    }

    /**
     * Accept an invitation as the signed-in user.
     *
     * The invitation is bound to its address: the account accepting it must
     * carry the same email. A forwarded mail is not a key to the team.
     */
    public function accept(string $token, mixed $user): Membership
    {
        $invitation = Invitation::findByToken($token) ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);

        $this->assertUsable($invitation);

        $key = Users::key($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
        $email = Str::lower((string) Users::email($user));

        if (config('teams.invitations.require_matching_email', true) && $email !== Str::lower($invitation->email)) {
            throw TeamsException::because(TeamsException::INVITATION_WRONG_EMAIL);
        }

        $team = $invitation->team ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);

        $membership = DB::transaction(function () use ($invitation, $team, $key) {
            // Claimed first, with a condition: two tabs accepting the same
            // link at once must produce one membership, not an error in one
            // of them after the other already joined.
            $claimed = Invitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['accepted_at' => now(), 'accepted_by' => $key]);

            if ($claimed === 0) {
                throw TeamsException::because(TeamsException::INVITATION_USED);
            }

            return $this->memberships->add($team, $key, $invitation->role, $invitation->meta ?? [], 'invitation');
        });

        $invitation->refresh();

        event(new InvitationAccepted($invitation, $membership));

        return $membership;
    }

    public function revoke(Invitation $invitation, mixed $actor = null): Invitation
    {
        $team = $invitation->team ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);
        $this->authorizer->authorize($actor, $team, 'invite members');

        $this->assertUsable($invitation);

        $invitation->update(['revoked_at' => now()]);

        event(new InvitationRevoked($invitation, $this->authorizer->actorKey($actor)));

        return $invitation;
    }

    /**
     * Send an open invitation again, with a new link.
     */
    public function resend(Invitation $invitation, mixed $actor = null): IssuedInvitation
    {
        $team = $invitation->team ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);

        return $this->invite($team, $invitation->email, $invitation->role, $invitation->meta ?? [], $actor);
    }

    /**
     * What the token is, without accepting it: for the page behind the link.
     */
    public function peek(string $token): Invitation
    {
        $invitation = Invitation::findByToken($token) ?? throw TeamsException::because(TeamsException::INVITATION_NOT_FOUND);
        $this->assertUsable($invitation);

        return $invitation;
    }

    /**
     * Open invitations addressed to the user's email, across all teams.
     *
     * @return Collection<int, Invitation>
     */
    public function pendingFor(mixed $user): Collection
    {
        $email = Users::email($user);

        if ($email === null) {
            return collect();
        }

        return Invitation::query()->pending()->with('team')->where('email', Str::lower($email))->latest()->get();
    }

    /** @return Collection<int, Invitation> */
    public function pendingOf(Team $team): Collection
    {
        return $team->invitations()->pending()->latest()->get();
    }

    public function acceptUrl(string $token): string
    {
        $pattern = config('teams.invitations.accept_url');

        if (is_string($pattern) && $pattern !== '') {
            return str_replace('{token}', $token, $pattern);
        }

        return url(trim((string) config('teams.routes.prefix', 'teams'), '/').'/invitations/'.$token);
    }

    protected function assertUsable(Invitation $invitation): void
    {
        match ($invitation->status()) {
            Invitation::STATUS_ACCEPTED => throw TeamsException::because(TeamsException::INVITATION_USED),
            Invitation::STATUS_REVOKED => throw TeamsException::because(TeamsException::INVITATION_REVOKED),
            Invitation::STATUS_EXPIRED => throw TeamsException::because(TeamsException::INVITATION_EXPIRED),
            default => null,
        };
    }

    protected function expiry(): ?Carbon
    {
        $days = (int) config('teams.invitations.expires_after_days', 7);

        return $days > 0 ? now()->addDays($days) : null;
    }
}
