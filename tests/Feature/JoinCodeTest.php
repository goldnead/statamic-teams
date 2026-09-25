<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class JoinCodeTest extends TestCase
{
    #[Test]
    public function joining_by_code_ignores_case_spaces_and_dashes(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => Team::JOIN_CODE]);
        $bob = $this->makeUser('bob@example.com');
        $typed = strtolower(substr($team->join_code, 0, 5)).' - '.substr($team->join_code, 5);

        $membership = Teams::joinByCode($typed, $bob);

        $this->assertSame('member', $membership->role);
        $this->assertTrue($team->hasMember($bob));
    }

    #[Test]
    public function a_team_without_joining_by_code_refuses_its_old_code(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => Team::JOIN_CODE]);
        $code = $team->join_code;
        Teams::update($team, ['join_method' => Team::JOIN_INVITATION_ONLY]);

        $this->expectExceptionObject(TeamsException::because(TeamsException::JOIN_DISABLED));
        Teams::joinByCode($code, $this->makeUser('bob@example.com'));
    }

    #[Test]
    public function an_unknown_code_is_refused(): void
    {
        $this->expectExceptionObject(TeamsException::because(TeamsException::JOIN_CODE_INVALID));

        Teams::joinByCode('NICHTDA234', $this->makeUser('bob@example.com'));
    }

    #[Test]
    public function a_regenerated_code_retires_the_old_one(): void
    {
        $team = Teams::create('Chor', $this->makeUser('owner@example.com'), ['join_method' => Team::JOIN_CODE]);
        $old = $team->join_code;

        $new = Teams::regenerateJoinCode($team);

        $this->assertNotSame($old, $new);
        $this->expectExceptionObject(TeamsException::because(TeamsException::JOIN_CODE_INVALID));
        Teams::joinByCode($old, $this->makeUser('bob@example.com'));
    }

    #[Test]
    public function joining_twice_is_refused(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $team = Teams::create('Chor', $owner, ['join_method' => Team::JOIN_CODE]);

        $this->expectExceptionObject(TeamsException::because(TeamsException::ALREADY_MEMBER));
        Teams::joinByCode($team->join_code, $owner);
    }
}
