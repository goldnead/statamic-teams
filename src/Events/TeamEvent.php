<?php

namespace Goldnead\Teams\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every domain event of this addon.
 *
 * `payload()` is what leaves the application: into a webhook body, into an
 * automation's context, into the activity log. It carries ids and the fields
 * a receiver needs to act, and never a token or a join code.
 */
abstract class TeamEvent
{
    use Dispatchable;

    /** The stable handle, e.g. `teams.member.joined`. Public contract. */
    abstract public static function handle(): string;

    /** @return array<string, mixed> */
    abstract public function payload(): array;
}
