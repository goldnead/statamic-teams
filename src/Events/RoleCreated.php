<?php

namespace Goldnead\Teams\Events;

class RoleCreated extends RoleEvent
{
    public static function handle(): string
    {
        return 'teams.role.created';
    }

    public function payload(): array
    {
        return self::typed($this->base() + ['actor_id' => $this->actorId]);
    }
}
