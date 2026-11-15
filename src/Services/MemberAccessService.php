<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Events\MemberPermissionGranted;
use Goldnead\Teams\Events\MemberPermissionRevoked;
use Goldnead\Teams\Events\MemberRoleAdded;
use Goldnead\Teams\Events\MemberRoleRemoved;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\MemberPermission;
use Goldnead\Teams\Models\MemberRole;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Permissions;
use Goldnead\Teams\Support\Roles;

/**
 * Additional roles and single permissions of a member.
 *
 * The main role (`team_members.role`) stays what it was and is changed with
 * {@see MembershipService::changeRole()}. What is added here sits on top:
 * the member may do whatever any of their roles, or a permission of their
 * own, allows. The owner role is only ever a main role.
 *
 * The rules are those of role changes: the actor needs `change roles`, may
 * hand out or take away only what they hold themselves, may not touch their
 * own access unless they are an owner, and an owner's access is changed by
 * an owner. Without an actor the call is trusted.
 */
class MemberAccessService
{
    public function __construct(
        protected Roles $roles,
        protected Permissions $permissions,
        protected Authorizer $authorizer,
    ) {}

    public function addRole(Team $team, mixed $user, string $role, mixed $actor = null): Membership
    {
        $membership = $this->target($team, $user, $actor);

        if (! $this->roles->exists($role, $team)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        if ($role === $this->roles->ownerRole()) {
            throw TeamsException::because(TeamsException::ROLE_PROTECTED);
        }

        $this->authorizer->authorizeRole($actor, $team, $role);

        if ($membership->role === $role) {
            return $membership;
        }

        $row = MemberRole::query()->createOrFirst(['team_id' => $team->id, 'user_id' => $membership->user_id, 'role' => $role]);

        if ($row->wasRecentlyCreated) {
            event(new MemberRoleAdded($team, $membership, $role, $this->authorizer->actorKey($actor)));
        }

        return $membership;
    }

    public function removeRole(Team $team, mixed $user, string $role, mixed $actor = null): Membership
    {
        $membership = $this->target($team, $user, $actor);

        $this->authorizer->authorizeRole($actor, $team, $role);

        $deleted = MemberRole::query()
            ->where('team_id', $team->id)
            ->where('user_id', $membership->user_id)
            ->where('role', $role)
            ->delete();

        if ($deleted > 0) {
            event(new MemberRoleRemoved($team, $membership, $role, $this->authorizer->actorKey($actor)));
        }

        return $membership;
    }

    public function grantPermission(Team $team, mixed $user, string $permission, mixed $actor = null): Membership
    {
        $membership = $this->target($team, $user, $actor);

        $this->checkPermission($permission);
        $this->authorizer->authorizePermission($actor, $team, $permission);

        $row = MemberPermission::query()->createOrFirst(['team_id' => $team->id, 'user_id' => $membership->user_id, 'permission' => $permission]);

        if ($row->wasRecentlyCreated) {
            event(new MemberPermissionGranted($team, $membership, $permission, $this->authorizer->actorKey($actor)));
        }

        return $membership;
    }

    public function revokePermission(Team $team, mixed $user, string $permission, mixed $actor = null): Membership
    {
        $membership = $this->target($team, $user, $actor);

        $this->authorizer->authorizePermission($actor, $team, $permission);

        $deleted = MemberPermission::query()
            ->where('team_id', $team->id)
            ->where('user_id', $membership->user_id)
            ->where('permission', $permission)
            ->delete();

        if ($deleted > 0) {
            event(new MemberPermissionRevoked($team, $membership, $permission, $this->authorizer->actorKey($actor)));
        }

        return $membership;
    }

    /**
     * Main role first, then the additional ones.
     *
     * @return list<string>
     */
    public function rolesOf(Team $team, mixed $user): array
    {
        $membership = $team->membershipOf($user);

        return $membership === null ? [] : [$membership->role, ...$this->roles->extraRoles($membership)];
    }

    /**
     * What the member may do, all sources together (`['*']` for everything).
     *
     * @return list<string>
     */
    public function permissionsOf(Team $team, mixed $user): array
    {
        $membership = $team->membershipOf($user);

        return $membership === null ? [] : $this->roles->effectivePermissions($membership, $team);
    }

    /**
     * The permissions granted to the member alone.
     *
     * @return list<string>
     */
    public function ownPermissionsOf(Team $team, mixed $user): array
    {
        $membership = $team->membershipOf($user);

        return $membership === null ? [] : $this->roles->ownPermissions($membership);
    }

    /**
     * The checks every change of a member's access shares.
     */
    protected function target(Team $team, mixed $user, mixed $actor): Membership
    {
        $this->authorizer->authorize($actor, $team, 'change roles');

        $membership = $team->membershipOf($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);

        // One's own access is an owner's business: `change roles` alone would
        // let an admin raise himself past whoever gave it to him.
        if ($actor !== null && $this->authorizer->actorKey($actor) === $membership->user_id
            && ! $this->authorizer->isOwner($actor, $team)) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        if ($membership->role === $this->roles->ownerRole() && ! $this->authorizer->isOwner($actor, $team)) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        return $membership;
    }

    protected function checkPermission(string $permission): void
    {
        if ($permission === '*') {
            throw TeamsException::because(TeamsException::WILDCARD);
        }

        if (! $this->permissions->exists($permission)) {
            throw TeamsException::because(TeamsException::UNKNOWN_PERMISSION, ['permissions' => $permission]);
        }
    }
}
