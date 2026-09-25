<?php

namespace Goldnead\Teams\Integrations\WebhookManager;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers every team event as a webhook trigger.
 *
 * Same shape as the LeadHub bridge: one trigger per event, and the event is
 * re-emitted as the manager's `TriggerDetected`. Nothing here touches the
 * manager's classes before the `class_exists` guard.
 */
class WebhookManagerBridge
{
    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('teams.integrations.webhook_manager', true)
            && class_exists(WebhookManager::class);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The binding appears only once the manager's provider booted; sibling
        // boot order is not guaranteed. Not marked booted, so a later pass can
        // still register.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (EventCatalog::all() as $event) {
            try {
                WebhookManager::registerTrigger(new TeamTrigger($event['handle'], $event['label']));
            } catch (Throwable $e) {
                Log::warning('statamic-teams: webhook trigger ['.$event['handle'].'] could not be registered: '.$e->getMessage());

                continue;
            }

            $handle = $event['handle'];

            $events->listen($event['class'], function (TeamEvent $fired) use ($handle): void {
                $this->dispatch($handle, $fired);
            });
        }
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    protected function dispatch(string $handle, TeamEvent $event): void
    {
        try {
            $trigger = WebhookManager::triggers()->get($handle);

            if (! $trigger) {
                return;
            }

            event(new TriggerDetected($trigger->build($event)));
        } catch (Throwable $e) {
            Log::warning('statamic-teams: webhook dispatch failed for ['.$handle.']: '.$e->getMessage());
        }
    }
}
