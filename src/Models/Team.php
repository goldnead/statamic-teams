<?php

namespace Goldnead\Teams\Models;

use Goldnead\Teams\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A workspace: a choir, a company, a household. People are in it through
 * {@see Membership}, each with a role that counts in this team only.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $type
 * @property string|null $owner_id
 * @property string|null $join_code
 * @property string $join_method
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $billing
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Team extends Model
{
    public const TYPE_PERSONAL = 'personal';

    public const JOIN_INVITATION_ONLY = 'invitation_only';

    public const JOIN_CODE = 'join_code';

    /**
     * The alias under which a team appears as a polymorphic subject, for
     * instance in `entitlements.subject_type`. Registered in the morph map
     * by the service provider.
     */
    public const MORPH_ALIAS = 'team';

    protected $table = 'teams';

    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'billing' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            if (empty($team->uuid)) {
                $team->uuid = (string) Str::uuid();
            }

            if (empty($team->type)) {
                $team->type = (string) config('teams.default_type', 'team');
            }

            if (empty($team->join_method)) {
                $team->join_method = self::JOIN_INVITATION_ONLY;
            }
        });
    }

    /** @return HasMany<Membership, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<TeamRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(TeamRole::class);
    }

    public function membershipOf(mixed $user): ?Membership
    {
        $key = Users::key($user);

        if ($key === null) {
            return null;
        }

        return $this->members()->where('user_id', $key)->first();
    }

    public function hasMember(mixed $user): bool
    {
        $key = Users::key($user);

        return $key !== null && $this->members()->where('user_id', $key)->exists();
    }

    public function roleOf(mixed $user): ?string
    {
        return $this->membershipOf($user)?->role;
    }

    public function isOwner(mixed $user): bool
    {
        return $this->roleOf($user) === config('teams.owner_role', 'owner');
    }

    public function isPersonal(): bool
    {
        return $this->type === self::TYPE_PERSONAL;
    }

    /**
     * A team that may be read but not changed, for instance after its plan
     * ended. Set through `settings.read_only`; `teams.writable` turns it
     * into a 423 for every write request.
     */
    public function isReadOnly(): bool
    {
        return (bool) ($this->settings['read_only'] ?? false);
    }

    public function allowsJoinCode(): bool
    {
        return $this->join_method === self::JOIN_CODE && ! empty($this->join_code);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * What an event, a webhook or an API response may say about a team.
     * No join code: whoever reads a webhook log is not thereby a member.
     *
     * @return array{id: int, uuid: string, name: string, type: string, owner_id: string|null}
     */
    public function summary(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'uuid' => (string) $this->uuid,
            'name' => (string) $this->name,
            'type' => (string) $this->type,
            'owner_id' => $this->owner_id,
        ];
    }
}
