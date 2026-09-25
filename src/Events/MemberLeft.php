<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

class MemberLeft extends TeamEvent
{
    public function __construct(
        public Team $team,
        public string $userId,
        public string $role,
        public string $reason = 'left',
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.left';
    }

    public function payload(): array
    {
        return ['team' => $this->team->summary(), 'user' => Users::summary($this->userId), 'role' => $this->role, 'reason' => $this->reason, 'actor_id' => $this->actorId];
    }
}
