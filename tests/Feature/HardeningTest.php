<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class HardeningTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function foreignTargets(): array
    {
        return [
            'protocol relative' => ['//evil.example'],
            'backslash' => ['/\\evil.example'],
            'absolute' => ['https://evil.example/'],
        ];
    }

    #[Test]
    #[DataProvider('foreignTargets')]
    public function a_form_never_redirects_off_the_site(string $target): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => 'join_code']);

        $response = $this->actingAs($this->makeUser('bob@example.com'))
            ->from('/konto')
            ->post('/!/statamic-teams/join', ['code' => $team->join_code, '_redirect' => $target]);

        $response->assertRedirect('/konto');
    }

    #[Test]
    public function a_path_on_the_site_is_followed(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => 'join_code']);

        $this->actingAs($this->makeUser('bob@example.com'))
            ->post('/!/statamic-teams/join', ['code' => $team->join_code, '_redirect' => '/chor/start'])
            ->assertRedirect('/chor/start');
    }

    #[Test]
    public function joining_by_code_is_limited_per_account(): void
    {
        config(['teams.routes.join_limits' => ['per_user' => 3, 'per_ip' => 100]]);
        $bob = $this->makeUser('bob@example.com');

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($bob)->postJson('/!/statamic-teams/join', ['code' => 'FALSCH'.$i.'AB23'])->assertStatus(404);
        }

        $this->actingAs($bob)->postJson('/!/statamic-teams/join', ['code' => 'FALSCH9AB23'])->assertStatus(429);

        // Another account from the same address still gets through.
        $this->actingAs($this->makeUser('eve@example.com'))->postJson('/!/statamic-teams/join', ['code' => 'FALSCH9AB23'])->assertStatus(404);
    }

    #[Test]
    public function joining_by_code_is_limited_per_address(): void
    {
        config(['teams.routes.join_limits' => ['per_user' => 100, 'per_ip' => 2]]);

        foreach (['a', 'b'] as $name) {
            $this->actingAs($this->makeUser($name.'@example.com'))->postJson('/!/statamic-teams/join', ['code' => 'FALSCHXAB23'])->assertStatus(404);
        }

        $this->actingAs($this->makeUser('c@example.com'))->postJson('/!/statamic-teams/join', ['code' => 'FALSCHXAB23'])->assertStatus(429);
    }

    #[Test]
    public function meta_keys_get_a_label_in_the_cp(): void
    {
        config(['teams.meta_labels' => ['voice_part' => 'Stimmgruppe']]);
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $this->makeUser('bob@example.com'), null, ['voice_part' => 'bass', 'seat_row' => 3]);

        $this->actingAsCpUser('viewer@example.com', ['view teams'])
            ->get('/cp/teams/'.$team->id)
            ->assertOk()
            ->assertSee('Stimmgruppe', false)
            ->assertSee('Seat row', false);
    }

    #[Test]
    public function event_descriptions_carry_no_markdown(): void
    {
        foreach (EventCatalog::all() as $event) {
            $this->assertStringNotContainsString('`', $event['description'], $event['handle']);
        }

        app()->setLocale('de');

        foreach (EventCatalog::all() as $event) {
            $this->assertStringNotContainsString('`', $event['description'], $event['handle']);
        }
    }
}
