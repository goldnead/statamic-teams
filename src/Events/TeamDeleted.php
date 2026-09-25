<?php

namespace Goldnead\Teams\Events;


class TeamDeleted extends TeamEvent
{
    /**
     * @param  array<string, mixed>  $team  The team as it was, the row is gone.
     */
    public function __construct(
        public array $team,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.team.deleted';
    }

    public function payload(): array
    {
        return ['team' => $this->team, 'actor_id' => $this->actorId];
    }
}
