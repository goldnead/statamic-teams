<?php

namespace Goldnead\Teams\Events;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\RoleService;

/**
 * A role was created, changed or deleted: a global one (`team` null) or one
 * of a single team (`role.scope` says which).
 */
abstract class RoleEvent extends TeamEvent
{
    /**
     * @param  array<string, mixed>  $role  as {@see RoleService::find()} returns it
     */
    public function __construct(
        public array $role,
        public ?Team $team = null,
        public ?string $actorId = null,
    ) {}

    /** @return array{handle: string, label: string, permissions: list<string>, scope: string} */
    protected function roleSummary(): array
    {
        return [
            'handle' => (string) $this->role['handle'],
            'label' => (string) $this->role['label'],
            'permissions' => array_values((array) ($this->role['permissions'] ?? [])),
            'scope' => $this->team === null ? 'global' : 'team',
        ];
    }

    /** @return array<string, mixed> */
    protected function base(): array
    {
        return ['role' => $this->roleSummary(), 'team' => $this->team?->summary()];
    }
}
