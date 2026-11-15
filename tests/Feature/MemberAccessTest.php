<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\MemberPermissionGranted;
use Goldnead\Teams\Events\MemberPermissionRevoked;
use Goldnead\Teams\Events\MemberRoleAdded;
use Goldnead\Teams\Events\MemberRoleRemoved;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\MemberPermission;
use Goldnead\Teams\Models\MemberRole;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class MemberAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('teams.roles.leader', ['label' => 'Leader', 'permissions' => ['update team']]);
        config()->set('teams.roles.treasurer', ['label' => 'Treasurer', 'permissions' => ['view billing', 'manage billing']]);
        Teams::registerPermission('edit scores', 'Edit scores');
    }

    protected function reason(callable $call): string
    {
        try {
            $call();
        } catch (TeamsException $e) {
            return $e->reason;
        }

        $this->fail('No TeamsException thrown.');
    }

    #[Test]
    public function without_extras_nothing_changes(): void
    {
        $team = Teams::create('Chor', $owner = $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);

        $this->assertFalse(Teams::can($bob, $team, 'update team'));
        $this->assertTrue(Teams::can($owner, $team, 'anything'));
        $this->assertSame(['member'], Teams::rolesOf($bob, $team));
        $this->assertSame([], Teams::permissionsOf($bob, $team));
        $this->assertSame(['*'], Teams::permissionsOf($owner, $team));
        $this->assertSame(0, MemberRole::query()->count() + MemberPermission::query()->count());
    }

    #[Test]
    public function an_additional_role_adds_its_permissions_and_keeps_the_main_role(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob, 'admin');
        Event::fake([MemberRoleAdded::class]);

        Teams::addRole($team, $bob, 'treasurer');
        Teams::addRole($team, $bob, 'treasurer');

        Event::assertDispatchedTimes(MemberRoleAdded::class, 1);
        $this->assertSame('admin', Teams::roleOf($bob, $team));
        $this->assertSame(['admin', 'treasurer'], Teams::rolesOf($bob, $team));
        $this->assertTrue(Teams::can($bob, $team, 'invite members'));
        $this->assertTrue(Teams::can($bob, $team, 'manage billing'));
        $this->assertTrue(Teams::can($bob, $team, 'update team'));
        $this->assertFalse(Teams::can($bob, $team, 'remove everything'));
    }

    #[Test]
    public function the_permission_comes_from_the_additional_role_only(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);

        $this->assertFalse(Teams::can($bob, $team, 'manage billing'));
        Teams::addRole($team, $bob, 'treasurer');
        $this->assertTrue(Teams::can($bob, $team, 'manage billing'));
        $this->assertFalse(Teams::can($bob, $team, 'invite members'));

        Event::fake([MemberRoleRemoved::class]);
        Teams::removeRole($team, $bob, 'treasurer');
        Teams::removeRole($team, $bob, 'treasurer');

        Event::assertDispatchedTimes(MemberRoleRemoved::class, 1);
        $this->assertFalse(Teams::can($bob, $team, 'manage billing'));
    }

    #[Test]
    public function an_own_permission_works_without_a_role_and_can_be_revoked(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);
        Event::fake([MemberPermissionGranted::class, MemberPermissionRevoked::class]);

        Teams::grantPermission($team, $bob, 'edit scores');
        Teams::grantPermission($team, $bob, 'edit scores');

        $this->assertTrue(Teams::can($bob, $team, 'edit scores'));
        $this->assertSame(['edit scores'], Teams::ownPermissionsOf($bob, $team));
        $this->assertSame(['edit scores'], Teams::permissionsOf($bob, $team));
        Event::assertDispatchedTimes(MemberPermissionGranted::class, 1);

        Teams::revokePermission($team, $bob, 'edit scores');

        $this->assertFalse(Teams::can($bob, $team, 'edit scores'));
        Event::assertDispatched(MemberPermissionRevoked::class, fn ($e) => $e->permission === 'edit scores');
    }

    #[Test]
    public function effective_permissions_are_the_union_without_duplicates(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob, 'admin');
        Teams::addRole($team, $bob, 'treasurer');
        Teams::grantPermission($team, $bob, 'view billing');
        Teams::grantPermission($team, $bob, 'edit scores');

        $permissions = Teams::permissionsOf($bob, $team);

        $this->assertSame(count($permissions), count(array_unique($permissions)));
        $this->assertContains('invite members', $permissions);
        $this->assertContains('manage billing', $permissions);
        $this->assertContains('edit scores', $permissions);
    }

    #[Test]
    public function an_extra_role_in_one_team_does_not_act_in_another(): void
    {
        $a = Teams::create('A', $this->makeUser('owner-a@example.com'));
        $b = Teams::create('B', $this->makeUser('owner-b@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($a, $bob);
        Teams::addMember($b, $bob);

        Teams::addRole($a, $bob, 'treasurer');
        Teams::grantPermission($a, $bob, 'edit scores');

        $this->assertTrue(Teams::can($bob, $a, 'manage billing'));
        $this->assertTrue(Teams::can($bob, $a, 'edit scores'));
        $this->assertFalse(Teams::can($bob, $b, 'manage billing'));
        $this->assertFalse(Teams::can($bob, $b, 'edit scores'));
        $this->assertSame(['member'], Teams::rolesOf($bob, $b));
    }

    #[Test]
    public function a_non_member_has_nothing_and_cannot_be_given_anything(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $stranger = $this->makeUser('stranger@example.com');

        $this->assertFalse(Teams::can($stranger, $team, 'edit scores'));
        $this->assertSame([], Teams::rolesOf($stranger, $team));
        $this->assertSame(TeamsException::NOT_MEMBER, $this->reason(fn () => Teams::addRole($team, $stranger, 'treasurer')));
    }

    #[Test]
    public function unknown_and_protected_values_are_refused(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);

        $this->assertSame(TeamsException::UNKNOWN_ROLE, $this->reason(fn () => Teams::addRole($team, $bob, 'nope')));
        $this->assertSame(TeamsException::ROLE_PROTECTED, $this->reason(fn () => Teams::addRole($team, $bob, 'owner')));
        $this->assertSame(TeamsException::UNKNOWN_PERMISSION, $this->reason(fn () => Teams::grantPermission($team, $bob, 'nope')));
        $this->assertSame(TeamsException::WILDCARD, $this->reason(fn () => Teams::grantPermission($team, $bob, '*')));
    }

    #[Test]
    public function the_main_role_is_not_stored_twice(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob, 'admin');

        Teams::addRole($team, $bob, 'admin');

        $this->assertSame(0, MemberRole::query()->count());
    }

    #[Test]
    public function an_actor_needs_change_roles_and_may_not_hand_out_more_than_held(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $admin = $this->makeUser('admin@example.com');
        $bob = $this->makeUser('bob@example.com');
        $plain = $this->makeUser('plain@example.com');
        Teams::addMember($team, $admin, 'admin');
        Teams::addMember($team, $bob);
        Teams::addMember($team, $plain);

        // Without `change roles`.
        $this->assertSame(TeamsException::FORBIDDEN, $this->reason(fn () => Teams::addRole($team, $bob, 'member', $plain)));

        // `leader` needs only `update team`, which the admin holds.
        Teams::addRole($team, $bob, 'leader', $admin);
        $this->assertTrue(Teams::can($bob, $team, 'update team'));

        // The admin does not hold `edit scores`, so cannot grant it.
        $this->assertSame(TeamsException::FORBIDDEN, $this->reason(fn () => Teams::grantPermission($team, $bob, 'edit scores', $admin)));

        // Nobody changes their own access unless an owner.
        $this->assertSame(TeamsException::FORBIDDEN, $this->reason(fn () => Teams::addRole($team, $admin, 'leader', $admin)));
    }

    #[Test]
    public function an_additional_role_lets_the_actor_hand_out_what_it_grants(): void
    {
        config()->set('teams.roles.rolemanager', ['label' => 'Manager', 'permissions' => ['change roles', 'view billing', 'manage billing']]);
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $helper = $this->makeUser('helper@example.com');
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $helper);
        Teams::addMember($team, $bob);

        $this->assertSame(TeamsException::FORBIDDEN, $this->reason(fn () => Teams::addRole($team, $bob, 'treasurer', $helper)));

        Teams::addRole($team, $helper, 'rolemanager');
        Teams::addRole($team, $bob, 'treasurer', $helper);

        $this->assertTrue(Teams::can($bob, $team, 'manage billing'));
    }

    #[Test]
    public function an_owners_access_is_changed_by_an_owner_only(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);
        $admin = $this->makeUser('admin@example.com');
        Teams::addMember($team, $admin, 'admin');

        $this->assertSame(TeamsException::FORBIDDEN, $this->reason(fn () => Teams::addRole($team, $owner, 'leader', $admin)));
    }

    #[Test]
    public function leaving_removes_the_extras(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);
        Teams::addRole($team, $bob, 'treasurer');
        Teams::grantPermission($team, $bob, 'edit scores');

        Teams::leave($team, $bob);

        $this->assertSame(0, MemberRole::query()->count());
        $this->assertSame(0, MemberPermission::query()->count());

        Teams::addMember($team, $bob);
        $this->assertSame(['member'], Teams::rolesOf($bob, $team));
    }

    #[Test]
    public function deleting_the_team_removes_the_extras(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);
        Teams::addRole($team, $bob, 'treasurer');
        Teams::grantPermission($team, $bob, 'edit scores');

        Teams::delete($team);

        $this->assertSame(0, MemberRole::query()->count());
        $this->assertSame(0, MemberPermission::query()->count());
    }

    #[Test]
    public function a_role_held_as_an_additional_role_counts_as_in_use_and_moves_on_deletion(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $ann = $this->makeUser('ann@example.com');
        Teams::addMember($team, $bob);
        Teams::addMember($team, $ann, 'admin');
        Teams::createRole('scribe', 'Scribe', ['view billing'], $team);
        Teams::addRole($team, $bob, 'scribe');
        Teams::addRole($team, $ann, 'scribe');

        $this->assertSame(['members' => 2, 'invitations' => 0], Teams::roleUsage('scribe', $team));
        $this->assertSame(TeamsException::ROLE_IN_USE, $this->reason(fn () => Teams::deleteRole('scribe', $team)));

        // Ann already holds `admin` as main role: her row just goes.
        Teams::deleteRole('scribe', $team, 'admin');

        $this->assertSame(['member', 'admin'], Teams::rolesOf($bob, $team));
        $this->assertSame(['admin'], Teams::rolesOf($ann, $team));
    }
}
