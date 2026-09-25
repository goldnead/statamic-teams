<?php

namespace Goldnead\Teams\Services;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Users;

/**
 * The permission check every service runs when it is handed an actor.
 *
 * `null` as actor means the system: the Control Panel (which has its own
 * Statamic permissions), a console command, an import. Anything reachable
 * from the front end or an API passes the signed-in user, and then the
 * team role decides.
 */
class Authorizer
{
    public function __construct(protected Roles $roles) {}

    public function authorize(mixed $actor, Team $team, string $permission): void
    {
        if ($actor === null) {
            return;
        }

        if (! $team->hasMember($actor)) {
            throw TeamsException::because(TeamsException::NOT_MEMBER);
        }

        if (! $this->roles->can($actor, $team, $permission)) {
            throw TeamsException::because(TeamsException::FORBIDDEN);
        }
    }

    public function actorKey(mixed $actor): ?string
    {
        return Users::key($actor);
    }
}
