<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamRole;
use Goldnead\Teams\Support\GlobalRoleStore;
use Goldnead\Teams\Support\TeamRoleStore;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Findings from the review of 0.3.0: widening through a detour, a silent
 * fallback to the config, a race on deletion, and a query per check.
 */
class RoleHardeningTest extends TestCase
{
    protected Team $team;

    protected mixed $owner;

    protected mixed $warden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeUser('owner@example.com');
        $this->team = Teams::create('Chor', $this->owner);
        Teams::createRole('rollenwart', 'Rollenwart', ['manage team roles', 'invite members'], $this->team);
        $this->warden = $this->makeUser('warden@example.com');
        Teams::addMember($this->team, $this->warden, 'rollenwart');
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

    #[Test]
    public function a_role_editor_cannot_restore_a_wider_global_role(): void
    {
        // The owner narrows admin for this team; a member holds it.
        Teams::createRole('admin', 'Admin', ['invite members'], $this->team);
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob, 'admin');

        // Deleting the narrow team version hands admin's global
        // `manage billing` back: more than the warden holds.
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::deleteRole('admin', $this->team, null, $this->warden));
        $this->assertFalse(Teams::can($bob, $this->team, 'manage billing'));

        // A team version of a global role the warden covers may go.
        Teams::createRole('helfer', 'Helfer', ['invite members']);
        Teams::createRole('helfer', 'Helfer (eng)', [], $this->team);
        Teams::deleteRole('helfer', $this->team, null, $this->warden);
        $this->assertSame('global', Teams::roles($this->team)['helfer']['scope']);

        // The owner may restore the wider one.
        Teams::deleteRole('admin', $this->team, null, $this->owner);
        $this->assertTrue(Teams::can($bob, $this->team, 'manage billing'));
    }

    #[Test]
    public function a_new_global_role_does_not_silently_take_over_a_team_role_of_the_same_handle(): void
    {
        $e = $this->assertRefused(TeamsException::ROLE_HANDLE_IN_TEAMS, fn () => Teams::createRole('rollenwart', 'Rollenwart', ['invite members']));

        $this->assertSame(409, $e->status());
        $this->assertSame([$this->team->id], array_column($e->details['teams'], 'id'));
        $this->assertArrayNotHasKey('rollenwart', Teams::roles());
        $this->assertFalse(Teams::roles($this->team)['rollenwart']['overrides_global']);
    }

    #[Test]
    public function resetting_a_deleted_config_role_checks_the_same_collision(): void
    {
        Teams::deleteRole('admin');
        Teams::createRole('admin', 'Eigener Admin', ['invite members'], $this->team);

        $this->assertRefused(TeamsException::ROLE_HANDLE_IN_TEAMS, fn () => Teams::resetRole('admin'));
        $this->assertArrayNotHasKey('admin', Teams::roles());
    }

    #[Test]
    public function without_the_table_the_config_applies(): void
    {
        Schema::drop('team_global_roles');
        app(GlobalRoleStore::class)->flush();

        $this->assertSame('Admin', Teams::roles()['admin']['label']);
    }

    #[Test]
    public function any_other_database_error_is_not_turned_into_the_config(): void
    {
        Teams::updateRole('admin', ['permissions' => ['invite members']]);

        // A table that exists but cannot be read.
        Schema::drop('team_global_roles');
        DB::statement('CREATE VIEW team_global_roles AS SELECT no_such_function(1) AS handle');
        app(GlobalRoleStore::class)->flush();

        $this->expectException(QueryException::class);
        Teams::roles();
    }

    #[Test]
    public function a_holder_appearing_during_the_deletion_rolls_it_back(): void
    {
        Teams::createRole('kasse', 'Kasse', ['view billing'], $this->team);
        $late = $this->makeUser('late@example.com');

        // Somebody gets the role between the check and the delete.
        TeamRole::deleting(function (TeamRole $role) use ($late) {
            if ($role->handle === 'kasse') {
                Membership::query()->create(['team_id' => $role->team_id, 'user_id' => (string) $late->id(), 'role' => 'kasse', 'joined_at' => now()]);
            }
        });

        $this->assertRefused(TeamsException::ROLE_IN_USE, fn () => Teams::deleteRole('kasse', $this->team));

        // The deletion is undone. (The late membership, written inside the
        // same transaction here, goes with it; a real concurrent writer
        // commits its own.)
        $this->assertArrayHasKey('kasse', Teams::roles($this->team->fresh()));
        $this->assertSame(1, TeamRole::query()->where('handle', 'kasse')->count());
    }

    #[Test]
    public function team_roles_are_read_once_per_request_and_again_after_a_change(): void
    {
        app(TeamRoleStore::class)->flush();
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, '"team_roles"') && str_starts_with(strtolower($query->sql), 'select')) {
                $queries++;
            }
        });

        foreach (['invite members', 'remove members', 'manage billing', 'view billing'] as $permission) {
            Teams::can($this->warden, $this->team, $permission);
        }

        $this->assertSame(1, $queries);

        Teams::updateRole('rollenwart', ['permissions' => ['manage team roles', 'invite members', 'remove members']], $this->team);
        $this->assertTrue(Teams::can($this->warden, $this->team, 'remove members'));
    }
}
