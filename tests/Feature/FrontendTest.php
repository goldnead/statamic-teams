<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

class FrontendTest extends TestCase
{
    protected function antlers(string $template, array $data = []): string
    {
        // Third argument true: without it Antlers::parse() runs no tags.
        return (string) Antlers::parse($template, $data, true);
    }

    #[Test]
    public function the_invitation_page_sends_a_guest_to_the_login_first(): void
    {
        $issued = Teams::invite(Teams::create('Chor', $this->makeUser('owner@example.com')), 'bob@example.com');

        $this->get('/teams/invitations/'.$issued->token)
            ->assertRedirect('/login?redirect='.urlencode(url('/teams/invitations/'.$issued->token)));
    }

    #[Test]
    public function the_invitation_page_shows_the_team_and_accepting_is_a_post(): void
    {
        $team = Teams::create('Kammerchor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        $issued = Teams::invite($team, 'bob@example.com');

        $this->actingAs($bob)->get('/teams/invitations/'.$issued->token)
            ->assertOk()
            ->assertSee('Kammerchor');

        $this->assertFalse($team->hasMember($bob), 'Opening the link must not accept.');

        $this->actingAs($bob)->post('/!/statamic-teams/invitations/'.$issued->token.'/accept', ['_redirect' => '/chor'])
            ->assertRedirect('/chor')
            ->assertSessionHas('teams.success');

        $this->assertTrue($team->hasMember($bob));
        $this->assertTrue(Teams::current($bob)->is($team));
    }

    #[Test]
    public function a_dead_link_says_why(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $issued = Teams::invite($team, 'bob@example.com');
        Teams::revokeInvitation($issued->invitation);

        $this->actingAs($this->makeUser('bob@example.com'))
            ->get('/teams/invitations/'.$issued->token)
            ->assertStatus(410)
            ->assertSee(__('teams::messages.errors.invitation_revoked'));
    }

    #[Test]
    public function joining_by_code_through_the_form(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => Team::JOIN_CODE]);
        $bob = $this->makeUser('bob@example.com');

        $this->actingAs($bob)->post('/!/statamic-teams/join', ['code' => $team->join_code])->assertSessionHas('teams.success');
        $this->assertTrue($team->hasMember($bob));

        $this->actingAs($this->makeUser('eve@example.com'))
            ->post('/!/statamic-teams/join', ['code' => 'FALSCH2345'])
            ->assertSessionHasErrors('teams', null, 'teams');
    }

    #[Test]
    public function a_form_asked_for_json_answers_with_the_reason(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'));
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($team, $bob);

        $this->actingAs($bob)
            ->postJson('/!/statamic-teams/'.$team->id.'/invite', ['email' => 'eve@example.com'])
            ->assertStatus(403)
            ->assertJson(['reason' => 'forbidden']);
    }

    #[Test]
    public function the_forms_need_a_signed_in_user(): void
    {
        $this->postJson('/!/statamic-teams/join', ['code' => 'ABCDEFGH23'])->assertStatus(401);
    }

    #[Test]
    public function creating_switching_inviting_and_leaving_through_forms(): void
    {
        $bob = $this->makeUser('bob@example.com');
        $this->actingAs($bob)->post('/!/statamic-teams/create', ['name' => 'Neuer Chor'])->assertSessionHas('teams.success');
        $team = Team::query()->where('name', 'Neuer Chor')->firstOrFail();
        $this->assertSame('owner', $team->roleOf($bob));

        $other = Teams::create('Anderer', $this->makeUser('eve@example.com'));
        Teams::addMember($other, $bob);
        $this->actingAs($bob)->post('/!/statamic-teams/switch', ['team' => $other->uuid]);
        Teams::setCurrent(null);
        $this->assertTrue(Teams::current($bob)->is($other));

        $this->actingAs($bob)->post('/!/statamic-teams/'.$team->id.'/invite', ['email' => 'neu@example.com', 'role' => 'admin'])->assertSessionHas('teams.success');
        $this->assertSame(1, $team->invitations()->count());

        $this->actingAs($bob)->post('/!/statamic-teams/'.$other->id.'/leave')->assertSessionHas('teams.success');
        $this->assertFalse($other->hasMember($bob));
    }

    #[Test]
    public function the_tags_list_teams_members_and_permissions(): void
    {
        $owner = $this->makeUser('owner@example.com', 'Olga');
        $bob = $this->makeUser('bob@example.com', 'Bob');
        $team = Teams::create('Kammerchor', $owner, ['join_method' => Team::JOIN_CODE]);
        Teams::addMember($team, $bob, null, ['voice_part' => 'bass']);
        Teams::invite($team, 'neu@example.com');

        $this->actingAs($owner);

        $this->assertSame('Kammerchor:owner:1|', $this->antlers('{{ teams }}{{ name }}:{{ role }}:{{ is_current ? "1" : "0" }}|{{ /teams }}'));
        $this->assertSame('Kammerchor', $this->antlers('{{ teams:current }}{{ name }}{{ /teams:current }}'));
        $this->assertSame($team->join_code, $this->antlers('{{ teams:current }}{{ join_code }}{{ /teams:current }}'));
        $this->assertSame('Olga-owner,Bob-member-bass,', $this->antlers('{{ teams:members }}{{ name }}-{{ role }}{{ if meta:voice_part }}-{{ meta:voice_part }}{{ /if }},{{ /teams:members }}'));
        $this->assertSame('neu@example.com', $this->antlers('{{ teams:invitations }}{{ email }}{{ /teams:invitations }}'));
        $this->assertSame('ja', $this->antlers('{{ teams:can do="invite members" }}ja{{ /teams:can }}'));

        $this->actingAs($bob);
        $this->assertSame('', $this->antlers('{{ teams:can do="invite members" }}ja{{ /teams:can }}'));
        $this->assertSame('', $this->antlers('{{ teams:invitations }}{{ email }}{{ /teams:invitations }}'));
        $this->assertSame('', $this->antlers('{{ teams:current }}{{ join_code }}{{ /teams:current }}'));
    }

    #[Test]
    public function the_form_tags_render_a_post_form_with_csrf(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Kammerchor', $owner);
        $this->actingAs($owner);

        $html = $this->antlers('{{ teams:invite_form redirect="/mitglieder" class="stack" }}{{ roles }}[{{ handle }}]{{ /roles }}<input name="email">{{ /teams:invite_form }}');

        $this->assertStringContainsString('action="'.route('statamic.teams.forms.invite', $team->id).'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="_redirect" value="/mitglieder"', $html);
        $this->assertStringContainsString('class="stack"', $html);
        $this->assertStringContainsString('[admin][member]', $html);
        $this->assertStringNotContainsString('[owner]', $html);
    }
}
