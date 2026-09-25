<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Events\OwnershipTransferred;
use Goldnead\Teams\Events\TeamCreated;
use Goldnead\Teams\Events\TeamDeleted;
use Goldnead\Teams\Events\TeamUpdated;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\JoinCodes;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creating, changing and deleting teams.
 */
class TeamService
{
    /** The attributes `update()` accepts. Everything else is ignored. */
    public const UPDATABLE = ['name', 'join_method', 'settings', 'billing'];

    public function __construct(
        protected MembershipService $memberships,
        protected Authorizer $authorizer,
        protected JoinCodes $joinCodes,
        protected Roles $roles,
    ) {}

    /**
     * Create a team. The owner, if given, becomes its first member with the
     * owner role.
     *
     * @param  array{type?: string, join_method?: string, settings?: array<string, mixed>, billing?: array<string, mixed>, meta?: array<string, mixed>}  $attributes
     */
    public function create(string $name, mixed $owner = null, array $attributes = []): Team
    {
        $ownerKey = Users::key($owner);
        $type = (string) ($attributes['type'] ?? config('teams.default_type', 'team'));

        if ($type === Team::TYPE_PERSONAL && $ownerKey !== null
            && Team::query()->where('type', Team::TYPE_PERSONAL)->where('owner_id', $ownerKey)->exists()) {
            throw TeamsException::because(TeamsException::PERSONAL_TEAM);
        }

        $team = DB::transaction(function () use ($name, $ownerKey, $type, $attributes) {
            $joinMethod = (string) ($attributes['join_method'] ?? Team::JOIN_INVITATION_ONLY);

            return Team::query()->create([
                'name' => trim($name),
                'type' => $type,
                'owner_id' => $ownerKey,
                'join_method' => $joinMethod,
                'join_code' => $joinMethod === Team::JOIN_CODE ? $this->joinCodes->generate() : null,
                'settings' => $attributes['settings'] ?? null,
                'billing' => $attributes['billing'] ?? null,
            ]);
        });

        event(new TeamCreated($team, $ownerKey));

        if ($ownerKey !== null) {
            $this->memberships->add($team, $ownerKey, $this->roles->ownerRole(), (array) ($attributes['meta'] ?? []), 'created');
        }

        return $team;
    }

    /**
     * Change name, join method, settings or billing address.
     *
     * Turning on joining by code without a code creates one: a team set to
     * "join by code" with no code is a team nobody can join, and nothing on
     * screen would say why.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Team $team, array $attributes, mixed $actor = null): Team
    {
        $permission = array_key_exists('billing', $attributes) ? 'manage billing' : 'update team';
        $this->authorizer->authorize($actor, $team, $permission);

        if (array_key_exists('billing', $attributes) && count($attributes) > 1) {
            $this->authorizer->authorize($actor, $team, 'update team');
        }

        $values = Arr::only($attributes, self::UPDATABLE);

        if (isset($values['settings']) && is_array($values['settings'])) {
            $values['settings'] = array_merge($team->settings ?? [], $values['settings']);
        }

        if (isset($values['billing']) && is_array($values['billing'])) {
            $values['billing'] = array_filter(
                array_merge($team->billing ?? [], $values['billing']),
                fn ($value) => $value !== null && $value !== ''
            );
        }

        $team->fill($values);

        if ($team->join_method === Team::JOIN_CODE && empty($team->join_code)) {
            $team->join_code = $this->joinCodes->generate();
        }

        $changed = array_keys($team->getDirty());

        if ($changed === []) {
            return $team;
        }

        $team->save();

        event(new TeamUpdated($team, $changed, $this->authorizer->actorKey($actor)));

        return $team;
    }

    /**
     * A fresh join code. The old one stops working at once: this is what an
     * owner does after a code ended up somewhere it should not be.
     */
    public function regenerateJoinCode(Team $team, mixed $actor = null): string
    {
        $this->authorizer->authorize($actor, $team, 'update team');

        $team->join_code = $this->joinCodes->generate();
        $team->save();

        event(new TeamUpdated($team, ['join_code'], $this->authorizer->actorKey($actor)));

        return $team->join_code;
    }

    public function delete(Team $team, mixed $actor = null): void
    {
        $this->authorizer->authorize($actor, $team, 'delete team');

        $summary = $team->summary();
        $team->delete();

        event(new TeamDeleted($summary, $this->authorizer->actorKey($actor)));
    }

    /**
     * Hand the team to another member. The previous owner stays, as admin.
     */
    public function transferOwnership(Team $team, mixed $to, mixed $actor = null): Team
    {
        if ($actor !== null && ! $team->isOwner($actor)) {
            throw TeamsException::because($team->hasMember($actor) ? TeamsException::FORBIDDEN : TeamsException::NOT_MEMBER);
        }

        $toKey = Users::key($to);
        $membership = $team->membershipOf($toKey) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);
        // Who hands over is the actor, not whoever `owner_id` happens to
        // name: in a team with two owners, the other one keeps the role.
        $fromKey = $this->authorizer->actorKey($actor) ?? $team->owner_id;
        $owner = $this->roles->ownerRole();

        if ($fromKey === $toKey) {
            throw TeamsException::because(TeamsException::ALREADY_OWNER);
        }

        DB::transaction(function () use ($team, $membership, $fromKey, $toKey, $owner) {
            $membership->update(['role' => $owner]);

            if ($fromKey !== null) {
                $team->members()->where('user_id', $fromKey)->update(['role' => array_key_exists('admin', $this->roles->all($team)) ? 'admin' : $this->roles->defaultRole()]);
            }

            $team->owner_id = $toKey;
            $team->save();
        });

        event(new OwnershipTransferred($team, $fromKey, (string) $toKey, $this->authorizer->actorKey($actor)));

        return $team;
    }

    /**
     * The user's personal team, created on first use.
     */
    public function personalTeam(mixed $user): Team
    {
        $key = Users::key($user) ?? throw TeamsException::because(TeamsException::NOT_MEMBER);

        $existing = Team::query()->where('type', Team::TYPE_PERSONAL)->where('owner_id', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = Users::name($key) ?? __('teams::messages.personal_team');

        return $this->create(__('teams::messages.personal_team_of', ['name' => $name]), $key, ['type' => Team::TYPE_PERSONAL]);
    }

    public function find(int|string|null $id): ?Team
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_int($id) || ctype_digit((string) $id)) {
            return Team::query()->find((int) $id);
        }

        return Team::query()->where('uuid', (string) $id)->first();
    }
}
