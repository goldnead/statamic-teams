<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Membership;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Models\TeamRole;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The second critique's probes, as tests: a role that holds a subset of
 * another, stale invitations, imports that reach into other teams, and a
 * transfer racing a leave.
 */
class RoundTwoTest extends TestCase
{
    protected Team $team;

    protected mixed $owner;

    protected mixed $admin;

    protected mixed $helper;

    protected mixed $member;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('statamic.routes.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'teams.current'])->get('/_probe/or-fail', fn () => 'team '.Teams::currentOrFail()->id);

        $this->owner = $this->makeUser('owner@example.com');
        $this->admin = $this->makeUser('admin@example.com');
        $this->helper = $this->makeUser('helper@example.com');
        $this->member = $this->makeUser('member@example.com');
        $this->team = Teams::create('Chor', $this->owner);
        Teams::addMember($this->team, $this->admin, 'admin');
        TeamRole::query()->create(['team_id' => $this->team->id, 'handle' => 'helfer', 'label' => 'Helfer', 'permissions' => ['invite members', 'change roles', 'remove members']]);
        Teams::addMember($this->team, $this->helper, 'helfer');
        Teams::addMember($this->team, $this->member, 'member');
    }

    protected function outcome(callable $op): string
    {
        try {
            $op();

            return 'allowed';
        } catch (TeamsException $e) {
            return 'refused:'.$e->reason;
        }
    }

    #[Test]
    public function current_or_fail_answers_422_to_a_browser_as_well(): void
    {
        config(['teams.current.fallback_to_current' => false]);

        $this->actingAs($this->member)->get('/_probe/or-fail')->assertStatus(422);
        $this->actingAs($this->member)->getJson('/_probe/or-fail')->assertStatus(422)->assertJson(['reason' => 'team_required']);
    }

