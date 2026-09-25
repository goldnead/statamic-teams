<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamRole;

/**
 * The roles each team defined for itself, read once per team and request.
 *
 * `Teams::can()` resolves the roles of a team on every call; without this a
 * page checking five permissions asked the database five times. Bound
 * `scoped` like {@see GlobalRoleStore}, and emptied whenever a `TeamRole` is
 * saved or deleted through the model (see its `booted()`).
 */
class TeamRoleStore
{
    /** @var array<int, list<TeamRole>> */
    protected array $rows = [];

    /** @return list<TeamRole> */
    public function for(Team $team): array
    {
        $key = (int) $team->getKey();

        return $this->rows[$key] ??= $team->roles()->orderBy('id')->get()->all();
    }

    public function flush(): void
    {
        $this->rows = [];
    }
}
