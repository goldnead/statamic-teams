<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Events\MemberRoleChanged;
use Goldnead\Teams\Events\RoleCreated;
use Goldnead\Teams\Events\RoleDeleted;
use Goldnead\Teams\Events\RoleUpdated;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\GlobalRole;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\GlobalRoleStore;
use Goldnead\Teams\Support\Permissions;
use Goldnead\Teams\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Creating, changing and deleting roles: global ones (`$team` null) and
 * those of one team.
 *
 * The rules, for every caller (CP, app-api, a host's code):
 *
 * - `*` belongs to the owner role only; the owner role keeps it and can only
 *   be renamed. Neither the owner role nor the default role is deleted.
 * - A role only lists known permissions ({@see Permissions}).
 * - A role somebody holds (member or open invitation) is deleted only
 *   together with a role to move them to; never into the owner role.
 * - Global roles are site business: no team member changes them, only the
 *   system (CP with `manage team roles`, console, code without actor).
 * - A team member changing the roles of their team needs the team
 *   permission `manage team roles`, and cannot hand out more than they
 *   hold: every permission of a role they write, and of a role they
 *   change, delete or move people into, must be theirs. Their own role is
 *   an owner's business.
 */
class RoleService
{
    public const HANDLE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function __construct(
        protected Roles $roles,
        protected Permissions $permissions,
        protected Authorizer $authorizer,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed> the role as {@see Roles::all()} shows it, plus `handle`
     */
    public function create(string $handle, string $label, array $permissions = [], ?Team $team = null, mixed $actor = null): array
    {
        $this->authorizeScope($team, $actor);

        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            throw TeamsException::because(TeamsException::INVALID_ROLE_HANDLE);
        }

        $permissions = $this->checkPermissions($handle, $permissions);
        $label = $this->checkLabel($label, $handle);

        if ($team === null) {
            if (array_key_exists($handle, $this->roles->global())) {
                throw TeamsException::because(TeamsException::ROLE_EXISTS);
            }

            // A deleted config role comes back through its tombstone row.
            GlobalRole::query()->updateOrCreate(['handle' => $handle], ['label' => $label, 'permissions' => $permissions, 'removed' => false]);
        } else {
            if ($handle === $this->roles->ownerRole()) {
                throw TeamsException::because(TeamsException::ROLE_PROTECTED);
            }

            if ($team->roles()->where('handle', $handle)->exists()) {
                throw TeamsException::because(TeamsException::ROLE_EXISTS);
            }

            // Adjusting a global role for this team is changing that role
            // for everybody in the team who holds it.
            if (array_key_exists($handle, $this->roles->all($team))) {
                $this->authorizeTouching($actor, $team, $handle);
            }

            $this->authorizeGrant($actor, $team, $permissions);

            $team->roles()->create(['handle' => $handle, 'label' => $label, 'permissions' => $permissions]);
        }

        $this->flush();
        $role = $this->find($handle, $team);

        event(new RoleCreated($role, $team, $this->authorizer->actorKey($actor)));

        return $role;
    }

    /**
     * @param  array{label?: string, permissions?: list<string>}  $attributes
     * @return array<string, mixed>
     */
    public function update(string $handle, array $attributes, ?Team $team = null, mixed $actor = null): array
    {
        $this->authorizeScope($team, $actor);

        $before = $this->find($handle, $team);

        if ($team !== null && $before['scope'] !== 'team') {
            // Only the team's own version is changed here; a global role is
            // adjusted for a team by creating one of the same handle.
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        $label = array_key_exists('label', $attributes) ? $this->checkLabel((string) $attributes['label'], $handle) : $before['label'];
        $permissions = $before['permissions'];

        if (array_key_exists('permissions', $attributes)) {
            if ($handle === $this->roles->ownerRole()) {
                if (array_values((array) $attributes['permissions']) !== ['*']) {
                    throw TeamsException::because(TeamsException::ROLE_PROTECTED);
                }
            } else {
                $permissions = $this->checkPermissions($handle, (array) $attributes['permissions']);
            }
        }

        if ($team !== null) {
            $this->authorizeTouching($actor, $team, $handle);
            $this->authorizeGrant($actor, $team, $permissions);
        }

        $changes = array_keys(array_filter([
            'label' => $label !== $before['label'],
            'permissions' => $permissions !== $before['permissions'],
        ]));

        if ($changes === []) {
            return $before;
        }

        if ($team === null) {
            GlobalRole::query()->updateOrCreate(['handle' => $handle], ['label' => $label, 'permissions' => $permissions, 'removed' => false]);
        } else {
            $team->roles()->where('handle', $handle)->firstOrFail()->update(['label' => $label, 'permissions' => $permissions]);
        }

        $this->flush();
        $role = $this->find($handle, $team);

        event(new RoleUpdated($role, $team, $changes, $this->authorizer->actorKey($actor)));

        return $role;
    }

    /**
     * Delete a role, moving whoever holds it to `$reassignTo` first.
     *
     * @return int how many memberships were moved
     */
    public function delete(string $handle, ?Team $team = null, ?string $reassignTo = null, mixed $actor = null): int
    {
        $this->authorizeScope($team, $actor);

        $role = $this->find($handle, $team);

        if ($team !== null && $role['scope'] !== 'team') {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        if ($handle === $this->roles->ownerRole() || ($team === null && $handle === $this->roles->defaultRole())) {
            throw TeamsException::because(TeamsException::ROLE_PROTECTED);
        }

        if ($team !== null) {
            $this->authorizeTouching($actor, $team, $handle);
        }

        // A team's version of a global role: its members fall back to the
        // global one and keep their role. Nobody needs to move.
        $survives = $team !== null && array_key_exists($handle, $this->roles->global());
        $usage = $survives ? ['members' => 0, 'invitations' => 0] : $this->usage($handle, $team);
        $held = $usage['members'] + $usage['invitations'] > 0;

        if ($held && $reassignTo === null) {
            throw TeamsException::because(TeamsException::ROLE_IN_USE, $usage);
        }

        if ($held) {
            $this->checkReassignTarget($handle, (string) $reassignTo, $team, $actor);
        }

        $moved = DB::transaction(function () use ($handle, $team, $held, $reassignTo) {
            $moved = $held ? $this->reassign($handle, (string) $reassignTo, $team) : [];

            if ($team === null) {
                if (array_key_exists($handle, $this->roles->configured())) {
                    GlobalRole::query()->updateOrCreate(['handle' => $handle], ['label' => $this->roles->configured()[$handle]['label'], 'permissions' => null, 'removed' => true]);
                } else {
                    GlobalRole::query()->where('handle', $handle)->delete();
                }
            } else {
                $team->roles()->where('handle', $handle)->delete();
            }

            return $moved;
        });

        $this->flush();

        $actorKey = $this->authorizer->actorKey($actor);

        foreach ($moved as $membership) {
            event(new MemberRoleChanged($membership->team, $membership, $handle, (string) $reassignTo, $actorKey));
        }

        event(new RoleDeleted($role, $team, $held ? $reassignTo : null, count($moved), $actorKey));

        return count($moved);
    }

    /**
     * Back to the config's version of a global role: its CP changes, or its
     * deletion, are dropped.
     *
     * @return array<string, mixed>
     */
    public function reset(string $handle): array
    {
        if (! array_key_exists($handle, $this->roles->configured())) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        $row = GlobalRole::query()->where('handle', $handle)->first();

        if ($row === null) {
            return $this->find($handle);
        }

        $before = $row->removed ? null : $this->find($handle);
        $row->delete();
        $this->flush();

        $role = $this->find($handle);

        if ($before === null) {
            event(new RoleCreated($role, null, null));
        } else {
            $changes = array_keys(array_filter([
                'label' => $role['label'] !== $before['label'],
                'permissions' => $role['permissions'] !== $before['permissions'],
            ]));

            if ($changes !== []) {
                event(new RoleUpdated($role, null, $changes, null));
            }
        }

        return $role;
    }

    /**
     * Who holds a role: members and open invitations. For a global role,
     * across every team that does not define its own role of that handle.
     *
     * @return array{members: int, invitations: int}
     */
    public function usage(string $handle, ?Team $team = null): array
    {
        return [
            'members' => $this->holders(Membership::query(), $handle, $team)->count(),
            'invitations' => $this->holders(Invitation::query()->pending(), $handle, $team)->count(),
        ];
    }

    /**
     * Usage of every role at once, for the lists.
     *
     * @return array<string, int> handle => members
     */
    public function memberCounts(?Team $team = null): array
    {
        $query = Membership::query();

        if ($team !== null) {
            $query->where('team_id', $team->id);
        } else {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('team_roles')
                ->whereColumn('team_roles.team_id', 'team_members.team_id')
                ->whereColumn('team_roles.handle', 'team_members.role'));
        }

        return $query->groupBy('role')->selectRaw('role, count(*) as total')->pluck('total', 'role')
            ->map(fn ($total) => (int) $total)->all();
    }

    /** @return array<string, mixed> */
    public function find(string $handle, ?Team $team = null): array
    {
        $roles = $team === null ? $this->roles->global() : $this->roles->all($team);

        if (! array_key_exists($handle, $roles)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        return ['handle' => $handle] + $roles[$handle];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function holders(Builder $query, string $handle, ?Team $team): Builder
    {
        $table = $query->getModel()->getTable();
        $query->where($table.'.role', $handle);

        if ($team !== null) {
            return $query->where($table.'.team_id', $team->id);
        }

        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('team_roles')
            ->whereColumn('team_roles.team_id', $table.'.team_id')
            ->where('team_roles.handle', $handle));
    }

    /** @return list<Membership> */
    protected function reassign(string $handle, string $to, ?Team $team): array
    {
        $members = $this->holders(Membership::query(), $handle, $team)->with('team')->get()->all();

        foreach ($members as $membership) {
            $membership->role = $to;
            $membership->save();
        }

        $this->holders(Invitation::query()->pending(), $handle, $team)->update(['role' => $to]);

        return $members;
    }

    protected function checkReassignTarget(string $handle, string $to, ?Team $team, mixed $actor): void
    {
        if ($to === $this->roles->ownerRole()) {
            throw TeamsException::because(TeamsException::ROLE_PROTECTED);
        }

        $available = $team === null ? $this->roles->global() : $this->roles->all($team);

        if ($to === $handle || ! array_key_exists($to, $available)) {
            throw TeamsException::because(TeamsException::UNKNOWN_ROLE);
        }

        if ($team === null) {
            // A team that defines its own role of the target handle keeps
            // its version, which is fine; one that lacks the target handle
            // cannot exist, the target is global.
            return;
        }

        $this->authorizer->authorizeRole($actor, $team, $to);
    }

    /**
     * @param  array<mixed>  $permissions
     * @return list<string>
     */
    protected function checkPermissions(string $handle, array $permissions): array
    {
        $permissions = array_values(array_unique(array_map('strval', $permissions)));

        if ($handle === $this->roles->ownerRole()) {
            return ['*'];
        }

        if (in_array('*', $permissions, true)) {
            throw TeamsException::because(TeamsException::WILDCARD);
        }

        $unknown = array_values(array_diff($permissions, $this->permissions->handles()));

        if ($unknown !== []) {
            throw TeamsException::because(TeamsException::UNKNOWN_PERMISSION, ['permissions' => implode(', ', $unknown)]);
        }

        return $permissions;
    }

    protected function checkLabel(string $label, string $handle): string
    {
        $label = trim($label);

        return $label === '' ? $handle : mb_substr($label, 0, 191);
    }

    /** Global roles: the system only. A team: `manage team roles` there. */
    protected function authorizeScope(?Team $team, mixed $actor): void
    {
        if ($actor === null) {
            return;
        }

        if ($team === null) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        $this->authorizer->authorize($actor, $team, 'manage team roles');
    }

    /**
     * Changing, replacing or deleting a role that exists: not one's own
     * (unless owner), and not one holding more than one's own.
     */
    protected function authorizeTouching(mixed $actor, Team $team, string $handle): void
    {
        if ($actor === null || $this->authorizer->isOwner($actor, $team)) {
            return;
        }

        if ($team->roleOf($actor) === $handle) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }

        $this->authorizer->authorizeRole($actor, $team, $handle);
    }

    /** @param  list<string>  $permissions */
    protected function authorizeGrant(mixed $actor, Team $team, array $permissions): void
    {
        if ($actor === null || $this->authorizer->isOwner($actor, $team)) {
            return;
        }

        $held = $this->roles->permissions((string) $team->roleOf($actor), $team);

        if (! in_array('*', $held, true) && array_diff($permissions, $held) !== []) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }
    }

    protected function flush(): void
    {
        app(GlobalRoleStore::class)->flush();
    }
}
