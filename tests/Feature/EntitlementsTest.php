<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Integrations\Entitlements\TeamEntitlements;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Against the real goldnead/statamic-entitlements (dev dependency): a team
 * grant, a limit at the team, and bookings counted at the team.
 */
class EntitlementsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('brand-context.multi_brand', false);
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Entitlements loads its migrations in bootAddon(), which Statamic runs
        // only for the addon under test here. Its tables are all this needs.
        $this->loadMigrationsFrom(dirname((string) (new \ReflectionClass(\Goldnead\Entitlements\ServiceProvider::class))->getFileName(), 2).'/database/migrations');
        $this->artisan('migrate')->run();
    }

    protected function userRef(mixed $user): SubjectReference
    {
        return new SubjectReference('user', (string) $user->id());
    }

    #[Test]
    public function a_team_is_a_subject_under_the_team_alias(): void
    {
        $team = Teams::create('Chor');

        $subject = Teams::entitlementSubject($team);

        $this->assertInstanceOf(SubjectReference::class, $subject);
        $this->assertSame('team:'.$team->id, $subject->key());
        $this->assertSame(Team::class, Relation::getMorphedModel('team'));
        $this->assertTrue(SubjectReference::for($team)->equals($subject));
    }

    #[Test]
    public function teams_registers_itself_as_subject_expander(): void
    {
        $owner = $this->makeUser('owner@example.com');
        $bob = $this->makeUser('bob@example.com');
        $team = Teams::create('Chor', $owner);
        Teams::addMember($team, $bob);
        Entitlements::grant($team, 'chortarif', 'manual');

        // Asked about the person, entitlements itself finds the team.
        $this->assertTrue(Entitlements::allows($this->userRef($bob), 'chortarif'));
        $this->assertFalse(Entitlements::allows($this->userRef($this->makeUser('stranger@example.com')), 'chortarif'));

        Teams::leave($team, $bob);

        $this->assertFalse(Entitlements::allows($this->userRef($bob), 'chortarif'));
        $this->assertTrue(Entitlements::allows($this->userRef($owner), 'chortarif'));
    }

    #[Test]
    public function teams_allows_checks_personal_and_team_access(): void
    {
        $anna = $this->makeUser('anna@example.com');
        Entitlements::grant($this->userRef($anna), 'lifetime', 'manual');
        $team = Teams::create('Chor', $anna);
        Entitlements::grant($team, 'chortarif', 'manual');

        $this->assertTrue(Teams::allows($anna, 'lifetime', $this->userRef($anna)));
        $this->assertTrue(Teams::allows($anna, 'chortarif'));
        $this->assertFalse(Teams::allows($anna, 'lifetime'));
    }

    #[Test]
    public function a_limit_at_the_team_is_shared_and_counted_at_the_team(): void
    {
        $olga = $this->makeUser('olga@example.com');
        $ben = $this->makeUser('ben@example.com');
        $team = Teams::create('Chor', $olga);
        Teams::addMember($team, $ben);
        Entitlements::grant($team, 'chortarif', 'manual');
        Entitlements::setLimits('chortarif', ['analyses' => ['value' => 2, 'period' => 'year']]);

        $this->assertSame(2, Entitlements::limit($this->userRef($ben), 'analyses'));
        // consume() returns a receipt on success (entitlements ≥ 8f9be46), null when refused.
        $first = Entitlements::consume($this->userRef($olga), 'analyses');
        $this->assertNotNull($first);
        $this->assertSame('team:'.$team->id, $first->holder()->key(), 'Booked at the team, not at the person.');
        $this->assertNotNull(Entitlements::consume($this->userRef($ben), 'analyses'));
        $this->assertNull(Entitlements::consume($this->userRef($ben), 'analyses'), 'The team counter is full, whoever books.');

        $quota = Entitlements::quota($this->userRef($ben), 'analyses');
        $this->assertSame(0, $quota->remaining());
        $this->assertSame(0, Entitlements::remaining(Teams::entitlementSubject($team), 'analyses'));
    }

    #[Test]
    public function the_user_types_follow_the_eloquent_user_model(): void
    {
        Schema::create('test_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });
        config(['auth.providers.users.model' => TeamsTestUser::class]);

        $model = TeamsTestUser::query()->create(['email' => 'eloquent@example.com']);
        $team = Teams::create('Chor');
        Teams::addMember($team, $model);
        Entitlements::grant($team, 'chortarif', 'manual');

        $this->assertTrue(Entitlements::allows($model, 'chortarif'), 'The model itself, as entitlements resolves it.');
        $this->assertTrue(Entitlements::allows(new SubjectReference(TeamsTestUser::class, (string) $model->id), 'chortarif'));
        $this->assertSame(['team:'.$team->id], array_map(
            fn ($s) => $s->key(),
            app(TeamEntitlements::class)->relatedSubjects(SubjectReference::for($model)),
        ));
    }

    #[Test]
    public function extra_user_types_come_from_config(): void
    {
        config(['teams.entitlements.user_types' => ['member']]);
        $bob = $this->makeUser('bob@example.com');
        $team = Teams::create('Chor', $bob);

        $this->assertCount(1, app(TeamEntitlements::class)->relatedSubjects(new SubjectReference('member', (string) $bob->id())));
        $this->assertSame([], app(TeamEntitlements::class)->relatedSubjects(new SubjectReference('email', 'bob@example.com')));
        $this->assertSame([], app(TeamEntitlements::class)->relatedSubjects(new SubjectReference('team', (string) $team->id)), 'A team does not expand into teams.');
    }
}

class TeamsTestUser extends Authenticatable
{
    protected $table = 'test_users';

    protected $guarded = [];
}
