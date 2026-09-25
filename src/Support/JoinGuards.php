<?php

namespace Goldnead\Teams\Support;

use Closure;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;

/**
 * Vetoes before somebody enters a team.
 *
 * A guard receives the team, the user key and how the user comes in
 * (`invitation`, `join_code`, `added`, `created`) and returns null to let
 * them in or a reason code to refuse. That is where a seat limit belongs:
 * the addon does not count seats itself, because the limit lives with
 * whatever sold the seats (offers seat pools, entitlements quotas).
 */
class JoinGuards
{
    /** @var list<Closure(Team, string, string): (string|null)> */
    protected array $guards = [];

    /** @param  Closure(Team, string, string): (string|null)  $guard */
    public function add(Closure $guard): void
    {
        $this->guards[] = $guard;
    }

    public function check(Team $team, string $userKey, string $via): void
    {
        foreach ($this->guards as $guard) {
            $reason = $guard($team, $userKey, $via);

            if (is_string($reason) && $reason !== '') {
                throw new TeamsException($reason, app('translator')->has("teams::messages.errors.{$reason}")
                    ? __("teams::messages.errors.{$reason}")
                    : __('teams::messages.errors.join_refused'));
            }
        }
    }

    public function flush(): void
    {
        $this->guards = [];
    }
}
