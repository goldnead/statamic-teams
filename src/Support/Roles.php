<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Team;

/**
 * Roles and permissions inside a team.
 *
 * A role is looked up in the team first (`team_roles`), then in
 * `teams.roles`. The owner role always holds every permission, whatever a
 * team or the config writes for it: a team whose owner can lock himself out
 * is a support ticket.
 */
class Roles
{
    /**
     * @return array<string, array{label: string, permissions: list<string>, custom: bool}>
     */
    public function all(?Team $team = null): array
    {
        $roles = [];

        foreach ((array) config('teams.roles', []) as $handle => $role) {
            $roles[(string) $handle] = [
                // Through __(): config labels are English and a German CP
                // translates them from lang/de.json.
                'label' => (string) __((string) ($role['label'] ?? $handle)),
                'permissions' => array_values(array_map('strval', (array) ($role['permissions'] ?? []))),
                'custom' => false,
            ];
        }

        if ($team !== null && $team->exists) {
            foreach ($team->roles()->get() as $role) {
                $roles[$role->handle] = [
                    'label' => $role->label,
                    'permissions' => array_values(array_map('strval', (array) ($role->permissions ?? []))),
                    'custom' => true,
                ];
            }
        }

        return $roles;
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

    public function can(mixed $user, Team $team, string $permission): bool
    {
        $role = $team->roleOf($user);

        return $role !== null && $this->roleAllows($role, $permission, $team);
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
