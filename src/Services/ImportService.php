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
use Ramsey\Uuid\Uuid;

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
    /** @var list<string> */
    protected array $warnings = [];

    public function __construct(
        protected Roles $roles,
        protected JoinCodes $joinCodes,
    ) {}

    public static function uuidForId(int $id): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'statamic-teams:team:'.$id)->toString();
    }

    /**
     * What the imports so far could only take over approximately (an
     * unsupported join method, an unknown invitation status). For the
     * import report; `flushWarnings()` starts a new one.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function flushWarnings(): void
    {
        $this->warnings = [];
    }

    protected function warn(string $team, string $message): void
    {
        $this->warnings[] = "{$team}: {$message}";
    }

    /**
     * The row this import writes into.
     *
     * Only the same team is updated: same uuid, and if an id is given, the
     * same id. A fixed id that another team holds (a different uuid, or none
     * given) stops the import: overwriting it would silently hand that
     * team's members, code and billing address to the imported one.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveTarget(array $data, string $uuid): Team
    {
        $id = isset($data['id']) ? (int) $data['id'] : null;
        $byUuid = $uuid !== '' ? Team::query()->where('uuid', $uuid)->first() : null;

        if ($byUuid !== null) {
            if ($id !== null && (int) $byUuid->id !== $id) {
                throw new TeamsException(TeamsException::IMPORT_COLLISION, __('teams::messages.errors.import_collision')." (uuid {$uuid} is #{$byUuid->id}, not #{$id})");
            }

            return $byUuid;
        }

        if ($id !== null && ($holder = Team::query()->find($id)) !== null) {
            throw new TeamsException(TeamsException::IMPORT_COLLISION, __('teams::messages.errors.import_collision')." (#{$id} {$holder->name}, uuid {$holder->uuid})");
        }

        $team = new Team;

        if ($id !== null) {
            $team->id = $id;
        }

        return $team;
    }

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

        // A file with ids but no uuids must import the same way twice. The
        // uuid is derived from the id (UUID v5), so the second run finds the
        // team of the first, and a team that got the id otherwise (random
        // uuid) still counts as a collision.
        if ($uuid === '' && isset($data['id'])) {
            $uuid = self::uuidForId((int) $data['id']);
        }

        return DB::transaction(function () use ($data, $name, $uuid) {
            $team = $this->resolveTarget($data, $uuid);

            $joinCode = isset($data['join_code']) && $data['join_code'] !== '' ? $this->joinCodes->normalise((string) $data['join_code']) : null;
            $joinMethod = (string) ($data['join_method'] ?? ($joinCode !== null ? Team::JOIN_CODE : Team::JOIN_INVITATION_ONLY));

            if (! in_array($joinMethod, Team::JOIN_METHODS, true)) {
                $this->warn($name, "join_method [{$joinMethod}] is not supported; the team accepts invitations only. The join code is kept and works again once join_method is set to join_code.");
                $joinMethod = Team::JOIN_INVITATION_ONLY;
            }

            $team->forceFill(array_filter([
                'uuid' => $uuid !== '' ? $uuid : ($team->uuid ?: (string) Str::uuid()),
                'name' => $name,
                'type' => (string) ($data['type'] ?? $team->type ?? config('teams.default_type', 'team')),
                'owner_id' => Users::key($data['owner_id'] ?? null) ?? $team->owner_id,
                'join_code' => $joinCode,
                'join_method' => $joinMethod,
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

        // ChoirLive keeps the state in `status` and did not always write
        // `accepted_at`. An accepted invitation without a date must not come
        // back as open: its link would let a second account in.
        $settledAt = $invitation['accepted_at'] ?? $invitation['updated_at'] ?? $invitation['created_at'] ?? now()->toDateTimeString();
        $status = isset($invitation['status']) ? strtolower((string) $invitation['status']) : null;

        switch ($status) {
            case 'accepted':
                $dates['accepted_at'] ??= $settledAt;
                break;
            case 'declined':
            case 'revoked':
            case 'rejected':
            case 'cancelled':
            case 'canceled':
                $dates['revoked_at'] ??= $invitation['updated_at'] ?? $settledAt;
                break;
            case 'expired':
                // Expired with no date would come back open with no end.
                $dates['expires_at'] ??= $invitation['updated_at'] ?? $invitation['created_at'] ?? now()->subSecond()->toDateTimeString();
                break;
            case null:
            case 'pending':
                break;
            default:
                $this->warn($team->name, "invitation for [{$invitation['email']}] has the unknown status [{$status}]; imported as withdrawn.");
                $dates['revoked_at'] ??= $settledAt;
        }

        $open = ($dates['accepted_at'] ?? null) === null && ($dates['revoked_at'] ?? null) === null;

        // An open invitation without an end gets the standard lifetime from
        // today, like a freshly sent one, not a link that works forever.
        if ($open && ($dates['expires_at'] ?? null) === null && ($days = (int) config('teams.invitations.expires_after_days', 7)) > 0) {
            $dates['expires_at'] = now()->addDays($days)->toDateTimeString();
        }

        $existing = Invitation::query()->where('token_hash', $hash)->first();

        // The same token in another team is that team's invitation, not
        // this one's: taking it over would move it, link and all.
        if ($existing !== null && (int) $existing->team_id !== (int) $team->id) {
            throw new TeamsException(TeamsException::IMPORT_COLLISION, __('teams::messages.errors.import_collision')." (invitation for {$existing->email} belongs to team #{$existing->team_id})");
        }

        $invitationRow = $existing ?? new Invitation(['token_hash' => $hash]);

        $invitationRow->forceFill(array_merge([
            'uuid' => $existing !== null ? $existing->uuid : (string) ($invitation['uuid'] ?? Str::uuid()),
            'team_id' => $team->id,
            'email' => Str::lower(trim((string) $invitation['email'])),
            'role' => (string) ($invitation['role'] ?? $this->roles->defaultRole()),
            'meta' => $invitation['meta'] ?? null,
            'invited_by' => Users::key($invitation['invited_by'] ?? null),
        ], array_map(fn ($value) => $value === null ? null : Carbon::parse($value), $dates)))->save();

        return $invitationRow;
    }
}
