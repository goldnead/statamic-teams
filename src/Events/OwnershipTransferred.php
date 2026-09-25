<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

class OwnershipTransferred extends TeamEvent
{
    public function __construct(
        public Team $team,
        public ?string $fromUserId,
        public string $toUserId,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.team.ownership_transferred';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'from' => Users::summary($this->fromUserId), 'to' => Users::summary($this->toUserId), 'actor_id' => $this->actorId]);
    }
}
