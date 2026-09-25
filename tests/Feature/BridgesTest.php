<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Activity\Facades\Activity;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\Automations\AutomationsBridge;
use Goldnead\Teams\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\Teams\Tests\TestCase;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__.'/../Fakes/automations.php';
require_once __DIR__.'/../Fakes/webhook-manager.php';
require_once __DIR__.'/../Fakes/activity.php';

class BridgesTest extends TestCase
{
    protected function setUp(): void
    {
        Automations::$root = null;
        WebhookManager::$triggers = [];
        Activity::$recorded = [];

        parent::setUp();
    }

    /**
     * The manager's container binding is what the bridge waits for.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->instance('webhook-manager', new \stdClass);
    }

    #[Test]
    public function every_event_is_an_automation_trigger_and_fires_with_its_payload(): void
    {
        $recorder = Automations::getFacadeRoot();

        $this->assertSame(EventCatalog::handles(), array_keys($recorder->triggers));
        $this->assertSame('Teams', $recorder->triggers['teams.member.joined']['group']);
        $this->assertArrayHasKey('user', $recorder->triggers['teams.member.joined']['output_schema']);

        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        Teams::addMember($team, $this->makeUser('bob@example.com'));

        $joined = collect($recorder->dispatched)->where('handle', 'teams.member.joined')->values();
        $this->assertCount(2, $joined);
        $this->assertSame('bob@example.com', $joined[1]['context']['user']['email']);
        $this->assertSame($team->uuid, $joined[1]['context']['team']['uuid']);
    }

    #[Test]
    public function registering_twice_registers_once(): void
    {
        $bridge = app(AutomationsBridge::class);
        $bridge->register();
        $bridge->register();

        Teams::create('Chor');

        $this->assertCount(1, collect(Automations::getFacadeRoot()->dispatched)->where('handle', 'teams.team.created'));
    }

    #[Test]
    public function every_event_is_a_webhook_trigger_and_is_handed_to_the_manager(): void
    {
        $this->assertSame(EventCatalog::handles(), array_keys(WebhookManager::$triggers));
        $this->assertTrue(app(WebhookManagerBridge::class)->booted());

        $detected = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $e) use (&$detected) {
            $detected[] = $e->event;
        });

        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $issued = Teams::invite($team, 'bob@example.com');

        $sent = collect($detected)->firstWhere('triggerHandle', 'teams.invitation.sent');
        $this->assertNotNull($sent);
        $this->assertSame('team', $sent->sourceType);
        $this->assertSame($team->uuid, $sent->sourceReference);
        $this->assertSame('bob@example.com', $sent->payload['invitation']['email']);
        $this->assertStringNotContainsString($issued->token, json_encode($sent->payload));
    }

    #[Test]
    public function every_event_lands_in_the_activity_log_with_the_team_as_subject(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));

        $types = array_column(Activity::$recorded, 'type');
        $this->assertContains('teams.team.created', $types);
        $this->assertContains('teams.member.joined', $types);

        $created = collect(Activity::$recorded)->firstWhere('type', 'teams.team.created');
        $this->assertSame('team', $created['attributes']['subject_type']);
        $this->assertSame((string) $team->id, $created['attributes']['subject_id']);
    }

    #[Test]
    public function the_activity_bridge_can_be_switched_off(): void
    {
        config(['teams.integrations.activity' => false]);

        Teams::create('Chor');

        $this->assertSame([], Activity::$recorded);
    }
}
