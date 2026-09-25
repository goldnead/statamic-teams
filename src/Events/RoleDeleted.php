<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;

class RoleDeleted extends RoleEvent
{
    /**
     * @param  array<string, mixed>  $role  the role as it was
     * @param  string|null  $reassignedTo  where its holders went, if anybody held it
     */
    public function __construct(
        array $role,
        ?Team $team = null,
        public ?string $reassignedTo = null,
        public int $reassigned = 0,
        ?string $actorId = null,
    ) {
        parent::__construct($role, $team, $actorId);
    }

    public static function handle(): string
    {
        return 'teams.role.deleted';
    }

    public function payload(): array
    {
        return self::typed($this->base() + ['reassigned_to' => $this->reassignedTo, 'reassigned' => $this->reassigned, 'actor_id' => $this->actorId]);
    }
}
