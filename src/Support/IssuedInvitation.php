<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\Invitation;

/**
 * An invitation together with its token, which exists only at this moment.
 *
 * The database keeps the hash. Whoever needs the link (the mail, an API
 * client that shows it for copying) takes it from here.
 */
final class IssuedInvitation
{
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $token,
        public readonly string $url,
    ) {}
}
