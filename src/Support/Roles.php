<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\MemberPermission;
use Goldnead\Teams\Models\MemberRole;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;

/**
 * Roles and permissions inside a team.
 *
 * Three layers, the later one wins per handle: `teams.roles` (the starting
 * point), the global roles changed in the CP (`team_global_roles`, see
 * {@see GlobalRoleStore}), and the roles of one team (`team_roles`). The
 * owner role always holds every permission, whatever a team, the CP or the
 * config writes for it: a team whose owner can lock himself out is a
 * support ticket.
 *
 * Each role says where it comes from: `scope` is `global` or `team`;
 * `source` is `config` (as configured), `customised` (a config role changed
 * in the CP), `cp` (created in the CP) or `team`. `overrides_global` marks a
 * team role that replaces a global one of the same handle for that team.
 */
class Roles
{
    /**
     * @return array<string, array{label: string, permissions: list<string>, custom: bool, scope: string, source: string, overrides_global: bool}>
     */
    public function all(?Team $team = null): array
    {
        $roles = $this->global();

        if ($team !== null && $team->exists) {
            foreach (app(TeamRoleStore::class)->for($team) as $role) {
                $roles[$role->handle] = [
                    'label' => $role->label,
                    'permissions' => $this->normalise($role->handle, (array) ($role->permissions ?? [])),
                    'custom' => true,
                    'scope' => 'team',
                    'source' => 'team',
                    'overrides_global' => array_key_exists($role->handle, $roles),
                ];
            }
        }

        return $roles;
    }

    /**
     * The global roles: config, then the CP's changes.
     *
     * @return array<string, array{label: string, permissions: list<string>, custom: bool, scope: string, source: string, overrides_global: bool}>
     */
    public function global(): array
    {
        $roles = [];

        foreach ($this->configured() as $handle => $role) {
            $roles[$handle] = $role + ['custom' => false, 'scope' => 'global', 'source' => 'config', 'overrides_global' => false];
        }

        foreach (app(GlobalRoleStore::class)->rows() as $handle => $row) {
            if ($row->removed) {
                unset($roles[$handle]);

                continue;
            }

            $roles[$handle] = [
                'label' => $row->label,
                'permissions' => $this->normalise($handle, (array) ($row->permissions ?? [])),
                'custom' => false,
                'scope' => 'global',
                'source' => array_key_exists($handle, $roles) ? 'customised' : 'cp',
                'overrides_global' => false,
            ];
        }

        return $roles;
    }

    /**
     * The roles as the config writes them, before any CP change.
     *
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public function configured(): array
    {
        $roles = [];

        foreach ((array) config('teams.roles', []) as $handle => $role) {
            $roles[(string) $handle] = [
                // Through __(): config labels are English and a German CP
                // translates them from lang/de.json.
                'label' => (string) __((string) ($role['label'] ?? $handle)),
                'permissions' => $this->normalise((string) $handle, (array) ($role['permissions'] ?? [])),
            ];
        }

        return $roles;
    }

    /**
     * @param  array<mixed>  $permissions
     * @return list<string>
     */
    protected function normalise(string $handle, array $permissions): array
    {
        if ($handle === $this->ownerRole()) {
            return ['*'];
        }

        return array_values(array_unique(array_map('strval', $permissions)));
    }

    public function exists(string $role, ?Team $team = null): bool
    {
        return array_key_exists($role, $this->all($team));
    }

    public function label(string $role, ?Team $team = null): string
    {
        return $this->all($team)[$role]['label'] ?? $role;
    }

    /** @return list<string> */
    public function permissions(string $role, ?Team $team = null): array
    {
        if ($role === $this->ownerRole()) {
            return ['*'];
        }

        return $this->all($team)[$role]['permissions'] ?? [];
    }

    public function roleAllows(string $role, string $permission, ?Team $team = null): bool
    {
        $permissions = $this->permissions($role, $team);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /**
     * Does a member in `$actorRole` hold everything `$targetRole` grants?
     *
     * The rule for handing out, inviting into and removing from a role:
     * nobody gives more than they have. `*` counts as "everything", and only
     * the owner role covers it: a custom role that someone wrote `*` into is
     * still not an owner.
     */
    public function covers(string $actorRole, string $targetRole, ?Team $team = null): bool
    {
        if ($actorRole === $this->ownerRole()) {
            return true;
        }

        if ($targetRole === $this->ownerRole()) {
            return false;
        }

        $target = $this->permissions($targetRole, $team);

        if (in_array('*', $target, true)) {
            return false;
        }

        $held = $this->permissions($actorRole, $team);

        return in_array('*', $held, true) || array_diff($target, $held) === [];
    }

    /**
     * May the member do `$permission` in the team: through the main role,
     * an additional role or a permission of their own.
     */
    public function can(mixed $user, Team $team, string $permission): bool
    {
        $membership = $team->membershipOf($user);

        if ($membership === null) {
            return false;
        }

        if ($this->roleAllows($membership->role, $permission, $team)) {
            return true;
        }

        foreach ($this->extraRoles($membership) as $role) {
            if ($this->roleAllows($role, $permission, $team)) {
                return true;
            }
        }

        $own = $this->ownPermissions($membership);

        return in_array('*', $own, true) || in_array($permission, $own, true);
    }

    /**
     * The additional roles of a member (not the main one).
     *
     * @return list<string>
     */
    public function extraRoles(Membership $membership): array
    {
        return MemberRole::query()
            ->where('team_id', $membership->team_id)
            ->where('user_id', $membership->user_id)
            ->orderBy('id')
            ->pluck('role')
            ->map(fn ($role) => (string) $role)
            ->all();
    }

    /**
     * The permissions granted to a member alone.
     *
     * @return list<string>
     */
    public function ownPermissions(Membership $membership): array
    {
        return MemberPermission::query()
            ->where('team_id', $membership->team_id)
            ->where('user_id', $membership->user_id)
            ->orderBy('id')
            ->pluck('permission')
            ->map(fn ($permission) => (string) $permission)
            ->all();
    }

    /**
     * Everything a member may do: main role, additional roles and own
     * permissions, once each. `['*']` when anything of it holds everything.
     *
     * @return list<string>
     */
    public function effectivePermissions(Membership $membership, Team $team): array
    {
        $all = $this->permissions($membership->role, $team);

        foreach ($this->extraRoles($membership) as $role) {
            $all = array_merge($all, $this->permissions($role, $team));
        }

        $all = array_values(array_unique(array_merge($all, $this->ownPermissions($membership))));

        return in_array('*', $all, true) ? ['*'] : $all;
    }

    /**
     * Like {@see covers()}, but with everything the actor holds: a member
     * whose additional role grants a permission may also hand it out.
     */
    public function coversAll(Membership $actor, string $targetRole, Team $team): bool
    {
        if ($actor->role === $this->ownerRole()) {
            return true;
        }

        if ($targetRole === $this->ownerRole()) {
            return false;
        }

        $target = $this->permissions($targetRole, $team);

        if (in_array('*', $target, true)) {
            return false;
        }

        $held = $this->effectivePermissions($actor, $team);

        return in_array('*', $held, true) || array_diff($target, $held) === [];
    }

    public function ownerRole(): string
    {
        return (string) config('teams.owner_role', 'owner');
    }

    public function defaultRole(): string
    {
        return (string) config('teams.default_role', 'member');
    }
}
