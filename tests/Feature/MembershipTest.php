<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\MemberLeft;
use Goldnead\Teams\Events\MemberRoleChanged;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\TeamRole;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class MembershipTest extends TestCase
{
    #[Test]
    public function the_last_owner_can_neither_leave_nor_be_demoted(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);

        try {
            Teams::leave($team, $owner);
            $this->fail('The last owner left.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::LAST_OWNER, $e->reason);
        }

        $this->expectExceptionObject(TeamsException::because(TeamsException::LAST_OWNER));
        Teams::changeRole($team, $owner, 'member');
    }

    #[Test]
    public function a_member_may_leave_and_the_event_says_so(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);
        Event::fake([MemberLeft::class]);

        Teams::leave($team, $bob);

        $this->assertFalse($team->hasMember($bob));
        Event::assertDispatched(MemberLeft::class, fn (MemberLeft $e) => $e->reason === 'left');
    }

    #[Test]
    public function removing_somebody_else_needs_the_permission(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $eve = $this->makeUser('eve@example.com');
        Teams::addMember($team, $bob);
        Teams::addMember($team, $eve);

        try {
            Teams::removeMember($team, $bob, $eve);
            $this->fail('A plain member removed somebody.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::FORBIDDEN, $e->reason);
        }

        Teams::changeRole($team, $eve, 'admin');
        Event::fake([MemberLeft::class]);
        Teams::removeMember($team, $bob, $eve);

        $this->assertFalse($team->hasMember($bob));
        Event::assertDispatched(MemberLeft::class, fn (MemberLeft $e) => $e->reason === 'removed');
    }

    #[Test]
    public function an_admin_cannot_make_anybody_owner(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $admin = $this->makeUser('admin@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $admin, 'admin');

        $this->expectExceptionObject(TeamsException::because(TeamsException::FORBIDDEN));

        Teams::changeRole($team, $admin, 'owner', $admin);
    }

    #[Test]
    public function a_role_change_fires_an_event_with_from_and_to(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);
        Event::fake([MemberRoleChanged::class]);

        Teams::changeRole($team, $bob, 'admin');

        Event::assertDispatched(MemberRoleChanged::class, fn (MemberRoleChanged $e) => $e->payload()['from'] === 'member' && $e->payload()['to'] === 'admin');
    }

    #[Test]
    public function an_unknown_role_is_refused(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));

        $this->expectExceptionObject(TeamsException::because(TeamsException::UNKNOWN_ROLE));

        Teams::addMember($team, $this->makeUser('bob@example.com'), 'kaiser');
    }

    #[Test]
    public function roles_and_permissions_count_per_team(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $bob = $this->makeUser('bob@example.com');
        $choir = Teams::create('Chor', $owner);
        $other = Teams::create('Anderer Chor', $owner);

        TeamRole::query()->create(['team_id' => $choir->id, 'handle' => 'section_leader', 'label' => 'Stimmführung', 'permissions' => ['invite members']]);

        Teams::addMember($choir, $bob, 'section_leader');
        Teams::addMember($other, $bob);

        $this->assertTrue(Teams::can($bob, $choir, 'invite members'));
        $this->assertFalse(Teams::can($bob, $other, 'invite members'));
        $this->assertFalse(Teams::can($bob, $choir, 'remove members'));
        $this->assertArrayHasKey('section_leader', Teams::roles($choir));
        $this->assertArrayNotHasKey('section_leader', Teams::roles($other));

        $this->expectExceptionObject(TeamsException::because(TeamsException::UNKNOWN_ROLE));
        Teams::changeRole($other, $bob, 'section_leader');
    }

    #[Test]
    public function the_owner_holds_every_permission_even_ones_the_site_added(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);

        $this->assertTrue(Teams::can($owner, $team, 'publish rehearsal plan'));
    }

    #[Test]
    public function switching_marks_one_current_team_and_leaving_it_picks_another(): void
    {
        $bob = $this->makeUser('bob@example.com');
        $a = Teams::create('A', $this->makeUser('a@example.com'));
        $b = Teams::create('B', $this->makeUser('b@example.com'));
        Teams::addMember($a, $bob);
        Teams::addMember($b, $bob);

        $this->assertTrue(Teams::current($bob)->is($a));

        Teams::switch($bob, $b);
        $this->assertTrue(Teams::current($bob)->is($b));

        Teams::setCurrent(null);
        Teams::leave($b, $bob);
        $this->assertTrue(Teams::current($bob)->is($a));
    }

    #[Test]
    public function switching_into_a_foreign_team_is_refused(): void
    {
        $team = Teams::create('A', $this->makeUser('a@example.com'));

        $this->expectExceptionObject(TeamsException::because(TeamsException::NOT_MEMBER));

        Teams::switch($this->makeUser('bob@example.com'), $team);
    }

    #[Test]
    public function membership_meta_is_merged_and_null_removes_a_key(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob, null, ['voice_part' => 'tenor', 'seat' => 3]);

        Teams::updateMemberMeta($team, $bob, ['voice_part' => 'bass', 'seat' => null], $bob);

        $this->assertSame(['voice_part' => 'bass'], $team->membershipOf($bob)->meta);
    }

    #[Test]
    public function a_join_guard_can_refuse_entry(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        Teams::guardJoining(fn ($t, $user, $via) => $t->members()->count() >= 1 ? 'team_full' : null);

        try {
            Teams::addMember($team, $this->makeUser('bob@example.com'));
            $this->fail('The guard did not refuse.');
        } catch (TeamsException $e) {
            $this->assertSame('team_full', $e->reason);
            $this->assertSame(__('teams::messages.errors.team_full'), $e->getMessage());
        }
    }
}
