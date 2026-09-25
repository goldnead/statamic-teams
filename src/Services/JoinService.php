<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Support\JoinCodes;

/**
 * Joining a team with its code.
 *
 * Only for teams whose join method is `join_code`. Whoever joins this way
 * gets the default role; the team can raise it afterwards.
 */
class JoinService
{
    public function __construct(
        protected MembershipService $memberships,
        protected JoinCodes $joinCodes,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function join(string $code, mixed $user, array $meta = []): Membership
    {
        $team = $this->joinCodes->find($code) ?? throw TeamsException::because(TeamsException::JOIN_CODE_INVALID);

        if (! $team->allowsJoinCode()) {
            throw TeamsException::because(TeamsException::JOIN_DISABLED);
        }

        return $this->memberships->add($team, $user, null, $meta, 'join_code');
    }
}
