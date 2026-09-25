<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Team;

/**
 * The team this request is about, once the middleware has decided it.
 *
 * Bound as `scoped`, so it is empty again for the next request under Octane
 * and for the next job in a queue worker.
 */
class CurrentTeam
{
    protected ?Team $team = null;

    public function set(?Team $team): void
    {
        $this->team = $team;
    }

    public function get(): ?Team
    {
        return $this->team;
    }

    public function has(): bool
    {
        return $this->team !== null;
    }
}
