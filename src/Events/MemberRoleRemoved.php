<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

/** An additional role of a member was taken away. The main role is changed, not removed. */
class MemberRoleRemoved extends TeamEvent
{
    public function __construct(
        public Team $team,
        public Membership $membership,
        public string $role,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.role_removed';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'user' => Users::summary($this->membership->user_id), 'role' => $this->role, 'actor_id' => $this->actorId]);
    }
}
