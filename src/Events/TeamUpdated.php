<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;

class TeamUpdated extends TeamEvent
{
    /**
     * @param  list<string>  $changes  Names of the changed attributes.
     */
    public function __construct(
        public Team $team,
        public array $changes = [],
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.team.updated';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'changes' => array_values(array_diff($this->changes, ['join_code'])), 'actor_id' => $this->actorId]);
    }
}
