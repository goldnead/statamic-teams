<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\ServiceProvider;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Statamic\Providers\AddonServiceProvider;

class ProviderTest extends TestCase
{
    /**
     * Statamic's boot chain calls these by name. A method of the same name
     * in the addon replaces core's step instead of adding one.
     */
    #[Test]
    public function the_provider_does_not_replace_statamics_boot_steps(): void
    {
        foreach (['bootPublishables', 'bootMiddleware', 'bootVite', 'bootRoutes', 'bootViews', 'bootConfig', 'bootTranslations'] as $method) {
            $this->assertSame(
                AddonServiceProvider::class,
                (new ReflectionMethod(ServiceProvider::class, $method))->getDeclaringClass()->getName(),
                "ServiceProvider::{$method}() overrides Statamic's step.",
            );
        }
    }

    #[Test]
    public function publishing_under_the_addon_tag_writes_the_cp_bundle(): void
    {
        $target = public_path('vendor/statamic-teams');
        File::deleteDirectory($target);

        $this->artisan('vendor:publish', ['--tag' => 'statamic-teams', '--force' => true])->assertSuccessful();

        $this->assertFileExists($target.'/build/manifest.json');

        File::deleteDirectory($target);
    }

    #[Test]
    public function the_config_views_and_translations_publish_under_their_tags(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'teams-config', '--force' => true])->assertSuccessful();
        $this->assertFileExists(config_path('teams.php'));
        File::delete(config_path('teams.php'));
    }
}
