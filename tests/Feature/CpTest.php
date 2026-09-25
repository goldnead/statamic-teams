<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Mail\TeamMail;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

class CpTest extends TestCase
{
    /**
     * Every CP write route, for the authorization sweep.
     *
     * @return array<string, array{string, string}>
     */
    public static function writeRoutes(): array
    {
        return [
            'store' => ['post', '/cp/teams'],
            'update' => ['patch', '/cp/teams/{team}'],
            'destroy' => ['delete', '/cp/teams/{team}'],
            'join code' => ['post', '/cp/teams/{team}/join-code'],
            'role' => ['patch', '/cp/teams/{team}/members/{membership}'],
            'remove' => ['delete', '/cp/teams/{team}/members/{membership}'],
            'invite' => ['post', '/cp/teams/{team}/invitations'],
            'resend' => ['post', '/cp/teams/{team}/invitations/{invitation}/resend'],
            'revoke' => ['delete', '/cp/teams/{team}/invitations/{invitation}'],
            'mail templates' => ['post', '/cp/teams/mail-templates'],
        ];
    }

    #[Test]
    #[DataProvider('writeRoutes')]
    public function every_write_route_needs_manage_teams(string $method, string $uri): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $invitation = Teams::invite($team, 'bob@example.com')->invitation;
        $membership = $team->members()->first();

        $uri = str_replace(['{team}', '{membership}', '{invitation}'], [$team->id, $membership->id, $invitation->id], $uri);

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->{$method.'Json'}($uri, ['name' => 'X', 'type' => 'team', 'role' => 'admin', 'email' => 'x@example.com'])
            ->assertForbidden();

        $this->assertSame('Chor', $team->fresh()->name);
    }

    #[Test]
    public function the_pages_need_view_teams(): void
    {
        $team = Teams::create('Chor');

        // As JSON: a browser request is turned into a redirect to /cp with a
        // flash message by Statamic's CP exception handling.
        $this->actingAsCpUser('nobody@example.com')->getJson('/cp/teams')->assertForbidden();
        $this->getJson('/cp/teams/'.$team->id)->assertForbidden();
        $this->getJson('/cp/teams/wiring')->assertForbidden();
        $this->get('/cp/teams')->assertRedirect('/cp');
    }

    #[Test]
    public function the_index_lists_the_teams(): void
    {
        Teams::create('Kammerchor', $this->makeUser('owner@example.com', 'Olga'));

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams')
            ->assertOk()
            ->assertSee('teams::Teams\\/Index', false)
            ->assertSee('Kammerchor', false)
            ->assertSee('Olga', false);
    }

    #[Test]
    public function the_team_page_shows_members_invitations_and_the_subject(): void
    {
        $owner = $this->makeUser('owner@example.com', 'Olga');
        $team = Teams::create('Kammerchor', $owner);
        Teams::invite($team, 'bob@example.com');

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/'.$team->id)
            ->assertOk()
            ->assertSee('teams::Teams\\/Show', false)
            ->assertSee('owner@example.com', false)
            ->assertSee('bob@example.com', false)
            ->assertSee('team:'.$team->id, false);
    }

    #[Test]
    public function a_manager_creates_a_team_with_an_existing_owner(): void
    {
        $this->makeUser('olga@example.com');

        $this->actingAsCpUser('admin@example.com', ['view teams', 'manage teams'])
            ->post('/cp/teams', ['name' => 'Neuer Chor', 'type' => 'team', 'owner_email' => 'olga@example.com'])
            ->assertRedirect();

        $team = Team::query()->where('name', 'Neuer Chor')->firstOrFail();
        $this->assertSame('owner', $team->roleOf(User::findByEmail('olga@example.com')));
    }

    #[Test]
    public function an_unknown_owner_is_a_form_error_not_a_team_without_owner(): void
    {
        $this->actingAsCpUser('admin@example.com', ['view teams', 'manage teams'])
            ->post('/cp/teams', ['name' => 'Neuer Chor', 'type' => 'team', 'owner_email' => 'gibtsnicht@example.com'])
            ->assertSessionHasErrors('owner_email');

        $this->assertSame(0, Team::query()->count());
    }

    #[Test]
    public function a_manager_invites_changes_roles_and_edits_billing(): void
    {
        Mail::fake();
        $owner = $this->makeUser('owner@example.com');
        $bob = $this->makeUser('bob@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $bob);
        $membership = $team->membershipOf($bob);

        $this->actingAsCpUser('admin@example.com', ['view teams', 'manage teams']);

        $this->post('/cp/teams/'.$team->id.'/invitations', ['email' => 'neu@example.com', 'role' => 'member'])->assertSessionHasNoErrors();
        Mail::assertSent(TeamMail::class, fn (TeamMail $m) => $m->hasTo('neu@example.com'));

        $this->patch('/cp/teams/'.$team->id.'/members/'.$membership->id, ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->assertSame('admin', $team->roleOf($bob));

        $this->patch('/cp/teams/'.$team->id, ['billing' => ['company' => 'Chor e.V.', 'country' => 'DE']])->assertSessionHasNoErrors();
        $this->assertSame('Chor e.V.', $team->fresh()->billing['company']);

        $this->patch('/cp/teams/'.$team->id, ['read_only' => true])->assertSessionHasNoErrors();
        $this->assertTrue($team->fresh()->isReadOnly());
    }

    #[Test]
    public function removing_the_last_owner_from_the_cp_is_a_form_error(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);

        $this->actingAsCpUser('admin@example.com', ['view teams', 'manage teams'])
            ->delete('/cp/teams/'.$team->id.'/members/'.$team->membershipOf($owner)->id)
            ->assertSessionHasErrors('member');

        $this->assertTrue($team->hasMember($owner));
    }

    #[Test]
    public function the_wiring_page_lists_every_event_with_its_mail(): void
    {
        $response = $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/wiring')
            ->assertOk()
            ->assertSee('teams::Teams\\/Wiring', false);

        foreach (EventCatalog::handles() as $handle) {
            $response->assertSee($handle, false);
        }

        $response->assertSee('teams-invitation', false);
    }
}
