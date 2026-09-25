<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

class MemberRoleChanged extends TeamEvent
{
    public function __construct(
        public Team $team,
        public Membership $membership,
        public string $from,
        public string $to,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.role_changed';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'user' => Users::summary($this->membership->user_id), 'from' => $this->from, 'to' => $this->to, 'actor_id' => $this->actorId]);
    }
}
