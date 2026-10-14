<?php

namespace Goldnead\Teams\Contracts;

use Statamic\Contracts\Auth\User;

interface ClaimPolicy
{
    public function assertCanRequest(User $actor): void;

    public function assertCanReview(User $actor): void;
}
