<?php

namespace Goldnead\Teams\Contracts;

use Goldnead\Teams\Models\Team;

/** A host resource that can be claimed by a team. */
interface ClaimResource
{
    /** Lock the target row and fail if it is no longer claimable. */
    public function lock(string $key, string $site): void;

    public function teamName(string $key, string $site): string;

    /** Persist the binding only; the claim service owns the transaction. */
    public function bind(string $key, string $site, Team $team): void;
}
