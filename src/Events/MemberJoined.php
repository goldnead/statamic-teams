<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

class MemberJoined extends TeamEvent
{
    public function __construct(
        public Team $team,
        public Membership $membership,
        public string $via = 'added',
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.joined';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'user' => Users::summary($this->membership->user_id), 'role' => $this->membership->role, 'via' => $this->via, 'actor_id' => $this->actorId]);
    }
}
