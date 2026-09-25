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

    /**
     * `team_type` next to `team`, at the top of the payload: `personal` or
     * `team` (or a host's own type). The same value as `team.type`, lifted so
     * a webhook filter or a flow condition that reads only the first level
     * can tell a personal team from a real one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected static function typed(array $payload): array
    {
        $team = $payload['team'] ?? null;
        $payload['team_type'] = is_array($team) && is_string($team['type'] ?? null) ? $team['type'] : null;

        return $payload;
    }
}
