<?php

namespace Goldnead\StatamicFunnels;

use Goldnead\StatamicFunnels\Http\Controllers\Cp\FunnelsController;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-funnels';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-funnels.php', 'statamic-funnels');

        $this->app->singleton(StepRegistry::class);
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-funnels');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-funnels');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootUtility()->bootCookie();

        $this->publishes([
            __DIR__.'/../config/statamic-funnels.php' => config_path('statamic-funnels.php'),
        ], 'statamic-funnels-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-funnels-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-funnels'),
        ], 'statamic-funnels-views');

        // The front-end stylesheet, published like any other addon asset so it
        // is served from the host's own domain rather than inlined into every
        // page.
        $this->publishes([
            __DIR__.'/../resources/dist-public' => public_path('vendor/statamic-funnels'),
        ], 'statamic-funnels');
    }

    /**
     * The walk token is not encrypted, deliberately.
     *
     * It is an opaque random string that identifies a walk, not a person, and
     * nothing about it needs hiding. What it *does* need is to survive the
     * round trip, and Laravel's cookie encryption silently discards a cookie it
     * cannot decrypt — which is what happens the moment anything other than
     * this application writes it, and what cost the consent addon a whole
     * release before somebody noticed the log was empty.
     */
    protected function bootCookie(): self
    {
        EncryptCookies::except(FunnelWalk::COOKIE);

        return $this;
    }

    protected function bootUtility(): self
    {
        // Inside `Utility::extend`: `__()` during boot resolves before core's
        // `Localize` middleware has set the user's language, so the nav entry
        // would freeze in the application locale.
        Utility::extend(fn () => $this->registerUtility());

        return $this;
    }

    protected function registerUtility(): void
    {
        Utility::register('funnels')
            ->action([FunnelsController::class, 'index'])
            ->title(__('statamic-funnels::messages.utility_title'))
            ->navTitle(__('statamic-funnels::messages.utility_nav'))
            ->icon('hierarchy')
            ->description(__('statamic-funnels::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-funnels#readme')
            ->routes(function ($router) {
                $router->post('/', [FunnelsController::class, 'store'])->name('store');
                $router->get('{funnel}/edit', [FunnelsController::class, 'edit'])->name('edit');
                $router->patch('{funnel}', [FunnelsController::class, 'update'])->name('update');
                $router->delete('{funnel}', [FunnelsController::class, 'destroy'])->name('destroy');
            });
    }
}
