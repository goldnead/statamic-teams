<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

class MiddlewareTest extends TestCase
{
    protected Team $choir;

    protected Team $other;

    protected mixed $bob;

    /**
     * Statamic's front-end catch-all is registered before these test routes
     * and would answer every one of them with 404.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $echo = fn () => response()->json(['team' => Teams::current()?->id]);

        Route::middleware(['web', 'teams.current'])->group(function () use ($echo) {
            Route::match(['get', 'post'], '/_test/mixed', $echo);
            Route::get('/_test/teams/{team}', $echo);
        });
        Route::middleware(['web', 'teams.current:required', 'teams.writable'])
            ->match(['get', 'post'], '/_test/required', $echo);

        $this->bob = $this->makeUser('bob@example.com');
        $this->choir = Teams::create('Chor', $this->bob);
        $this->other = Teams::create('Fremder Chor', $this->makeUser('eve@example.com'));
    }

    #[Test]
    public function a_non_member_gets_403(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/mixed', ['X-Team-ID' => (string) $this->other->id])
            ->assertStatus(403);
    }

    #[Test]
    public function a_team_that_does_not_exist_is_also_403(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/mixed?team_id=99999')
            ->assertStatus(403);
    }

    #[Test]
    public function contradicting_team_names_get_422(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/mixed?team_id='.$this->other->id, ['X-Team-ID' => (string) $this->choir->id])
            ->assertStatus(422);

        $this->actingAs($this->bob)
            ->getJson('/_test/teams/'.$this->choir->id.'?team_id='.$this->other->id)
            ->assertStatus(422);
    }

    #[Test]
    public function id_and_uuid_of_the_same_team_agree(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/mixed?team_id='.$this->choir->uuid, ['X-Team-ID' => (string) $this->choir->id])
            ->assertOk()
            ->assertJson(['team' => $this->choir->id]);
    }

    #[Test]
    public function the_body_counts_as_a_source(): void
    {
        $this->actingAs($this->bob)
            ->postJson('/_test/mixed', ['team_id' => $this->other->id])
            ->assertStatus(403);
    }

    #[Test]
    public function without_a_named_team_mixed_mode_uses_the_current_one(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/mixed')
            ->assertOk()
            ->assertJson(['team' => $this->choir->id]);
    }

    #[Test]
    public function required_mode_without_a_named_team_is_422(): void
    {
        $this->actingAs($this->bob)
            ->getJson('/_test/required')
            ->assertStatus(422);
    }

    #[Test]
    public function a_read_only_team_refuses_writes_with_423_but_allows_reads(): void
    {
        Teams::update($this->choir, ['settings' => ['read_only' => true]]);
        $headers = ['X-Team-ID' => (string) $this->choir->id];

        $this->actingAs($this->bob)->getJson('/_test/required', $headers)->assertOk();
        $this->actingAs($this->bob)->postJson('/_test/required', [], $headers)->assertStatus(423);
    }
}
