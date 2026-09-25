<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Invitation;

class InvitationSent extends TeamEvent
{
    /**
     * `$acceptUrl` carries the token and exists for the mail listener in
     * this request only. It is deliberately not in `payload()`: a webhook
     * log or an automation run is not a place for a working invitation.
     */
    public function __construct(
        public Invitation $invitation,
        public bool $resent = false,
        public ?string $actorId = null,
        public ?string $acceptUrl = null,
    ) {}

    public static function handle(): string
    {
        return 'teams.invitation.sent';
    }

    public function payload(): array
    {
        return ['team' => $this->invitation->team?->summary(), 'invitation' => $this->invitation->summary(), 'resent' => $this->resent, 'actor_id' => $this->actorId];
    }
}
