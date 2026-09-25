<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\RoleCreated;
use Goldnead\Teams\Events\RoleDeleted;
use Goldnead\Teams\Events\RoleUpdated;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamRole;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Roles are managed, not only configured: global roles (config as the
 * starting point, stored changes win) and roles of one team, through the
 * facade the CP and app-api share.
 */
class RoleManagementTest extends TestCase
{
    protected Team $team;

    protected mixed $owner;

    protected mixed $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('owner@example.com');
        $this->admin = $this->makeUser('admin@example.com');
        $this->team = Teams::create('Chor', $this->owner);
        Teams::addMember($this->team, $this->admin, 'admin');
    }

    protected function assertRefused(string $reason, callable $operation): TeamsException
    {
        try {
            $operation();
        } catch (TeamsException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }

        $this->fail("Expected refusal [{$reason}].");
    }

    // Global roles ---------------------------------------------------------

    #[Test]
    public function a_global_role_is_created_changed_and_used_by_every_team(): void
    {
        Teams::createRole('stimmfuehrung', 'Stimmführung', ['invite members']);

        $this->assertSame('Stimmführung', Teams::roles($this->team)['stimmfuehrung']['label']);
        $this->assertSame('global', Teams::roles()['stimmfuehrung']['scope']);
        $this->assertSame('cp', Teams::roles()['stimmfuehrung']['source']);

        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob, 'stimmfuehrung');
        $this->assertTrue(Teams::can($bob, $this->team, 'invite members'));

        Teams::updateRole('stimmfuehrung', ['label' => 'Stimmführer:in', 'permissions' => ['invite members', 'remove members']]);

        $this->assertSame('Stimmführer:in', Teams::roles()['stimmfuehrung']['label']);
        $this->assertTrue(Teams::can($bob, $this->team, 'remove members'));
    }

    #[Test]
    public function a_changed_config_role_wins_over_the_config_and_resets_to_it(): void
    {
        Teams::updateRole('admin', ['label' => 'Vorstand', 'permissions' => ['invite members']]);

        $this->assertSame('Vorstand', Teams::roles()['admin']['label']);
        $this->assertSame('customised', Teams::roles()['admin']['source']);
        $this->assertFalse(Teams::can($this->admin, $this->team, 'change roles'));

        Teams::resetRole('admin');

        $this->assertSame('Admin', Teams::roles()['admin']['label']);
        $this->assertSame('config', Teams::roles()['admin']['source']);
        $this->assertTrue(Teams::can($this->admin, $this->team, 'change roles'));
    }

    #[Test]
    public function a_deleted_config_role_stays_deleted_until_it_is_reset(): void
    {
        Teams::changeRole($this->team, $this->admin, 'member');

        Teams::deleteRole('admin');
        $this->assertArrayNotHasKey('admin', Teams::roles());

        Teams::resetRole('admin');
        $this->assertArrayHasKey('admin', Teams::roles());
    }

    #[Test]
    public function the_owner_role_and_the_default_role_cannot_be_deleted(): void
    {
        $this->assertRefused(TeamsException::ROLE_PROTECTED, fn () => Teams::deleteRole('owner'));
        $this->assertRefused(TeamsException::ROLE_PROTECTED, fn () => Teams::deleteRole('member'));

        $this->assertArrayHasKey('owner', Teams::roles());
        $this->assertArrayHasKey('member', Teams::roles());
    }

    #[Test]
    public function a_role_somebody_holds_is_deleted_only_after_moving_them(): void
    {
        $e = $this->assertRefused(TeamsException::ROLE_IN_USE, fn () => Teams::deleteRole('admin'));
        $this->assertSame(1, $e->details['members']);
        $this->assertSame('admin', $this->team->roleOf($this->admin));

        $moved = Teams::deleteRole('admin', null, 'member');

        $this->assertSame(1, $moved);
        $this->assertSame('member', $this->team->roleOf($this->admin));
        $this->assertArrayNotHasKey('admin', Teams::roles());
    }

    #[Test]
    public function nobody_is_moved_into_the_owner_role_or_the_role_being_deleted(): void
    {
        $this->assertRefused(TeamsException::ROLE_PROTECTED, fn () => Teams::deleteRole('admin', null, 'owner'));
        $this->assertRefused(TeamsException::UNKNOWN_ROLE, fn () => Teams::deleteRole('admin', null, 'admin'));
        $this->assertRefused(TeamsException::UNKNOWN_ROLE, fn () => Teams::deleteRole('admin', null, 'gibtsnicht'));
        $this->assertSame('admin', $this->team->roleOf($this->admin));
    }

    #[Test]
    public function the_wildcard_belongs_to_the_owner_role_only(): void
    {
        $this->assertRefused(TeamsException::WILDCARD, fn () => Teams::createRole('allmacht', 'Allmacht', ['*']));
        $this->assertRefused(TeamsException::WILDCARD, fn () => Teams::updateRole('admin', ['permissions' => ['*']]));
        $this->assertRefused(TeamsException::WILDCARD, fn () => Teams::createRole('allmacht', 'Allmacht', ['*'], $this->team));

        $this->assertArrayNotHasKey('allmacht', Teams::roles($this->team));
    }

    #[Test]
    public function the_owner_role_keeps_every_permission_whatever_is_written(): void
    {
        $this->assertRefused(TeamsException::ROLE_PROTECTED, fn () => Teams::updateRole('owner', ['permissions' => ['invite members']]));

        Teams::updateRole('owner', ['label' => 'Chorleitung']);

        $this->assertSame('Chorleitung', Teams::roles()['owner']['label']);
        $this->assertSame(['*'], Teams::roles()['owner']['permissions']);
        $this->assertTrue(Teams::can($this->owner, $this->team, 'delete team'));
    }

    #[Test]
    public function only_known_permissions_are_handed_out_and_hosts_can_add_their_own(): void
    {
        $this->assertRefused(TeamsException::UNKNOWN_PERMISSION, fn () => Teams::createRole('noten', 'Noten', ['edit scores']));

        Teams::registerPermission('edit scores', 'Edit scores');
        Teams::createRole('noten', 'Noten', ['edit scores']);

        $this->assertArrayHasKey('edit scores', Teams::permissions());
        $this->assertSame('Edit scores', Teams::permissions()['edit scores']);
        $this->assertArrayHasKey('manage team roles', Teams::permissions());
    }

    #[Test]
    public function handles_are_checked_and_not_taken_twice(): void
    {
        $this->assertRefused(TeamsException::INVALID_ROLE_HANDLE, fn () => Teams::createRole('Stimm Führung', 'X'));
        $this->assertRefused(TeamsException::ROLE_EXISTS, fn () => Teams::createRole('admin', 'Admin 2'));
    }

    #[Test]
    public function global_roles_are_not_changed_by_a_team_member(): void
    {
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::createRole('x', 'X', [], null, $this->owner));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::updateRole('member', ['label' => 'X'], null, $this->owner));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::deleteRole('admin', null, 'member', $this->owner));
    }

    // Roles of one team ----------------------------------------------------

    #[Test]
    public function a_team_role_belongs_to_one_team_and_is_marked_as_such(): void
    {
        $other = Teams::create('Anderer Chor');

        Teams::createRole('kasse', 'Kasse', ['view billing'], $this->team);

        $this->assertSame('team', Teams::roles($this->team)['kasse']['scope']);
        $this->assertSame('global', Teams::roles($this->team)['admin']['scope']);
        $this->assertArrayNotHasKey('kasse', Teams::roles($other));
        $this->assertArrayNotHasKey('kasse', Teams::roles());

        Teams::updateRole('kasse', ['permissions' => ['view billing', 'manage billing']], $this->team);
        $this->assertSame(['view billing', 'manage billing'], Teams::roles($this->team)['kasse']['permissions']);
    }

    #[Test]
    public function a_team_can_adjust_a_global_role_for_itself_and_fall_back_to_it(): void
    {
        Teams::createRole('admin', 'Vorstand', ['invite members'], $this->team);

        $this->assertSame('Vorstand', Teams::roles($this->team)['admin']['label']);
        $this->assertTrue(Teams::roles($this->team)['admin']['overrides_global']);
        $this->assertFalse(Teams::can($this->admin, $this->team, 'change roles'));

        // The member keeps the role: after deleting the team's version the
        // global one applies again.
        Teams::deleteRole('admin', $this->team);

        $this->assertSame('admin', $this->team->roleOf($this->admin));
        $this->assertTrue(Teams::can($this->admin, $this->team, 'change roles'));
    }

    #[Test]
    public function a_team_role_in_use_is_deleted_only_after_moving_its_members(): void
    {
        Teams::createRole('kasse', 'Kasse', ['view billing'], $this->team);
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob, 'kasse');
        Teams::invite($this->team, 'neu@example.com', 'kasse');

        $e = $this->assertRefused(TeamsException::ROLE_IN_USE, fn () => Teams::deleteRole('kasse', $this->team));
        $this->assertSame(1, $e->details['members']);
        $this->assertSame(1, $e->details['invitations']);

        Teams::deleteRole('kasse', $this->team, 'member');

        $this->assertSame('member', $this->team->roleOf($bob));
        $this->assertSame('member', $this->team->invitations()->first()->role);
        $this->assertSame(0, TeamRole::query()->count());
    }

    #[Test]
    public function no_team_role_takes_the_owner_handle(): void
    {
        $this->assertRefused(TeamsException::ROLE_PROTECTED, fn () => Teams::createRole('owner', 'Owner', [], $this->team));
    }

    // Escalation through the role editor -----------------------------------

    #[Test]
    public function an_admin_with_change_roles_but_without_manage_team_roles_cannot_touch_a_role(): void
    {
        $this->assertTrue(Teams::can($this->admin, $this->team, 'change roles'));
        $this->assertFalse(Teams::can($this->admin, $this->team, 'manage team roles'));

        Teams::createRole('kasse', 'Kasse', ['view billing'], $this->team);

        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::createRole('x', 'X', [], $this->team, $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::updateRole('kasse', ['label' => 'X'], $this->team, $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::deleteRole('kasse', $this->team, null, $this->admin));

        $this->assertSame('Kasse', Teams::roles($this->team)['kasse']['label']);
    }

    #[Test]
    public function a_role_editor_cannot_grant_more_than_they_hold(): void
    {
        Teams::createRole('rollenwart', 'Rollenwart', ['manage team roles', 'invite members'], $this->team);
        $warden = $this->makeUser('warden@example.com');
        Teams::addMember($this->team, $warden, 'rollenwart');

        // A new role with a permission the editor lacks.
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::createRole('kasse', 'Kasse', ['manage billing'], $this->team, $warden));
        // Their own role, widened.
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::updateRole('rollenwart', ['permissions' => ['manage team roles', 'invite members', 'delete team']], $this->team, $warden));
        // Their own role, even with what they hold: an owner's business.
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::updateRole('rollenwart', ['label' => 'Chef'], $this->team, $warden));
        // A role holding more than theirs, adjusted for the team.
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::createRole('admin', 'Admin', ['invite members'], $this->team, $warden));
        // The wildcard.
        $this->assertRefused(TeamsException::WILDCARD, fn () => Teams::createRole('alles', 'Alles', ['*'], $this->team, $warden));

        // Within what they hold, it works.
        Teams::createRole('helfer', 'Helfer', ['invite members'], $this->team, $warden);
        $this->assertSame(['invite members'], Teams::roles($this->team)['helfer']['permissions']);
        $this->assertSame(['manage team roles', 'invite members'], Teams::roles($this->team)['rollenwart']['permissions']);
    }

    #[Test]
    public function a_role_editor_moves_members_only_into_a_role_they_cover(): void
    {
        Teams::createRole('rollenwart', 'Rollenwart', ['manage team roles', 'invite members'], $this->team);
        Teams::createRole('helfer', 'Helfer', ['invite members'], $this->team);
        $warden = $this->makeUser('warden@example.com');
        Teams::addMember($this->team, $warden, 'rollenwart');
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob, 'helfer');

        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::deleteRole('helfer', $this->team, 'admin', $warden));
        $this->assertSame('helfer', $this->team->roleOf($bob));

        Teams::deleteRole('helfer', $this->team, 'member', $warden);
        $this->assertSame('member', $this->team->roleOf($bob));
    }

    #[Test]
    public function an_owner_manages_the_roles_of_their_team(): void
    {
        Teams::createRole('kasse', 'Kasse', ['view billing', 'manage billing'], $this->team, $this->owner);
        Teams::updateRole('kasse', ['label' => 'Kassenwart'], $this->team, $this->owner);

        $this->assertSame('Kassenwart', Teams::roles($this->team)['kasse']['label']);
    }

    // Events ---------------------------------------------------------------

    #[Test]
    public function every_role_change_fires_its_event(): void
    {
        Event::fake([RoleCreated::class, RoleUpdated::class, RoleDeleted::class]);

        Teams::createRole('kasse', 'Kasse', ['view billing'], $this->team, $this->owner);
        Teams::updateRole('kasse', ['label' => 'Kassenwart'], $this->team);
        Teams::deleteRole('kasse', $this->team);
        Teams::createRole('stimmfuehrung', 'Stimmführung');

        Event::assertDispatched(RoleCreated::class, function (RoleCreated $e) {
            $payload = $e->payload();

            return $payload['role']['handle'] === 'kasse'
                && $payload['role']['scope'] === 'team'
                && $payload['team']['id'] === $this->team->id
                && $payload['team_type'] === 'team'
                && $payload['actor_id'] === (string) $this->owner->id();
        });
        Event::assertDispatched(RoleUpdated::class, fn (RoleUpdated $e) => $e->payload()['changes'] === ['label'] && $e->payload()['role']['label'] === 'Kassenwart');
        Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $e) => $e->payload()['role']['handle'] === 'kasse');
        Event::assertDispatched(RoleCreated::class, fn (RoleCreated $e) => $e->payload()['team'] === null && $e->payload()['role']['scope'] === 'global');
    }

    #[Test]
    public function deleting_with_a_move_says_where_to_and_how_many(): void
    {
        Event::fake([RoleDeleted::class]);

        Teams::deleteRole('admin', null, 'member');

        Event::assertDispatched(RoleDeleted::class, fn (RoleDeleted $e) => $e->payload()['reassigned_to'] === 'member' && $e->payload()['reassigned'] === 1);
    }
}
