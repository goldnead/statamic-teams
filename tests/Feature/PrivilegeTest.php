<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamRole;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Nobody hands out more than they hold.
 *
 * Whoever assigns, invites into or removes from a role must hold every
 * permission of that role; `*` belongs to owners only. Changing one's own
 * role, and demoting or removing an owner, is an owner's business.
 */
class PrivilegeTest extends TestCase
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

        TeamRole::query()->create(['team_id' => $this->team->id, 'handle' => 'allmacht', 'label' => 'Allmacht', 'permissions' => ['*']]);
        TeamRole::query()->create(['team_id' => $this->team->id, 'handle' => 'kasse', 'label' => 'Kasse', 'permissions' => ['manage billing', 'delete team']]);
    }

    protected function assertRefused(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected refusal [{$reason}].");
        } catch (TeamsException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    #[Test]
    public function an_admin_cannot_give_himself_a_role_with_every_permission(): void
    {
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::changeRole($this->team, $this->admin, 'allmacht', $this->admin));
        $this->assertSame('admin', $this->team->roleOf($this->admin));
    }

    #[Test]
    public function an_admin_cannot_change_his_own_role_at_all(): void
    {
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::changeRole($this->team, $this->admin, 'member', $this->admin));
    }

    #[Test]
    public function an_admin_cannot_hand_out_a_role_holding_more_than_his_own(): void
    {
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob);

        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::changeRole($this->team, $bob, 'allmacht', $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::changeRole($this->team, $bob, 'kasse', $this->admin));

        Teams::changeRole($this->team, $bob, 'admin', $this->admin);
        $this->assertSame('admin', $this->team->roleOf($bob));
    }

    #[Test]
    public function an_admin_cannot_invite_into_a_higher_role(): void
    {
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::invite($this->team, 'x@example.com', 'allmacht', [], $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::invite($this->team, 'x@example.com', 'kasse', [], $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::invite($this->team, 'x@example.com', 'owner', [], $this->admin));

        $this->assertSame('admin', Teams::invite($this->team, 'x@example.com', 'admin', [], $this->admin)->invitation->role);
    }

    #[Test]
    public function an_admin_cannot_demote_or_remove_an_owner(): void
    {
        $second = $this->makeUser('second@example.com');
        Teams::addMember($this->team, $second, 'owner');

        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::changeRole($this->team, $second, 'member', $this->admin));
        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::removeMember($this->team, $second, $this->admin));
        $this->assertSame('owner', $this->team->roleOf($second));

        Teams::changeRole($this->team, $second, 'member', $this->owner);
        $this->assertSame('member', $this->team->roleOf($second));
    }

    #[Test]
    public function an_admin_cannot_remove_somebody_whose_role_holds_more(): void
    {
        $cashier = $this->makeUser('cashier@example.com');
        Teams::addMember($this->team, $cashier, 'kasse');

        $this->assertRefused(TeamsException::FORBIDDEN, fn () => Teams::removeMember($this->team, $cashier, $this->admin));
        $this->assertTrue($this->team->hasMember($cashier));
    }

    #[Test]
    public function owners_may_do_all_of_it(): void
    {
        $bob = $this->makeUser('bob@example.com');
        Teams::addMember($this->team, $bob);

        Teams::changeRole($this->team, $bob, 'allmacht', $this->owner);
        Teams::removeMember($this->team, $this->admin, $this->owner);

        $this->assertSame('allmacht', $this->team->roleOf($bob));
        $this->assertFalse($this->team->hasMember($this->admin));
    }

    #[Test]
    public function the_front_end_form_refuses_the_escalation_with_the_reason(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/!/statamic-teams/'.$this->team->id.'/members/'.$this->admin->id().'/role', ['role' => 'allmacht'])
            ->assertStatus(403)
            ->assertJson(['reason' => 'forbidden']);
    }

    #[Test]
    public function ownership_goes_from_the_actor_and_not_to_the_same_person(): void
    {
        $second = $this->makeUser('second@example.com');
        Teams::addMember($this->team, $second, 'owner');
        $next = $this->makeUser('next@example.com');
        Teams::addMember($this->team, $next);

        Teams::transferOwnership($this->team, $next, $second);

        $this->assertSame('owner', $this->team->roleOf($next));
        $this->assertSame('admin', $this->team->roleOf($second));
        $this->assertSame('owner', $this->team->roleOf($this->owner), 'The recorded owner is not the actor and keeps the role.');

        $this->assertRefused(TeamsException::ALREADY_OWNER, fn () => Teams::transferOwnership($this->team, $next, $next));
    }
}
