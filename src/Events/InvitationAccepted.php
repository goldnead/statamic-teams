<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Support\Users;

class InvitationAccepted extends TeamEvent
{
    public function __construct(
        public Invitation $invitation,
        public Membership $membership,
    ) {}

    public static function handle(): string
    {
        return 'teams.invitation.accepted';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->invitation->team?->summary(), 'invitation' => $this->invitation->summary(), 'user' => Users::summary($this->membership->user_id)]);
    }
}
