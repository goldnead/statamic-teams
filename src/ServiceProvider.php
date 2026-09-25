<?php

namespace Goldnead\Teams;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Teams\Exceptions\TeamsException;
use Goldnead\Teams\Http\Middleware\EnsureTeamMembership;
use Goldnead\Teams\Http\Middleware\EnsureTeamWritable;
use Goldnead\Teams\Integrations\Automations\AutomationsBridge;
use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Teams\Integrations\EmailTemplates\TeamsTemplateSource;
use Goldnead\Teams\Integrations\Entitlements\TeamEntitlements;
use Goldnead\Teams\Integrations\Payments\TeamBuyer;
use Goldnead\Teams\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Services\Authorizer;
use Goldnead\Teams\Services\ImportService;
use Goldnead\Teams\Services\InvitationService;
use Goldnead\Teams\Services\JoinService;
use Goldnead\Teams\Services\MembershipService;
use Goldnead\Teams\Services\TeamService;
use Goldnead\Teams\Support\CurrentTeam;
use Goldnead\Teams\Support\JoinCodes;
use Goldnead\Teams\Support\JoinGuards;
use Goldnead\Teams\Support\Roles;
use Goldnead\Teams\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Events\UserRegistered;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        // The invitation page. The route switches in both files are read at
        // boot, so they are config only, not on the settings page.
        'web' => __DIR__.'/../routes/web.php',
        // Front-end form posts under /!/statamic-teams/…
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    // Registered by hand in register() under the short `teams` namespace,
    // plus the JSON path the Vue pages' `__()` resolves through.
    protected $translations = false;

    protected $config = false;

    protected $viewNamespace = 'teams';

    /**
     * Must byte-match `laravel()` in vite.config.js.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/teams.php', 'teams');

        $langPath = __DIR__.'/../lang';

        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('teams', $langPath);
            $translator->addJsonPath($langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('teams', $langPath);
            $this->app['translator']->addJsonPath($langPath);
        }

        foreach ([
            Roles::class, JoinCodes::class, JoinGuards::class, Authorizer::class,
            TeamService::class, MembershipService::class, InvitationService::class,
            JoinService::class, ImportService::class, TeamsManager::class,
            TeamEntitlements::class, TeamBuyer::class, MailTemplates::class,
            AutomationsBridge::class, WebhookManagerBridge::class,
        ] as $singleton) {
            $this->app->singleton($singleton);
        }

        $this->app->scoped(CurrentTeam::class);
    }

    /**
     * Settings are announced in `boot()`, not `bootAddon()`: brand-context
     * applies stored values from an `app->booted()` callback, and
     * `bootAddon()` runs from one too, in package order.
     */
    public function boot(): void
    {
        parent::boot();

        // `::class` without a leading backslash: the container keys the
        // singleton by exactly that string, and `make('\Goldnead\…')` would
        // build a second, empty registry that nobody reads.
        if (class_exists(SettingsRegistry::class)) {
            $this->app->make(SettingsRegistry::class)->register(Settings::class);
        }

        $this->bootMailTemplates();
    }

    /**
     * The mails as templates in email-templates. With its registry (the
     * current way) each mail is announced with occasion, event and
     * placeholders, and `email-templates:import` takes the defaults from
     * there. Only an older email-templates without registry gets the tagged
     * import source; both at once would offer every default twice.
     *
     * In `boot()`: every provider's `register()` has run, so the registry
     * binding is there if the package is.
     */
    protected function bootMailTemplates(): void
    {
        if ($this->app->make(MailTemplates::class)->registerWithRegistry()) {
            return;
        }

        if (interface_exists('Goldnead\EmailTemplates\Contracts\EmailTemplateSource')) {
            $this->app->tag([TeamsTemplateSource::class], 'email-templates.sources');
        }
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'teams');

        $this
            ->bootMorphAlias()
            ->bootMiddleware()
            ->bootExceptionRendering()
            ->bootRateLimits()
            ->bootNav()
            ->bootPermissions()
            ->bootBridges()
            ->bootPersonalTeams()
            ->bootPublishables();
    }

    /**
     * `team` in polymorphic columns (entitlements, activity), instead of the
     * class name. Not taken when the host already maps `team` to something
     * else: that alias is theirs.
     */
    protected function bootMorphAlias(): self
    {
        $existing = Relation::getMorphedModel(Team::MORPH_ALIAS);

        if ($existing === null) {
            Relation::morphMap([Team::MORPH_ALIAS => Team::class]);
        }

        return $this;
    }

    protected function bootMiddleware(): self
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('teams.current', EnsureTeamMembership::class);
        $router->aliasMiddleware('teams.writable', EnsureTeamWritable::class);

        return $this;
    }

    /**
     * A refusal that escapes to the framework (`Teams::currentOrFail()` in a
     * host controller) answers with its status and reason, not a 500.
     */
    protected function bootExceptionRendering(): self
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(function (TeamsException $e, $request) {
                return $request->expectsJson()
                    ? response()->json($e->toArray(), $e->status())
                    : abort($e->status(), $e->getMessage());
            });
        }

        return $this;
    }

    /**
     * `throttle:teams-join`: per account and per address, read when the
     * limiter runs, so the numbers can be changed without a deploy.
     */
    protected function bootRateLimits(): self
    {
        RateLimiter::for('teams-join', function (Request $request) {
            $perUser = max(1, (int) config('teams.routes.join_limits.per_user', 10));
            $perIp = max(1, (int) config('teams.routes.join_limits.per_ip', 30));

            return [
                Limit::perHour($perUser)->by('teams-join:user:'.($request->user()?->getAuthIdentifier() ?? 'guest:'.$request->ip())),
                Limit::perHour($perIp)->by('teams-join:ip:'.$request->ip()),
            ];
        });

        return $this;
    }

    protected function bootNav(): self
    {
        Nav::extend(function ($nav) {
            $nav->create(__('teams::messages.nav'))
                ->section('Users')
                ->icon('users')
                ->route('teams.index')
                ->can('view teams')
                ->children([
                    $nav->item(__('teams::messages.nav_wiring'))
                        ->route('teams.wiring')
                        ->can('view teams'),
                ]);
        });

        return $this;
    }

    protected function bootPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('teams', __('teams::messages.permission_group'), function () {
                Permission::register('view teams')
                    ->label(__('teams::messages.permission_view'))
                    ->children([
                        Permission::make('manage teams')
                            ->label(__('teams::messages.permission_manage')),
                    ]);

                Permission::register('manage teams settings')
                    ->label(__('teams::messages.permission_settings'));
            });
        });

        return $this;
    }

    /**
     * From a booted callback: the siblings' bindings exist only once their
     * providers booted, and this one may boot first. Both bridges are
     * idempotent.
     */
    protected function bootBridges(): self
    {
        $register = function (): void {
            $this->app->make(AutomationsBridge::class)->register();
            $this->app->make(WebhookManagerBridge::class)->boot($this->app['events']);
        };

        $this->app->booted(function () use ($register): void {
            $register();

            $this->app->booted($register);
        });

        return $this;
    }

    protected function bootPersonalTeams(): self
    {
        $this->app['events']->listen(UserRegistered::class, function (UserRegistered $event): void {
            if (config('teams.personal.create_on_registration', false)) {
                $this->app->make(TeamService::class)->personalTeam($event->user);
            }
        });

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/teams.php' => config_path('teams.php'),
        ], 'teams-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/teams'),
        ], 'teams-views');

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/teams'),
        ], 'teams-translations');

        return $this;
    }
}
