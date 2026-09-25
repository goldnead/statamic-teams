<?php

namespace Goldnead\Teams\Integrations\WebhookManager;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * One team event as a webhook-manager trigger. Loaded only when the manager
 * is installed (see {@see WebhookManagerBridge}).
 */
class TeamTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $label,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sourceType(): string
    {
        return 'team';
    }

    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $payload = $source instanceof TeamEvent ? $source->payload() : (array) $source;
        $teamId = $payload['team']['uuid'] ?? $payload['team']['id'] ?? null;

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: $teamId === null ? null : (string) $teamId,
            payload: $payload + ['event' => $this->handle],
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable,
        );
    }
}
