<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permission granted to one member in one team, independent of any role.
 *
 * @property int $id
 * @property int $team_id
 * @property string $user_id
 * @property string $permission
 */
class MemberPermission extends Model
{
    protected $table = 'team_member_permissions';

    protected $guarded = ['id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
