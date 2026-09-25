<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Events\TeamCreated;
use Goldnead\Teams\Events\TeamDeleted;
use Goldnead\Teams\Events\TeamUpdated;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class TeamLifecycleTest extends TestCase
{
    #[Test]
    public function creating_a_team_makes_the_creator_its_owner_and_current_team(): void
    {
        Event::fake([TeamCreated::class, MemberJoined::class]);
        $anna = $this->makeUser('anna@example.com');

        $team = Teams::create('Kammerchor', $anna);

        $this->assertSame('owner', $team->roleOf($anna));
        $this->assertSame((string) $anna->id(), $team->owner_id);
        $this->assertTrue(Teams::current($anna)->is($team));
        $this->assertNotEmpty($team->uuid);
        Event::assertDispatched(TeamCreated::class);
        Event::assertDispatched(MemberJoined::class, fn (MemberJoined $e) => $e->via === 'created');
    }

    #[Test]
    public function switching_to_join_by_code_creates_a_code(): void
    {
        $team = Teams::create('Chor', $this->makeUser('a@example.com'));
        $this->assertNull($team->join_code);

        Teams::update($team, ['join_method' => Team::JOIN_CODE]);

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $team->fresh()->join_code);
    }

    #[Test]
    public function an_update_event_names_the_changed_fields_but_never_the_code(): void
    {
        $team = Teams::create('Chor', $this->makeUser('a@example.com'));
        Event::fake([TeamUpdated::class]);

        Teams::update($team, ['name' => 'Neuer Name', 'join_method' => Team::JOIN_CODE]);

        Event::assertDispatched(TeamUpdated::class, function (TeamUpdated $e) {
            $payload = $e->payload();

            return in_array('name', $payload['changes'], true)
                && ! in_array('join_code', $payload['changes'], true)
                && ! str_contains(json_encode($payload), (string) $e->team->join_code);
        });
    }

    #[Test]
    public function a_member_without_the_permission_cannot_update_the_team(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $member = $this->makeUser('member@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $member);

        $this->expectExceptionObject(TeamsException::because(TeamsException::FORBIDDEN));

        Teams::update($team, ['name' => 'Gekapert'], $member);
    }

    #[Test]
    public function billing_is_merged_and_empty_fields_are_dropped(): void
    {
        $team = Teams::create('Chor', $this->makeUser('a@example.com'));

        Teams::update($team, ['billing' => ['company' => 'Chor e.V.', 'city' => 'Köln']]);
        Teams::update($team, ['billing' => ['city' => '', 'vat_id' => 'DE123']]);

        $this->assertSame(['company' => 'Chor e.V.', 'vat_id' => 'DE123'], $team->fresh()->billing);
    }

    #[Test]
    public function a_user_has_at_most_one_personal_team(): void
    {
        $anna = $this->makeUser('anna@example.com', 'Anna');

        $first = Teams::personalTeam($anna);
        $second = Teams::personalTeam($anna);

        $this->assertTrue($first->is($second));
        $this->assertTrue($first->isPersonal());
        $this->assertSame(1, Team::query()->where('type', 'personal')->count());
    }

    #[Test]
    public function nobody_is_invited_into_a_personal_team(): void
    {
        $team = Teams::personalTeam($this->makeUser('anna@example.com'));

        $this->expectExceptionObject(TeamsException::because(TeamsException::PERSONAL_TEAM));

        Teams::invite($team, 'bob@example.com');
    }

    #[Test]
    public function deleting_a_team_fires_an_event_with_the_team_as_it_was(): void
    {
        $team = Teams::create('Chor', $this->makeUser('a@example.com'));
        Event::fake([TeamDeleted::class]);

        Teams::delete($team);

        $this->assertNull(Team::query()->find($team->id));
        Event::assertDispatched(TeamDeleted::class, fn (TeamDeleted $e) => $e->payload()['team']['uuid'] === $team->uuid);
    }

    #[Test]
    public function ownership_moves_and_the_old_owner_stays_as_admin(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $next = $this->makeUser('next@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $next);

        Teams::transferOwnership($team, $next, $owner);

        $this->assertSame('owner', $team->roleOf($next));
        $this->assertSame('admin', $team->roleOf($owner));
        $this->assertSame((string) $next->id(), $team->fresh()->owner_id);
    }

    #[Test]
    public function a_team_is_read_only_through_its_settings(): void
    {
        $team = Teams::create('Chor');
        $this->assertFalse($team->isReadOnly());

        Teams::update($team, ['settings' => ['read_only' => true]]);

        $this->assertTrue($team->fresh()->isReadOnly());
    }
}
