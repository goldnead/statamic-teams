<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Contracts\ClaimPolicy;
use Goldnead\Teams\Exceptions\TeamsException;
use Statamic\Contracts\Auth\User;

/** Safe default: a host must explicitly provide its authorization policy. */
class DenyClaimPolicy implements ClaimPolicy
{
    public function assertCanRequest(User $actor): void
    {
        throw TeamsException::because(TeamsException::FORBIDDEN);
    }

    public function assertCanReview(User $actor): void
    {
        throw TeamsException::because(TeamsException::FORBIDDEN);
    }
}
