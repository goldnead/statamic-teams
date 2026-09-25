<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Integrations\Automations\AutomationsBridge;
use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Teams\Integrations\WebhookManager\WebhookManagerBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the CP page "Wiring" shows: every event, the mail it sends, and who
 * listens to it in automations and the webhook manager.
 *
 * The listener counts are read straight from the siblings' tables
 * (`automation_nodes.type`, `webhook_outbounds.trigger_type`) instead of
 * through their classes: this page must render with either sibling missing,
 * and a count is not worth a coupling.
 */
class Wiring
{
    public function __construct(
        protected MailTemplates $mails,
        protected AutomationsBridge $automations,
    ) {}

    /**
     * @return array{events: list<array<string, mixed>>, integrations: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $flows = $this->automationCounts();
        $hooks = $this->webhookCounts();

        $events = array_map(function (array $event) use ($flows, $hooks) {
            $mail = $event['mail'];

            return [
                'handle' => $event['handle'],
                'label' => $event['label'],
                'description' => $event['description'],
                'mail' => $mail === null ? null : [
                    'key' => $mail,
                    'slug' => $this->mails->slug($mail),
                    'enabled' => $this->mails->enabled($mail),
                    'customised' => $this->mails->hasEntry($mail),
                    'edit_url' => $this->mails->editUrl($mail),
                    'variables' => MailTemplates::MAILS[$mail] ?? [],
                ],
                'automations' => $flows[$event['handle']] ?? 0,
                'webhooks' => $hooks[$event['handle']] ?? 0,
            ];
        }, EventCatalog::all());

        return [
            'events' => $events,
            'integrations' => [
                'email_templates' => [
                    'installed' => $this->mails->available(),
                    'url' => $this->cpRoute('collections.show', 'et_templates'),
                ],
                'automations' => [
                    'installed' => $this->automations->available(),
                    'url' => $this->cpRoute('statamic-automations.automations.index'),
                ],
                'webhook_manager' => [
                    'installed' => WebhookManagerBridge::available(),
                    'url' => $this->cpRoute('webhook-manager.outbound.index'),
                ],
                'activity' => [
                    'installed' => class_exists('\Goldnead\Activity\Facades\Activity'),
                    'url' => null,
                ],
            ],
        ];
    }

    /** @return array<string, int> */
    protected function automationCounts(): array
    {
        if (! $this->automations->available()) {
            return [];
        }

        return $this->safely(fn () => DB::table('automation_nodes')
            ->join('automations', 'automations.id', '=', 'automation_nodes.automation_id')
            ->where('automations.enabled', true)
            ->where('automation_nodes.disabled', false)
            ->whereIn('automation_nodes.type', EventCatalog::handles())
            ->groupBy('automation_nodes.type')
            ->selectRaw('automation_nodes.type as handle, count(distinct automations.id) as total')
            ->pluck('total', 'handle')
            ->map(fn ($total) => (int) $total)
            ->all(), ['automation_nodes', 'automations']);
    }

    /** @return array<string, int> */
    protected function webhookCounts(): array
    {
        if (! WebhookManagerBridge::available()) {
            return [];
        }

        return $this->safely(fn () => DB::table('webhook_outbounds')
            ->where('enabled', true)
            ->whereIn('trigger_type', EventCatalog::handles())
            ->groupBy('trigger_type')
            ->selectRaw('trigger_type as handle, count(*) as total')
            ->pluck('total', 'handle')
            ->map(fn ($total) => (int) $total)
            ->all(), ['webhook_outbounds']);
    }

    /**
     * @param  callable(): array<string, int>  $query
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    protected function safely(callable $query, array $tables): array
    {
        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    return [];
                }
            }

            return $query();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    protected function cpRoute(string $name, mixed ...$parameters): ?string
    {
        $full = 'statamic.cp.'.$name;

        return Route::has($full) ? route($full, $parameters) : null;
    }
}
