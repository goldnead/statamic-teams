<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\Entitlements\TeamEntitlements;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\Test;

require_once __DIR__.'/../Fakes/entitlements.php';

class EntitlementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Entitlements::$grants = [];
    }

    #[Test]
    public function a_team_is_a_subject_under_the_team_alias(): void
    {
        $team = Teams::create('Chor');

        $subject = Teams::entitlementSubject($team);

        $this->assertInstanceOf(SubjectReference::class, $subject);
        $this->assertSame('team', $subject->type);
        $this->assertSame((string) $team->id, $subject->id);
        $this->assertSame(Team::class, Relation::getMorphedModel('team'));
        $this->assertSame('team', $team->getMorphClass());
    }

    #[Test]
    public function access_granted_to_the_team_holds_for_every_member_and_ends_when_they_leave(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $bob = $this->makeUser('bob@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $bob);
        Entitlements::grant(Teams::entitlementSubject($team), 'chortarif');

        $this->assertTrue(Teams::allows($owner, 'chortarif'));
        $this->assertTrue(Teams::allows($bob, 'chortarif'));
        $this->assertFalse(Teams::allows($this->makeUser('stranger@example.com'), 'chortarif'));

        Teams::leave($team, $bob);

        $this->assertFalse(Teams::allows($bob, 'chortarif'));
        $this->assertTrue(Teams::allows($owner, 'chortarif'));
    }

    #[Test]
    public function personal_access_counts_when_the_host_names_the_user_subject(): void
    {
        $anna = $this->makeUser('anna@example.com');
        $personal = new SubjectReference('user', (string) $anna->id());
        Entitlements::grant($personal, 'lifetime');

        $this->assertTrue(Teams::allows($anna, 'lifetime', $personal));
        $this->assertFalse(Teams::allows($anna, 'lifetime'));
    }

    #[Test]
    public function the_subjects_of_a_user_are_one_per_team(): void
    {
        $bob = $this->makeUser('bob@example.com');
        $a = Teams::create('A', $bob);
        $b = Teams::create('B', $bob);

        $keys = array_map(fn ($s) => $s->key(), Teams::entitlementSubjectsFor($bob));
        sort($keys);

        $this->assertSame(['team:'.$a->id, 'team:'.$b->id], $keys);
    }

    #[Test]
    public function related_subjects_expand_a_user_reference_into_its_teams(): void
    {
        $bob = $this->makeUser('bob@example.com');
        $team = Teams::create('A', $bob);

        $related = app(TeamEntitlements::class)->relatedSubjects(new SubjectReference('user', (string) $bob->id()));
        $this->assertSame(['team:'.$team->id], array_map(fn ($s) => $s->key(), $related));

        $this->assertSame([], app(TeamEntitlements::class)->relatedSubjects(new SubjectReference('email', 'bob@example.com')));
    }

    #[Test]
    public function products_through_teams_are_listed_once(): void
    {
        $bob = $this->makeUser('bob@example.com');
        $a = Teams::create('A', $bob);
        $b = Teams::create('B', $bob);
        Entitlements::grant(Teams::entitlementSubject($a), 'chortarif');
        Entitlements::grant(Teams::entitlementSubject($b), 'chortarif');
        Entitlements::grant(Teams::entitlementSubject($b), 'archiv');

        $this->assertSame(['chortarif', 'archiv'], app(TeamEntitlements::class)->productsThroughTeams($bob));
    }
}
