<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\EventCatalog;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class ImportTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function choir(string $ownerId, string $memberId): array
    {
        return [
            'id' => 42,
            'uuid' => '0b8a1c7e-2f7e-4a0e-9a54-0d1f5f7f2a11',
            'name' => 'Kammerchor Köln',
            'type' => 'team',
            'join_code' => 'kammer24',
            'settings' => ['read_only' => false, 'language' => 'de'],
            'created_at' => '2025-12-04 10:00:00',
            'roles' => [
                ['handle' => 'section_leader', 'label' => 'Stimmführung', 'permissions' => ['invite members']],
            ],
            'members' => [
                ['user_id' => $ownerId, 'role' => 'owner', 'is_current' => true],
                ['user_id' => $memberId, 'role' => 'section_leader', 'meta' => ['voice_part' => 'alto']],
            ],
            'invitations' => [
                ['email' => 'neu@example.com', 'role' => 'member', 'token' => 'altertokenausdermail', 'expires_at' => now()->addDays(3)->toDateTimeString()],
            ],
        ];
    }

    #[Test]
    public function a_team_keeps_its_id_uuid_code_roles_and_members(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $alto = $this->makeUser('alto@example.com');
        Event::fake(array_keys(EventCatalog::EVENTS));

        $team = Teams::import($this->choir((string) $owner->id(), (string) $alto->id()));

        $this->assertSame(42, $team->id);
        $this->assertSame('0b8a1c7e-2f7e-4a0e-9a54-0d1f5f7f2a11', $team->uuid);
        $this->assertSame('KAMMER24', $team->join_code);
        $this->assertSame(Team::JOIN_CODE, $team->join_method);
        $this->assertSame((string) $owner->id(), $team->owner_id);
        $this->assertSame('2025-12-04', $team->created_at->toDateString());
        $this->assertSame('section_leader', $team->roleOf($alto));
        $this->assertSame(['voice_part' => 'alto'], $team->membershipOf($alto)->meta);
        $this->assertTrue(Teams::can($alto, $team, 'invite members'));
        $this->assertTrue(Teams::current($owner)->is($team));

        foreach (EventCatalog::EVENTS as $class => $meta) {
            Event::assertNotDispatched($class);
        }
    }

    #[Test]
    public function importing_twice_updates_instead_of_duplicating(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $alto = $this->makeUser('alto@example.com');
        $data = $this->choir((string) $owner->id(), (string) $alto->id());

        Teams::import($data);
        $data['name'] = 'Kammerchor Köln e.V.';
        Teams::import($data);

        $this->assertSame(1, Team::query()->count());
        $this->assertSame('Kammerchor Köln e.V.', Team::query()->find(42)->name);
        $this->assertSame(2, Team::query()->find(42)->members()->count());
        $this->assertSame(1, Invitation::query()->count());
    }

    #[Test]
    public function the_old_join_code_and_the_old_invitation_link_keep_working(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $alto = $this->makeUser('alto@example.com');
        Teams::import($this->choir((string) $owner->id(), (string) $alto->id()));

        Teams::joinByCode('Kammer24', $this->makeUser('bass@example.com'));
        $membership = Teams::acceptInvitation('altertokenausdermail', $this->makeUser('neu@example.com'));

        $this->assertSame(42, $membership->team_id);
        $this->assertSame(4, Team::query()->find(42)->members()->count());
    }

    #[Test]
    public function members_can_be_matched_by_email(): void
    {
        $owner = $this->makeUser('owner@example.com');

        $team = Teams::import(['name' => 'Chor', 'members' => [['email' => 'owner@example.com', 'role' => 'owner']]]);

        $this->assertTrue($team->hasMember($owner));
        $this->assertSame((string) $owner->id(), $team->owner_id);
    }

    #[Test]
    public function an_unknown_role_stops_the_import_and_leaves_nothing_behind(): void
    {
        $owner = $this->makeUser('owner@example.com');

        try {
            Teams::import(['name' => 'Chor', 'uuid' => '0b8a1c7e-2f7e-4a0e-9a54-0d1f5f7f2a12', 'members' => [['user_id' => (string) $owner->id(), 'role' => 'dirigent']]]);
            $this->fail('An unknown role was imported.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::UNKNOWN_ROLE, $e->reason);
        }

        $this->assertSame(0, Team::query()->count());
    }

    #[Test]
    public function the_command_imports_a_json_file(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $alto = $this->makeUser('alto@example.com');
        $file = tempnam(sys_get_temp_dir(), 'teams').'.json';
        file_put_contents($file, json_encode([$this->choir((string) $owner->id(), (string) $alto->id())]));

        $this->artisan('teams:import', ['file' => $file])
            ->expectsOutputToContain('Kammerchor Köln')
            ->assertSuccessful();

        $this->assertSame(1, Team::query()->count());
        @unlink($file);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $alto = $this->makeUser('alto@example.com');
        $file = tempnam(sys_get_temp_dir(), 'teams').'.json';
        file_put_contents($file, json_encode([$this->choir((string) $owner->id(), (string) $alto->id())]));

        $this->artisan('teams:import', ['file' => $file, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Team::query()->count());
        @unlink($file);
    }
}
