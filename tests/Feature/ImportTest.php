<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Invitation;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\ImportService;
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
    public function a_fixed_id_held_by_another_team_stops_the_import(): void
    {
        $existing = Teams::create('Fremder Chor');
        $data = ['id' => $existing->id, 'uuid' => '0b8a1c7e-2f7e-4a0e-9a54-0d1f5f7f2a99', 'name' => 'Überschreiber'];

        try {
            Teams::import($data);
            $this->fail('The import overwrote another team.');
        } catch (TeamsException $e) {
            $this->assertSame(TeamsException::IMPORT_COLLISION, $e->reason);
        }

        $this->assertSame('Fremder Chor', $existing->fresh()->name);
        $this->assertSame($existing->uuid, $existing->fresh()->uuid);
    }

    #[Test]
    public function a_dry_run_lists_every_collision(): void
    {
        $existing = Teams::create('Fremder Chor');
        $file = tempnam(sys_get_temp_dir(), 'teams').'.json';
        file_put_contents($file, json_encode([
            ['id' => $existing->id, 'uuid' => '0b8a1c7e-2f7e-4a0e-9a54-0d1f5f7f2a99', 'name' => 'Überschreiber'],
            ['name' => 'Harmlos'],
        ]));

        $this->artisan('teams:import', ['file' => $file, '--dry-run' => true])
            ->expectsOutputToContain('Überschreiber: '.__('teams::messages.errors.import_collision'))
            ->expectsOutputToContain('Harmlos')
            ->assertFailed();

        $this->assertSame(1, Team::query()->count());
        @unlink($file);
    }

    #[Test]
    public function the_status_of_old_invitations_is_kept(): void
    {
        $team = Teams::import(['name' => 'Chor', 'invitations' => [
            ['email' => 'a@example.com', 'token' => 'accepted-without-date', 'status' => 'accepted', 'updated_at' => '2026-01-10 09:00:00', 'expires_at' => now()->addDays(3)->toDateTimeString()],
            ['email' => 'b@example.com', 'token' => 'declined-token', 'status' => 'declined', 'expires_at' => now()->addDays(3)->toDateTimeString()],
            ['email' => 'c@example.com', 'token' => 'revoked-token', 'status' => 'revoked', 'expires_at' => now()->addDays(3)->toDateTimeString()],
            ['email' => 'd@example.com', 'token' => 'pending-token', 'status' => 'pending', 'expires_at' => now()->addDays(3)->toDateTimeString()],
        ]]);

        $status = $team->invitations()->get()->mapWithKeys(fn (Invitation $i) => [$i->email => $i->status()])->all();

        $this->assertSame([
            'a@example.com' => Invitation::STATUS_ACCEPTED,
            'b@example.com' => Invitation::STATUS_REVOKED,
            'c@example.com' => Invitation::STATUS_REVOKED,
            'd@example.com' => Invitation::STATUS_PENDING,
        ], $status);
        $this->assertSame('2026-01-10', Invitation::query()->where('email', 'a@example.com')->first()->accepted_at->toDateString());

        $this->expectExceptionObject(TeamsException::because(TeamsException::INVITATION_USED));
        Teams::acceptInvitation('accepted-without-date', $this->makeUser('a@example.com'));
    }

    #[Test]
    public function an_unknown_join_method_is_reported_and_falls_back_to_invitations(): void
    {
        $importer = app(ImportService::class);

        $team = $importer->import(['name' => 'Chor', 'join_method' => 'join_request', 'join_code' => 'ABCDEFGH23']);

        $this->assertSame(Team::JOIN_INVITATION_ONLY, $team->join_method);
        $this->assertSame('ABCDEFGH23', $team->join_code, 'The code is kept, so it can be switched back on.');
        $this->assertStringContainsString('join_request', implode("\n", $importer->warnings()));
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
