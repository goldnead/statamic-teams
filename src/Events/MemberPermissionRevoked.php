<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;

/** A permission of one member was taken away again. */
class MemberPermissionRevoked extends TeamEvent
{
    public function __construct(
        public Team $team,
        public Membership $membership,
        public string $permission,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.member.permission_revoked';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->team->summary(), 'user' => Users::summary($this->membership->user_id), 'permission' => $this->permission, 'actor_id' => $this->actorId]);
    }
}
