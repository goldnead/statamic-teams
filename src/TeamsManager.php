<?php

namespace Goldnead\Teams;

use Closure;
use Goldnead\Teams\Integrations\Entitlements\TeamEntitlements;
use Goldnead\Teams\Integrations\Payments\TeamBuyer;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\ImportService;
use Goldnead\Teams\Services\InvitationService;
use Goldnead\Teams\Services\JoinService;
use Goldnead\Teams\Services\MembershipService;
use Goldnead\Teams\Services\TeamService;
use Goldnead\Teams\Support\CurrentTeam;
use Goldnead\Teams\Support\IssuedInvitation;
use Goldnead\Teams\Support\JoinGuards;
use Goldnead\Teams\Support\Roles;
use Illuminate\Support\Collection;

/**
 * The public API, behind the `Teams` facade.
 *
 * Every method that changes something takes an optional `$actor`. With an
 * actor (the signed-in user, from a front-end form or an API), the actor's
 * role in the team must allow it, and a refusal is a
 * {@see Exceptions\TeamsException} with a stable `reason`. Without an actor
 * the call is trusted (Control Panel, console, import).
 */
class TeamsManager
{
    public function __construct(
        protected TeamService $teams,
        protected MembershipService $memberships,
        protected InvitationService $invitations,
        protected JoinService $joins,
        protected ImportService $importer,
        protected Roles $roles,
        protected CurrentTeam $current,
        protected JoinGuards $guards,
        protected TeamEntitlements $entitlements,
        protected TeamBuyer $buyer,
        protected Services\Authorizer $authorizer,
    ) {}

    // Teams ---------------------------------------------------------------

