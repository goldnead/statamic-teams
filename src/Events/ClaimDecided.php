<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\TeamClaim;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class ClaimDecided implements ShouldDispatchAfterCommit
{
    public function __construct(public TeamClaim $claim) {}
}
