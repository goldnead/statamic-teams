<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An additional role of one member in one team, on top of the main role in
 * `team_members.role`.
 *
 * @property int $id
 * @property int $team_id
 * @property string $user_id
 * @property string $role
 */
class MemberRole extends Model
{
    protected $table = 'team_member_roles';

    protected $guarded = ['id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