    #[Test]
    public function a_subset_role_can_neither_go_up_nor_around(): void
    {
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::changeRole($this->team, $this->member, 'admin', $this->helper)));
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::changeRole($this->team, $this->admin, 'member', $this->helper)));
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::removeMember($this->team, $this->admin, $this->helper)));
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::invite($this->team, 'x@example.com', 'admin', [], $this->helper)));
        $this->assertSame('allowed', $this->outcome(fn () => Teams::invite($this->team, 'x@example.com', 'helfer', [], $this->helper)));
    }

    #[Test]
    public function meta_of_somebody_holding_more_is_not_editable(): void
    {
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::updateMemberMeta($this->team, $this->owner, ['voice_part' => 'x'], $this->admin)));
        $this->assertSame('refused:forbidden', $this->outcome(fn () => Teams::updateMemberMeta($this->team, $this->admin, ['voice_part' => 'x'], $this->helper)));
        $this->assertSame('allowed', $this->outcome(fn () => Teams::updateMemberMeta($this->team, $this->member, ['voice_part' => 'alto'], $this->helper)));
        $this->assertSame('allowed', $this->outcome(fn () => Teams::updateMemberMeta($this->team, $this->member, ['voice_part' => 'bass'], $this->member)));
    }

    #[Test]
    public function an_invitation_from_somebody_who_lost_the_rights_grants_only_the_default_role(): void
    {
        $issued = Teams::invite($this->team, 'late@example.com', 'admin', [], $this->admin);
        Teams::changeRole($this->team, $this->admin, 'member', $this->owner);

        $membership = Teams::acceptInvitation($issued->token, $this->makeUser('late@example.com'));

        $this->assertSame('member', $membership->role);
    }

    #[Test]
    public function an_invitation_from_somebody_who_left_grants_only_the_default_role(): void
    {
        $issued = Teams::invite($this->team, 'late@example.com', 'admin', [], $this->admin);
        Teams::leave($this->team, $this->admin);

        $this->assertSame('member', Teams::acceptInvitation($issued->token, $this->makeUser('late@example.com'))->role);
    }

    #[Test]
    public function an_invitation_from_the_system_keeps_its_role(): void
    {
        $issued = Teams::invite($this->team, 'cp@example.com', 'admin');

        $this->assertSame('admin', Teams::acceptInvitation($issued->token, $this->makeUser('cp@example.com'))->role);
    }

    #[Test]
    public function an_import_without_uuid_cannot_take_a_foreign_id(): void
    {
        $this->assertSame('refused:import_collision', $this->outcome(fn () => Teams::import(['name' => 'Fremd', 'uuid' => null, 'id' => $this->team->id])));
    }

    #[Test]
    public function an_import_cannot_pull_in_another_teams_invitation(): void
    {
        $issued = Teams::invite($this->team, 'inv@example.com', 'member', [], $this->owner);

        $this->assertSame('refused:import_collision', $this->outcome(fn () => Teams::import(['name' => 'Andere', 'invitations' => [['email' => 'inv@example.com', 'token' => $issued->token]]])));

        $this->assertSame($this->team->id, Invitation::query()->where('token_hash', Invitation::hashToken($issued->token))->value('team_id'));
        $this->assertFalse(Team::query()->where('name', 'Andere')->exists(), 'The failed import left nothing behind.');
    }

    #[Test]
    public function an_import_with_id_only_is_idempotent(): void
    {
        $data = ['name' => 'Neu', 'id' => 900];

        $first = Teams::import($data);
        $second = Teams::import($data);

        $this->assertSame($first->uuid, $second->uuid);
        $this->assertSame(1, Team::query()->where('id', 900)->count());
    }

    #[Test]
    public function expired_and_undated_invitations_do_not_come_back_open_forever(): void
    {
        $team = Teams::import(['name' => 'Alt', 'invitations' => [
            ['email' => 'old@example.com', 'token' => 'EXPIREDTOKEN123456789', 'status' => 'expired', 'created_at' => '2024-01-01 00:00:00'],
            ['email' => 'a@example.com', 'token' => 'ACCEPTEDTOKEN12345678', 'status' => 'Accepted'],
            ['email' => 'p@example.com', 'token' => 'PENDINGTOKEN123456789', 'status' => 'pending'],
        ]]);

        $invitations = $team->invitations()->get()->keyBy('email');

        $this->assertSame(Invitation::STATUS_EXPIRED, $invitations['old@example.com']->status());
        $this->assertSame(Invitation::STATUS_ACCEPTED, $invitations['a@example.com']->status());
        $this->assertSame(Invitation::STATUS_PENDING, $invitations['p@example.com']->status());
        $this->assertNotNull($invitations['p@example.com']->expires_at, 'A pending invitation gets the standard lifetime, not none.');
    }

    #[Test]
    public function a_transfer_racing_the_targets_leave_keeps_an_owner(): void
    {
        $target = $this->admin;
        $fired = false;

        // A concurrent request that commits the target's leave right after
        // the transfer first read the target's row, before its transaction.
        Membership::retrieved(function (Membership $m) use (&$fired, $target) {
            if (! $fired && $m->user_id === (string) $target->id()) {
                $fired = true;
                Membership::query()->whereKey($m->id)->delete();
            }
        });

        $outcome = $this->outcome(fn () => Teams::transferOwnership($this->team, $target, $this->owner));

        $this->assertSame('refused:not_member', $outcome);
        $this->assertSame('owner', $this->team->roleOf($this->owner));
        $this->assertSame((string) $this->owner->id(), $this->team->fresh()->owner_id);
    }

    #[Test]
    public function two_owners_cannot_demote_each_other_to_none(): void
    {
        $second = $this->makeUser('second@example.com');
        Teams::addMember($this->team, $second, 'owner', [], $this->owner);
        Teams::changeRole($this->team, $second, 'admin', $this->owner);

        $this->assertSame('refused:last_owner', $this->outcome(fn () => Teams::changeRole($this->team, $this->owner, 'admin', $this->owner)));
    }
}
