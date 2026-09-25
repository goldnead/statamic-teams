<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The CP pages "Roles" (global) and the roles panel of a team.
 */
class RolesCpTest extends TestCase
{
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Teams::create('Chor', $this->makeUser('owner@example.com'));
    }

    /** @return array<string, array{string, string}> */
    public static function roleRoutes(): array
    {
        return [
            'index' => ['get', '/cp/teams/roles'],
            'create' => ['get', '/cp/teams/roles/create'],
            'store' => ['post', '/cp/teams/roles'],
            'edit' => ['get', '/cp/teams/roles/admin/edit'],
            'update' => ['patch', '/cp/teams/roles/admin'],
            'reset' => ['post', '/cp/teams/roles/admin/reset'],
            'destroy' => ['delete', '/cp/teams/roles/admin'],
            'team store' => ['post', '/cp/teams/{team}/roles'],
            'team update' => ['patch', '/cp/teams/{team}/roles/admin'],
            'team destroy' => ['delete', '/cp/teams/{team}/roles/admin'],
        ];
    }

    #[Test]
    #[DataProvider('roleRoutes')]
    public function every_role_route_needs_manage_team_roles_even_for_a_team_manager(string $method, string $uri): void
    {
        $uri = str_replace('{team}', (string) $this->team->id, $uri);

        $this->actingAsCpUser('manager@example.com', ['view teams', 'manage teams', 'manage teams settings'])
            ->{$method.'Json'}($uri, ['title' => 'X', 'handle' => 'x', 'permissions' => ['invite members'], 'label' => 'X'])
            ->assertForbidden();

        $this->assertSame('Admin', Teams::roles()['admin']['label']);
        $this->assertArrayNotHasKey('x', Teams::roles($this->team));
    }

    #[Test]
    public function the_index_shows_every_role_with_its_permissions(): void
    {
        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles'])
            ->get('/cp/teams/roles')
            ->assertOk()
            ->assertSee('teams::Roles\\/Index', false)
            ->assertSee('invite members', false)
            ->assertSee('"handle":"admin"');
    }

    #[Test]
    public function the_edit_page_is_core_publish_form_with_checkboxes(): void
    {
        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles'])
            ->get('/cp/teams/roles/admin/edit')
            ->assertOk()
            ->assertSee('"component":"PublishForm"')
            ->assertSee('checkboxes', false);
    }

    #[Test]
    public function a_role_is_created_changed_reset_and_deleted_from_the_cp(): void
    {
        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles']);

        $this->postJson('/cp/teams/roles', ['title' => 'Stimmführung', 'handle' => 'stimmfuehrung', 'permissions' => ['invite members']])
            ->assertOk()
            ->assertJsonStructure(['redirect']);
        $this->assertSame('Stimmführung', Teams::roles()['stimmfuehrung']['label']);

        $this->patchJson('/cp/teams/roles/admin', ['title' => 'Vorstand', 'permissions' => ['invite members']])->assertOk();
        $this->assertSame('Vorstand', Teams::roles()['admin']['label']);

        $this->post('/cp/teams/roles/admin/reset')->assertSessionHasNoErrors();
        $this->assertSame('Admin', Teams::roles()['admin']['label']);

        $this->delete('/cp/teams/roles/stimmfuehrung')->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('stimmfuehrung', Teams::roles());
    }

    #[Test]
    public function the_wildcard_and_unknown_permissions_are_form_errors(): void
    {
        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles']);

        $this->patchJson('/cp/teams/roles/admin', ['title' => 'Admin', 'permissions' => ['*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
        $this->postJson('/cp/teams/roles', ['title' => 'X', 'handle' => 'x', 'permissions' => ['fly']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->assertNotContains('*', Teams::roles()['admin']['permissions']);
    }

    #[Test]
    public function a_protected_or_held_role_is_not_deleted_from_the_cp(): void
    {
        Teams::addMember($this->team, $this->makeUser('bob@example.com'), 'admin');

        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles']);

        $this->delete('/cp/teams/roles/owner')->assertSessionHasErrors('role');
        $this->delete('/cp/teams/roles/member')->assertSessionHasErrors('role');
        $this->delete('/cp/teams/roles/admin')->assertSessionHasErrors('role');
        $this->assertArrayHasKey('admin', Teams::roles());

        $this->delete('/cp/teams/roles/admin', ['reassign_to' => 'member'])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('admin', Teams::roles());
    }

    #[Test]
    public function the_team_page_manages_the_roles_of_that_team(): void
    {
        $this->actingAsCpUser('roles@example.com', ['view teams', 'manage team roles']);

        $this->post('/cp/teams/'.$this->team->id.'/roles', ['handle' => 'kasse', 'label' => 'Kasse', 'permissions' => ['view billing']])
            ->assertSessionHasNoErrors();
        $this->assertSame('team', Teams::roles($this->team)['kasse']['scope']);

        $this->patch('/cp/teams/'.$this->team->id.'/roles/kasse', ['label' => 'Kassenwart', 'permissions' => ['view billing', 'manage billing']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Kassenwart', Teams::roles($this->team)['kasse']['label']);

        $this->post('/cp/teams/'.$this->team->id.'/roles', ['handle' => 'alles', 'label' => 'Alles', 'permissions' => ['*']])
            ->assertSessionHasErrors('permissions');

        $this->get('/cp/teams/'.$this->team->id)
            ->assertOk()
            ->assertSee('"scope":"team"')
            ->assertSee('"canManageRoles":true');

        $this->delete('/cp/teams/'.$this->team->id.'/roles/kasse')->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('kasse', Teams::roles($this->team));
    }

    #[Test]
    public function the_team_page_does_not_offer_role_editing_without_the_permission(): void
    {
        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/'.$this->team->id)
            ->assertOk()
            ->assertSee('"canManageRoles":false');
    }

    #[Test]
    public function the_role_events_are_on_the_wiring_page(): void
    {
        $this->assertContains('teams.role.created', EventCatalog::handles());
        $this->assertContains('teams.role.updated', EventCatalog::handles());
        $this->assertContains('teams.role.deleted', EventCatalog::handles());

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/wiring')
            ->assertSee('teams.role.deleted', false);
    }
}
