<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role one team defines for itself, on top of `teams.roles`.
 *
 * @property int $id
 * @property int $team_id
 * @property string $handle
 * @property string $label
 * @property list<string>|null $permissions
 */
class TeamRole extends Model
{
    protected $table = 'team_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'permissions' => 'array',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
