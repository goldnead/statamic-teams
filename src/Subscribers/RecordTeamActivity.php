<?php

namespace Goldnead\Teams\Subscribers;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\EventCatalog;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes every team event to goldnead/statamic-activity, when installed.
 *
 * Subject is the team (`team:<id>`), the event handle is the activity type,
 * the payload goes into `properties`.
 */
class RecordTeamActivity
{
    public const FACADE = 'Goldnead\Activity\Facades\Activity';

    public function subscribe(Dispatcher $events): void
    {
        foreach (array_keys(EventCatalog::EVENTS) as $class) {
            $events->listen($class, [self::class, 'record']);
        }
    }

    public function available(): bool
    {
        return (bool) config('teams.integrations.activity', true) && class_exists(self::FACADE);
    }

    public function record(TeamEvent $event): void
    {
        if (! $this->available()) {
            return;
        }

        $payload = $event->payload();
        $teamId = $payload['team']['id'] ?? null;

        try {
            $facade = self::FACADE;
            // A global role belongs to no team: no subject then.
            $facade::record($event::handle(), array_filter([
                'subject_type' => $teamId === null ? null : Team::MORPH_ALIAS,
                'subject_id' => $teamId === null ? null : (string) $teamId,
                'source' => 'statamic-teams',
                'properties' => $payload,
            ], fn ($value) => $value !== null));
        } catch (Throwable $e) {
            Log::warning('statamic-teams: writing the activity failed.', [
                'event' => $event::handle(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
