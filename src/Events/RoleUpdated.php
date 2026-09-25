<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;

class RoleUpdated extends RoleEvent
{
    /**
     * @param  array<string, mixed>  $role
     * @param  list<string>  $changes  `label`, `permissions`
     */
    public function __construct(
        array $role,
        ?Team $team = null,
        public array $changes = [],
        ?string $actorId = null,
    ) {
        parent::__construct($role, $team, $actorId);
    }

    public static function handle(): string
    {
        return 'teams.role.updated';
    }

    public function payload(): array
    {
        return self::typed($this->base() + ['changes' => $this->changes, 'actor_id' => $this->actorId]);
    }
}
