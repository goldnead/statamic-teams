<?php

namespace Goldnead\Teams\Models;

use Goldnead\Teams\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Auth\User;

/**
 * One person in one team, with the role they hold there.
 *
 * `meta` is the place for what a site adds per membership. ChoirLive keeps
 * the voice part there.
 *
 * @property int $id
 * @property int $team_id
 * @property string $user_id
 * @property string $role
 * @property array<string, mixed>|null $meta
 * @property bool $is_current
 * @property Carbon|null $joined_at
 * @property-read Team|null $team
 */
class Membership extends Model
{
    protected $table = 'team_members';

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'is_current' => 'boolean',
        'joined_at' => 'datetime',
    ];

    /**
     * The additional roles and single permissions of a member go with the
     * membership: a member who leaves and comes back starts clean.
     */
    protected static function booted(): void
    {
        static::deleted(function (Membership $membership) {
            MemberRole::query()->where('team_id', $membership->team_id)->where('user_id', $membership->user_id)->delete();
            MemberPermission::query()->where('team_id', $membership->team_id)->where('user_id', $membership->user_id)->delete();
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): ?User
    {
        return Users::find($this->user_id);
    }
}
