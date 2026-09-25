<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Invitation;

class InvitationRevoked extends TeamEvent
{
    public function __construct(
        public Invitation $invitation,
        public ?string $actorId = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.invitation.revoked';
    }

    public function payload(): array
    {
        return self::typed(['team' => $this->invitation->team?->summary(), 'invitation' => $this->invitation->summary(), 'actor_id' => $this->actorId]);
    }
}
