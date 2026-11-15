<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

/** A member got an additional role, on top of the main one. */
class MemberRoleAdded extends TeamEvent
{
    public function __construct(
        public Team $team,
        public Membership $membership,
        public string $role,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.role_added';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'user' => Users::summary($this->membership->user_id), 'role' => $this->role, 'actor_id' => $this->actorId]);
    }
}
