<?php

namespace Goldnead\Teams\Tests;

use Goldnead\Teams\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;

/**
 * The suite runs with brand-context (a dev dependency, for the settings
 * page) and no other sibling. Automations, webhook-manager, entitlements,
 * payments, activity and email-templates are stand-ins under `tests/Fakes/`,
 * loaded by the tests that need a bridge to fire.
 *
 * Users come from Statamic's file repository, keyed by UUID. The Eloquent
 * case (integer ids) is covered in `EloquentUsersTest`.
 */
abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getPackageProviders($app): array
    {
        return [
            \Goldnead\BrandContext\ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);

        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('mail.default', 'array');
    }

    protected function tearDown(): void
    {
        foreach (glob(__DIR__.'/__fixtures__/users/*.yaml') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function makeUser(string $email, ?string $name = null): UserContract
    {
        $user = User::make()->email($email)->data(['name' => $name ?? ucfirst(strstr($email, '@', true) ?: $email)]);
        $user->save();

        return $user;
    }

    /**
     * Sign in as a CP user holding exactly the listed permissions.
     *
     * @param  list<string>  $permissions
     */
    protected function actingAsCpUser(string $email, array $permissions = []): static
    {
        $allowed = array_merge(['access cp'], $permissions);

        Gate::before(fn ($user, $ability) => in_array($ability, $allowed, true) ? true : null);

        return $this->actingAs($this->makeUser($email));
    }
}
