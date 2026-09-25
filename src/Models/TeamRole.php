<?php

namespace Goldnead\Teams\Models;

use Goldnead\Teams\Support\TeamRoleStore;
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

    /**
     * The cached reads forget on every write through the model. A write
     * through the query builder (`->update()`, `->delete()`) bypasses this;
     * the addon does not do that.
     */
    protected static function booted(): void
    {
        $flush = fn () => app(TeamRoleStore::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
