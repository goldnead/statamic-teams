<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\JoinCodes;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Taking over teams from another system with their identity intact.
 *
 * Written for moving ChoirLive's tenants: a team keeps its id and uuid (other
 * tables and the app point at them), its join code keeps working, and every
 * member keeps role and meta. The import is idempotent by uuid: running it
 * twice updates, it does not duplicate.
 *
 * No events are fired. An import is not twenty people joining a team today,
 * and it must not send twenty welcome mails or start twenty automations.
 */
class ImportService
{
    public function __construct(
        protected Roles $roles,
        protected JoinCodes $joinCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $data  See README, "Importing teams".
     */
    public function import(array $data): Team
    {
        $name = trim((string) ($data['name'] ?? ''));
        $uuid = (string) ($data['uuid'] ?? '');

        if ($name === '') {
            throw new InvalidArgumentException('A team needs a name.');
        }

        if ($uuid !== '' && ! Str::isUuid($uuid)) {
            throw new InvalidArgumentException("[{$uuid}] is not a UUID.");
        }

        return DB::transaction(function () use ($data, $name, $uuid) {
            $team = $uuid !== '' ? Team::query()->where('uuid', $uuid)->first() : null;
            $team ??= isset($data['id']) ? Team::query()->find((int) $data['id']) : null;
            $team ??= new Team;

            if (! $team->exists && isset($data['id'])) {
                $team->id = (int) $data['id'];
            }

            $joinCode = isset($data['join_code']) && $data['join_code'] !== '' ? $this->joinCodes->normalise((string) $data['join_code']) : null;

            $team->forceFill(array_filter([
                'uuid' => $uuid !== '' ? $uuid : ($team->uuid ?: (string) Str::uuid()),
                'name' => $name,
                'type' => (string) ($data['type'] ?? $team->type ?? config('teams.default_type', 'team')),
                'owner_id' => Users::key($data['owner_id'] ?? null) ?? $team->owner_id,
                'join_code' => $joinCode,
                'join_method' => (string) ($data['join_method'] ?? ($joinCode !== null ? Team::JOIN_CODE : Team::JOIN_INVITATION_ONLY)),
                'settings' => $data['settings'] ?? $team->settings,
                'billing' => $data['billing'] ?? $team->billing,
                'created_at' => isset($data['created_at']) ? Carbon::parse($data['created_at']) : null,
            ], fn ($value) => $value !== null));

            $team->save();

            foreach ((array) ($data['roles'] ?? []) as $role) {
                $team->roles()->updateOrCreate(
                    ['handle' => (string) $role['handle']],
                    ['label' => (string) ($role['label'] ?? $role['handle']), 'permissions' => array_values((array) ($role['permissions'] ?? []))],
                );
            }

            foreach ((array) ($data['members'] ?? []) as $member) {
                $this->importMember($team, (array) $member);
            }

            foreach ((array) ($data['invitations'] ?? []) as $invitation) {
                $this->importInvitation($team, (array) $invitation);
            }

            if ($team->owner_id === null) {
                $owner = $team->members()->where('role', $this->roles->ownerRole())->orderBy('id')->first();

                if ($owner !== null) {
                    $team->forceFill(['owner_id' => $owner->user_id])->save();
                }
            }

            return $team->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $member
     */
    protected function importMember(Team $team, array $member): Membership
    {
        $key = Users::key($member['user_id'] ?? null);

        if ($key === null && ! empty($member['email'])) {
            $key = Users::key(Users::findByEmail((string) $member['email']));
        }

        if ($key === null) {
            throw new InvalidArgumentException('A member needs a user_id or the email of an existing user.');
        }

        $role = (string) ($member['role'] ?? $this->roles->defaultRole());

        if (! $this->roles->exists($role, $team)) {
            throw new TeamsException(TeamsException::UNKNOWN_ROLE, "Unknown role [{$role}] in team [{$team->name}]. Map it or import it under `roles`.");
        }

        $isCurrent = (bool) ($member['is_current'] ?? false);

        if ($isCurrent) {
            Membership::query()->where('user_id', $key)->where('team_id', '!=', $team->id)->update(['is_current' => false]);
        }

        return Membership::query()->updateOrCreate(
            ['team_id' => $team->id, 'user_id' => $key],
            [
                'role' => $role,
                'meta' => $member['meta'] ?? null,
                'is_current' => $isCurrent,
                'joined_at' => isset($member['joined_at']) ? Carbon::parse($member['joined_at']) : now(),
            ],
        );
    }

    /**
     * A plain `token` is hashed on the way in, so a link that is already in
     * somebody's inbox keeps working. A `token_hash` is taken as it is.
     *
     * @param  array<string, mixed>  $invitation
     */
    protected function importInvitation(Team $team, array $invitation): Invitation
    {
        $hash = isset($invitation['token']) && $invitation['token'] !== ''
            ? Invitation::hashToken((string) $invitation['token'])
            : (string) ($invitation['token_hash'] ?? '');

        if ($hash === '') {
            $hash = Invitation::hashToken(Str::random(48));
        }

        $dates = Arr::only($invitation, ['expires_at', 'accepted_at', 'revoked_at', 'created_at']);

        return Invitation::query()->updateOrCreate(
            ['token_hash' => $hash],
            array_merge([
                'uuid' => (string) ($invitation['uuid'] ?? Str::uuid()),
                'team_id' => $team->id,
                'email' => Str::lower(trim((string) $invitation['email'])),
                'role' => (string) ($invitation['role'] ?? $this->roles->defaultRole()),
                'meta' => $invitation['meta'] ?? null,
                'invited_by' => Users::key($invitation['invited_by'] ?? null),
            ], array_map(fn ($value) => $value === null ? null : Carbon::parse($value), $dates)),
        );
    }
}
