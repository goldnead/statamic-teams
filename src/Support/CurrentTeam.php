<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Team;

/**
 * The team this request is about, once the middleware has decided it.
 *
 * `resolved()` tells "the middleware decided: no team" apart from "nobody
 * decided yet". Only in the second case may `Teams::current()` fall back to
 * the user's current team.
 *
 * Bound as `scoped`, so it is empty again for the next request under Octane
 * and for the next job in a queue worker.
 */
class CurrentTeam
{
    protected ?Team $team = null;

    protected bool $resolved = false;

    public function set(?Team $team): void
    {
        $this->team = $team;
        $this->resolved = true;
    }

    public function get(): ?Team
    {
        return $this->team;
    }

    public function has(): bool
    {
        return $this->team !== null;
    }

    public function resolved(): bool
    {
        return $this->resolved;
    }
}
