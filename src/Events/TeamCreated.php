<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;

class TeamCreated extends TeamEvent
{
    public function __construct(
        public Team $team,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.team.created';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'actor_id' => $this->actorId]);
    }
}
