<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\TeamCreated;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__.'/../Fakes/automations.php';

/**
 * `member.joined` fired on every registration (Gesamtprüfung 25.09.2026).
 *
 * ChoirLive gives every new account a personal team. Creating it made the
 * owner its first member, and that fired `teams.member.joined`: a flow "a
 * new singer joined the choir" ran for every sign-up. A personal team is not
 * joined, it is created; it announces `team.created` and nothing else. A
 * regular team still announces its founder as the first member.
 */
class PersonalTeamEventsTest extends TestCase
{
    protected function setUp(): void
    {
        Automations::$root = null;

        parent::setUp();
    }

    #[Test]
    public function a_personal_team_announces_its_creation_but_no_member_joining(): void
    {
        $owner = $this->makeUser('sina@example.com');
        Event::fake([MemberJoined::class, TeamCreated::class]);

        $team = Teams::personalTeam($owner);

        $this->assertSame(Team::TYPE_PERSONAL, $team->type);
        $this->assertTrue($team->hasMember((string) $owner->id()), 'the owner is still a member');
        Event::assertDispatched(TeamCreated::class, fn (TeamCreated $e) => $e->payload()['team']['type'] === Team::TYPE_PERSONAL);
        Event::assertNotDispatched(MemberJoined::class);
    }

    #[Test]
    public function a_regular_team_still_announces_its_founder_joining_with_the_team_type(): void
    {
        $owner = $this->makeUser('owner@example.com');
        Event::fake([MemberJoined::class]);

        Teams::create('Kammerchor', $owner);

        Event::assertDispatched(MemberJoined::class, fn (MemberJoined $e) => $e->via === 'created'
            && $e->payload()['team']['type'] === 'team'
            && $e->payload()['team_type'] === 'team');
    }

    #[Test]
    public function an_automation_can_listen_to_one_team_type_only(): void
    {
        $definition = Automations::getFacadeRoot()->triggers['teams.member.joined'];

        $field = collect($definition['schema'] ?? [])->firstWhere('handle', 'team_type');
        $this->assertNotNull($field, 'the trigger offers a team type filter');
        $this->assertContains('team', array_keys($field['options']));

        $matches = $definition['matches'];
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        $joined = new MemberJoined($team, $team->members()->first(), 'created');

        $this->assertTrue($matches($joined, []), 'no filter: every type');
        $this->assertTrue($matches($joined, ['team_type' => 'team']));
        $this->assertFalse($matches($joined, ['team_type' => 'personal']));
    }
}