    /** @param  array<string, mixed>  $attributes */
    public function create(string $name, mixed $owner = null, array $attributes = []): Team
    {
        return $this->teams->create($name, $owner, $attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(Team $team, array $attributes, mixed $actor = null): Team
    {
        return $this->teams->update($team, $attributes, $actor);
    }

    public function delete(Team $team, mixed $actor = null): void
    {
        $this->teams->delete($team, $actor);
    }

    /** By id or uuid. */
    public function find(int|string|null $id): ?Team
    {
        return $this->teams->find($id);
    }

    public function personalTeam(mixed $user): Team
    {
        return $this->teams->personalTeam($user);
    }

    public function regenerateJoinCode(Team $team, mixed $actor = null): string
    {
        return $this->teams->regenerateJoinCode($team, $actor);
    }

    public function transferOwnership(Team $team, mixed $to, mixed $actor = null): Team
    {
        return $this->teams->transferOwnership($team, $to, $actor);
    }

    // Members -------------------------------------------------------------

    /** @return Collection<int, Team> */
    public function teamsOf(mixed $user): Collection
    {
        return $this->memberships->teamsOf($user);
    }

    /** @return Collection<int, Membership> */
    public function members(Team $team): Collection
    {
        return $this->memberships->membersOf($team);
    }

    /** @param  array<string, mixed>  $meta */
    public function addMember(Team $team, mixed $user, ?string $role = null, array $meta = [], mixed $actor = null): Membership
    {
        return $this->memberships->add($team, $user, $role, $meta, 'added', $actor);
    }

    public function removeMember(Team $team, mixed $user, mixed $actor = null): void
    {
        $this->memberships->remove($team, $user, $actor);
    }

    public function leave(Team $team, mixed $user): void
    {
        $this->memberships->leave($team, $user);
    }

    public function changeRole(Team $team, mixed $user, string $role, mixed $actor = null): Membership
    {
        return $this->memberships->changeRole($team, $user, $role, $actor);
    }

    /** @param  array<string, mixed>  $meta */
    public function updateMemberMeta(Team $team, mixed $user, array $meta, mixed $actor = null): Membership
    {
        return $this->memberships->updateMeta($team, $user, $meta, $actor);
    }

    // Current team --------------------------------------------------------

    /**
     * The team of this request: the one the `teams.current` middleware
     * resolved, else the user's current team. Null for a guest or somebody
     * in no team.
     */
    public function current(mixed $user = null): ?Team
    {
        // Once `teams.current` decided (even "none", with the fallback off),
        // its word stands. Without the middleware: the user's current team.
        if ($this->current->resolved() && $user === null) {
            return $this->current->get();
        }

        if ($this->current->has()) {
            return $this->current->get();
        }

        $user ??= auth()->user();

        return $user === null ? null : $this->memberships->currentFor($user);
    }

    /**
     * The team of this request, or a `team_required` refusal (422). The
     * counterpart of ChoirLive's `currentTenantId()`.
     */
    public function currentOrFail(mixed $user = null): Team
    {
        return $this->current($user) ?? throw Exceptions\TeamsException::because(Exceptions\TeamsException::TEAM_REQUIRED);
    }

    public function setCurrent(?Team $team): void
    {
        $this->current->set($team);
    }

    public function switch(mixed $user, Team $team): Membership
    {
        $membership = $this->memberships->switch($user, $team);
        $this->current->set($team);

        return $membership;
    }

    // Invitations and join codes -----------------------------------------

    /** @param  array<string, mixed>  $meta */
    public function invite(Team $team, string $email, ?string $role = null, array $meta = [], mixed $actor = null): IssuedInvitation
    {
        return $this->invitations->invite($team, $email, $role, $meta, $actor);
    }

    public function acceptInvitation(string $token, mixed $user): Membership
    {
        return $this->invitations->accept($token, $user);
    }

    public function revokeInvitation(Invitation $invitation, mixed $actor = null): Invitation
    {
        return $this->invitations->revoke($invitation, $actor);
    }

    public function resendInvitation(Invitation $invitation, mixed $actor = null): IssuedInvitation
    {
        return $this->invitations->resend($invitation, $actor);
    }

    /** The invitation behind a token, if it can still be accepted. */
    public function invitation(string $token): Invitation
    {
        return $this->invitations->peek($token);
    }

    /** @return Collection<int, Invitation> */
    public function pendingInvitationsFor(mixed $user): Collection
    {
        return $this->invitations->pendingFor($user);
    }

    /** @return Collection<int, Invitation> */
    public function pendingInvitationsOf(Team $team): Collection
    {
        return $this->invitations->pendingOf($team);
    }

    /** @param  array<string, mixed>  $meta */
    public function joinByCode(string $code, mixed $user, array $meta = []): Membership
    {
        return $this->joins->join($code, $user, $meta);
    }

    /**
     * Veto who may enter a team, e.g. a seat limit.
     *
     * @param  Closure(Team, string, string): (string|null)  $guard
     */
    public function guardJoining(Closure $guard): void
    {
        $this->guards->add($guard);
    }

    // Roles ---------------------------------------------------------------

    public function can(mixed $user, Team $team, string $permission): bool
    {
        return $this->roles->can($user, $team, $permission);
    }

    public function roleOf(mixed $user, Team $team): ?string
    {
        return $team->roleOf($user);
    }

    /** @return array<string, array{label: string, permissions: list<string>, custom: bool}> */
    public function roles(?Team $team = null): array
    {
        return $this->roles->all($team);
    }

    // Entitlements and payments ------------------------------------------

    /**
     * The entitlement subjects a user holds access through: one per team.
     *
     * @return list<mixed>
     */
    public function entitlementSubjectsFor(mixed $user): array
    {
        return $this->entitlements->subjectsFor($user);
    }

    public function entitlementSubject(Team $team): mixed
    {
        return $this->entitlements->subjectFor($team);
    }

    /**
     * Access to a product, personally (`$personalSubject`) or through a team.
     */
    public function allows(mixed $user, string $productSlug, mixed $personalSubject = null): bool
    {
        return $this->entitlements->allows($user, $productSlug, $personalSubject);
    }

    /** @return array<string, mixed> */
    public function checkoutBuyer(Team $team, mixed $payer = null): array
    {
        return $this->buyer->buyer($team, $payer);
    }

    /** @return array<string, mixed> */
    public function checkoutDetails(Team $team, mixed $payer = null): array
    {
        return $this->buyer->details($team, $payer);
    }

    /**
     * Start a payments checkout with the team as buyer.
     *
     * @param  string|list<string>  $products
     */
    public function checkout(Team $team, string|array $products, mixed $payer = null, ?string $returnUrl = null): ?object
    {
        // Paying for a team is spending its money and choosing its plan: the
        // payer must be a member holding `manage billing` there. No payer
        // means the system (a CP action, a job), as everywhere else.
        $this->authorizer->authorize($payer, $team, 'manage billing');

        return $this->buyer->checkout($team, $products, $payer, $returnUrl);
    }

    // Import --------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    public function import(array $data): Team
    {
        return $this->importer->import($data);
    }
}
