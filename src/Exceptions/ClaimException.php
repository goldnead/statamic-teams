<?php

namespace Goldnead\Teams\Exceptions;

use RuntimeException;

class ClaimException extends RuntimeException
{
    public const UNKNOWN_RESOURCE = 'claim_unknown_resource';

    public const NOT_FOUND = 'claim_not_found';

    public const CONFLICT = 'claim_conflict';

    public const INVALID = 'claim_invalid';

    public const RESOURCE_UNAVAILABLE = 'claim_resource_unavailable';

    public const RATE_LIMITED = 'rate_limited';

    public const COOLDOWN = 'cooldown';

    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason);
    }
}
