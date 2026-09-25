<?php

namespace Goldnead\Teams\Integrations\Automations;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Support\EventCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers every team event as an automation trigger.
 *
 * Through `Automations::registerEventTrigger()`, the sibling's generic path:
 * it subscribes one listener per event and hands it to its own dispatcher,
 * so matching and enrolment stay its rules. The context is the event's
 * `payload()`, the same fields a webhook gets.
 */
class AutomationsBridge
{
    public const FACADE = 'Goldnead\StatamicAutomations\Facades\Automations';

    protected bool $registered = false;

    public function available(): bool
    {
        return (bool) config('teams.integrations.automations', true) && class_exists(self::FACADE);
    }

    /**
     * Idempotent: Statamic fires booted callbacks more than once, and a
     * trigger registered twice would also listen twice.
     */
    public function register(): void
    {
        if ($this->registered || ! $this->available()) {
            return;
        }

        try {
            $facade = self::FACADE;
            $root = $facade::getFacadeRoot();

            if (! is_object($root) || ! method_exists($root, 'registerEventTrigger')) {
                return;
            }

            foreach (EventCatalog::all() as $event) {
                $root->registerEventTrigger($event['class'], [
                    'handle' => $event['handle'],
                    'label' => $event['label'],
                    'description' => $event['description'],
                    'group' => 'Teams',
                    'payload' => fn (TeamEvent $fired) => $fired->payload(),
                    'output_schema' => self::outputSchema($event['handle']),
                    // A flow can listen to one team type only: "somebody
                    // joined a choir" without the personal teams.
                    'schema' => [self::teamTypeField()],
                    'matches' => fn (object|array $fired, array $config) => self::matchesTeamType($fired, $config),
                ]);
            }

            $this->registered = true;
        } catch (Throwable $e) {
            Log::warning('statamic-teams: the automation triggers could not be registered.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function registered(): bool
    {
        return $this->registered;
    }

    /**
     * The trigger's one setting: which team type it fires for. Empty: all.
     *
     * @return array<string, mixed>
     */
    public static function teamTypeField(): array
    {
        $options = [];

        foreach ((array) config('teams.types', []) as $handle => $label) {
            $options[(string) $handle] = is_string($label) ? $label : (string) $handle;
        }

        return [
            'handle' => 'team_type',
            'label' => __('teams::cp.automations_team_type'),
            'type' => 'select',
            'options' => $options,
            'required' => false,
            'help' => __('teams::cp.automations_team_type_help'),
        ];
    }

    /** @param  array<string, mixed>  $config */
    public static function matchesTeamType(object|array $fired, array $config): bool
    {
        $wanted = $config['team_type'] ?? null;

        if (! is_string($wanted) || $wanted === '') {
            return true;
        }

        $payload = $fired instanceof TeamEvent ? $fired->payload() : (array) $fired;

        return ($payload['team_type'] ?? null) === $wanted;
    }

    /**
     * What a flow can read from the context, for the picker in the builder.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(string $handle): array
    {
        $team = ['id' => 'integer', 'uuid' => 'string', 'name' => 'string', 'type' => 'string', 'owner_id' => 'string'];
        $user = ['id' => 'string', 'email' => 'string', 'name' => 'string'];
        $role = ['handle' => 'string', 'label' => 'string', 'permissions' => 'array', 'scope' => 'string'];
        $invitation = ['id' => 'integer', 'uuid' => 'string', 'team_id' => 'integer', 'email' => 'string', 'role' => 'string', 'status' => 'string', 'expires_at' => 'datetime'];

        return match ($handle) {
            'teams.team.created' => ['team' => $team, 'actor_id' => 'string'],
            'teams.team.updated' => ['team' => $team, 'changes' => 'array', 'actor_id' => 'string'],
            'teams.team.deleted' => ['team' => $team, 'actor_id' => 'string'],
            'teams.team.ownership_transferred' => ['team' => $team, 'from' => $user, 'to' => $user, 'actor_id' => 'string'],
            'teams.member.joined' => ['team' => $team, 'user' => $user, 'role' => 'string', 'via' => 'string', 'actor_id' => 'string'],
            'teams.member.left' => ['team' => $team, 'user' => $user, 'role' => 'string', 'reason' => 'string', 'actor_id' => 'string'],
            'teams.member.role_changed' => ['team' => $team, 'user' => $user, 'from' => 'string', 'to' => 'string', 'actor_id' => 'string'],
            'teams.invitation.sent' => ['team' => $team, 'invitation' => $invitation, 'resent' => 'boolean', 'actor_id' => 'string'],
            'teams.invitation.accepted' => ['team' => $team, 'invitation' => $invitation, 'user' => $user],
            'teams.invitation.revoked' => ['team' => $team, 'invitation' => $invitation, 'actor_id' => 'string'],
            // `team` is empty for a global role (`role.scope = global`).
            'teams.role.created' => ['role' => $role, 'team' => $team, 'actor_id' => 'string'],
            'teams.role.updated' => ['role' => $role, 'team' => $team, 'changes' => 'array', 'actor_id' => 'string'],
            'teams.role.deleted' => ['role' => $role, 'team' => $team, 'reassigned_to' => 'string', 'reassigned' => 'integer', 'actor_id' => 'string'],
            default => [],
        };
    }
}
