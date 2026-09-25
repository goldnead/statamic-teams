<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\MemberLeft;
use Goldnead\Teams\Events\MemberRoleChanged;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\JoinGuards;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is in which team, in which role, and which team a user works in.
 */
class MembershipService
{
    public function __construct(
        protected Roles $roles,
        protected Authorizer $authorizer,
        protected JoinGuards $guards,
    ) {}

    /**
     * Put a user into a team.
     *
     * `$via` says how (`added`, `created`, `invitation`, `join_code`) and
     * travels with the event. With an actor, the actor needs
     * `invite members` in the team.
     *
     * @param  array<string, mixed>  $meta
     */
    public function add(Team $team, mixed $user, ?string $role = null, array $meta = [], string $via = 'added', mixed $actor = null): Membership
    {
        $this->authorizer->authorize($actor, $team, 'invite members');

        $key = Users::key($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
        $role ??= $this->roles->defaultRole();

        if (! $this->roles->exists($role, $team)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        if ($team->isPersonal() && $via !== 'created' && $team->owner_id !== $key) {
            throw TeamsException::because(TeamsException::PERSONAL_TEAM);
        }

        if ($team->hasMember($key)) {
            throw TeamsException::because(TeamsException::ALREADY_MEMBER);
        }

        $this->guards->check($team, $key, $via);

        $membership = DB::transaction(function () use ($team, $key, $role, $meta) {
            return $team->members()->create([
                'user_id' => $key,
                'role' => $role,
                'meta' => $meta === [] ? null : $meta,
                'is_current' => ! Membership::query()->where('user_id', $key)->where('is_current', true)->exists(),
                'joined_at' => now(),
            ]);
        });

        event(new MemberJoined($team, $membership, $via, $this->authorizer->actorKey($actor)));

        return $membership;
    }

    /**
     * Take a user out of a team.
     *
     * The actor removing somebody else needs `remove members`; anybody may
     * remove themselves (that is leaving). The last owner cannot go:
     * a team nobody can administer is lost.
     */
    public function remove(Team $team, mixed $user, mixed $actor = null): void
    {
        $key = Users::key($user);
        $actorKey = $this->authorizer->actorKey($actor);
        $leaving = $actorKey !== null && $actorKey === $key;

        if (! $leaving) {
            $this->authorizer->authorize($actor, $team, 'remove members');
        }

        $membership = $team->membershipOf($key) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);

        if ($membership->role === $this->roles->ownerRole() && $this->ownerCount($team) <= 1) {
            throw TeamsException::because(TeamsException::LAST_OWNER);
        }

        DB::transaction(function () use ($membership, $key) {
            $wasCurrent = $membership->is_current;
            $membership->delete();

            if ($wasCurrent) {
                Membership::query()->where('user_id', $key)->orderBy('id')->limit(1)->update(['is_current' => true]);
            }
        });

        event(new MemberLeft($team, (string) $key, $membership->role, $leaving ? 'left' : 'removed', $actorKey));
    }

    public function leave(Team $team, mixed $user): void
    {
        $this->remove($team, $user, $user);
    }

    public function changeRole(Team $team, mixed $user, string $role, mixed $actor = null): Membership
    {
        $this->authorizer->authorize($actor, $team, 'change roles');

        if (! $this->roles->exists($role, $team)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        $membership = $team->membershipOf($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
        $from = $membership->role;

        if ($from === $role) {
            return $membership;
        }

        $owner = $this->roles->ownerRole();

        if ($from === $owner && $this->ownerCount($team) <= 1) {
            throw TeamsException::because(TeamsException::LAST_OWNER);
        }

        // Only an owner makes somebody an owner. `change roles` alone would
        // let an admin promote himself past the people who gave him the role.
        if ($role === $owner && $actor !== null && ! $team->isOwner($actor)) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        $membership->update(['role' => $role]);

        event(new MemberRoleChanged($team, $membership, $from, $role, $this->authorizer->actorKey($actor)));

        return $membership;
    }

    /**
     * Merge fields into a membership's `meta` (a voice part, a department).
     * A null value removes the key.
     *
     * @param  array<string, mixed>  $meta
     */
    public function updateMeta(Team $team, mixed $user, array $meta, mixed $actor = null): Membership
    {
        if (Users::key($actor) !== Users::key($user)) {
            $this->authorizer->authorize($actor, $team, 'change roles');
        }

        $membership = $team->membershipOf($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
        $merged = array_filter(array_merge($membership->meta ?? [], $meta), fn ($value) => $value !== null);
        $membership->update(['meta' => $merged === [] ? null : $merged]);

        return $membership;
    }

    /**
     * Make a team the one the user works in when a request names none.
     */
    public function switch(mixed $user, Team $team): Membership
    {
        $key = Users::key($user);
        $membership = $team->membershipOf($key) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);

        DB::transaction(function () use ($key, $membership) {
            Membership::query()->where('user_id', $key)->where('id', '!=', $membership->id)->update(['is_current' => false]);
            $membership->update(['is_current' => true]);
        });

        return $membership;
    }

    /**
     * The user's current team: the one marked current, else the oldest
     * membership. Null for somebody in no team.
     */
    public function currentFor(mixed $user): ?Team
    {
        $key = Users::key($user);

        if ($key === null) {
            return null;
        }

        $membership = Membership::query()
            ->with('team')
            ->where('user_id', $key)
            ->orderByDesc('is_current')
            ->orderBy('id')
            ->first();

        return $membership?->team;
    }

    /** @return Collection<int, Team> */
    public function teamsOf(mixed $user): Collection
    {
        $key = Users::key($user);

        if ($key === null) {
            return collect();
        }

        return Team::query()
            ->whereIn('id', Membership::query()->select('team_id')->where('user_id', $key))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Membership> */
    public function membersOf(Team $team): Collection
    {
        return $team->members()->orderBy('joined_at')->orderBy('id')->get();
    }

    public function ownerCount(Team $team): int
    {
        return $team->members()->where('role', $this->roles->ownerRole())->count();
    }
}
