<?php

namespace Goldnead\StatamicFunnels;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Goldnead\StatamicFunnels\Http\Controllers\Cp\FunnelActionsController;
use Goldnead\StatamicFunnels\Http\Controllers\Cp\FunnelsController;
use Goldnead\StatamicFunnels\Integrations\Insights\Completed;
use Goldnead\StatamicFunnels\Integrations\Insights\CompletionRate;
use Goldnead\StatamicFunnels\Integrations\Insights\StepEvents;
use Goldnead\StatamicFunnels\Integrations\Insights\Visits;
use Goldnead\StatamicFunnels\Integrations\LeadHubBridge;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\Settings;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Goldnead\StatamicPayments\Cp\SuiteNav;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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

        // Decided once per process, and lazily: the search for a browser is a
        // handful of stats, but there is no reason to pay for it on a request
        // that never renders anything. A test swaps the binding for a double.
        $this->app->singleton(ThumbnailRenderer::class, fn () => Thumbnails::detectRenderer());
    }

    /**
     * In `boot()`, nicht in `bootAddon()`, und das ist keine Stilfrage.
     *
     * brand-context legt die gespeicherten Werte aus einem `app->booted()`
     * auf die Config, absichtlich erst dann, damit jedes Provider-`boot()`
     * seine Anmeldung hinter sich hat. `bootAddon()` läuft selbst aus einem
     * `app->booted()` (Statamics AddonServiceProvider), und welches der beiden
     * zuerst feuert, hängt an der Ladereihenfolge der Pakete: eine Anmeldung
     * von dort wirkte auf manchen Installationen und auf anderen nicht.
     */
    public function boot(): void
    {
        parent::boot();

        app(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-funnels');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-funnels');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootUtility()->bootCookie()->bootPermissions();

        $this->registerInsightsMetrics();

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
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Visits::class => 'funnels.visits',
        Completed::class => 'funnels.completed',
        CompletionRate::class => 'funnels.completion_rate',
        StepEvents::class => 'funnels.step_events',
    ];

    /**
     * Offer the funnel figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than from `bootAddon()` directly:
     * the sibling's container bindings only exist once its own provider has
     * booted, and this one may boot first. Registering earlier registers into
     * nothing, silently — an empty screen with no error anywhere, which is the
     * worst shape this failure could take.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * a funnel a visitor is standing in. The guards are three, and each one has
     * caught a real variation of "installed but not quite": the class may be
     * absent, the container may refuse to build the manager, and an older
     * release of the sibling may have the facade without this method on it.
     *
     * The `method_exists` probe is asked of the object and never of the facade.
     * A facade forwards through `__callStatic` and declares none of what it
     * forwards, so the probe on the facade itself is always false — the same
     * rule {@see LeadHubBridge} follows one door down.
     *
     * The metric classes name the sibling's contract in their `implements` and
     * their parent class, which is safe precisely because of the first guard:
     * PHP loads a class when something touches it, and nothing touches these
     * unless the facade exists. Hence `suggest` in composer.json rather than
     * `require` — installing this addon alone must not drag an analytics
     * package in.
     */
    protected function registerInsightsMetrics(): void
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-funnels: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
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

    /**
     * Das Recht, das den Abschnitt dieses Addons auf der geteilten
     * Einstellungsseite freigibt.
     *
     * Neu vergeben, nicht umbenannt: dieses Addon hatte bisher kein eigenes
     * Recht. Ohne die Registrierung hier ließe es sich in keiner
     * Benutzergruppe vergeben und nur ein Super-Admin käme an die Seite.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('statamic-funnels', __('statamic-funnels::settings.permission_group'), function (): void {
                Permission::register('manage funnels settings')
                    ->label(__('statamic-funnels::settings.permission_manage'));
            });
        });

        return $this;
    }

    protected function bootUtility(): self
    {
        // Inside `Utility::extend`: `__()` during boot resolves before core's
        // `Localize` middleware has set the user's language, so the nav entry
        // would freeze in the application locale.
        Utility::extend(fn () => $this->registerUtility());

        // Der Bildschirm bleibt eine Utility — dieselbe Route, dasselbe Recht.
        // Was fehlte, war der Weg dorthin: unter „Hilfsmittel" steht er
        // zwischen Cache und PHP-Info, und genau das hat Adrian am 03.09.2026
        // als verwirrend gemeldet. Der Abschnittsname kommt aus
        // `statamic-payments`, an dem dieses Addon ohnehin haengt — Statamic
        // uebersetzt Abschnittsnamen nicht, zwei Schreibweisen ergaeben zwei
        // halb gefuellte Abschnitte nebeneinander.
        Nav::extend(function ($nav) {
            // Erst aushaengen, dann einhaengen — sonst steht der Bildschirm
            // zweimal da: einmal unter „Hilfsmittel", wohin `Utility::register`
            // ihn haengt, und einmal hier. Die Registrierung bleibt, sie traegt
            // Route, Recht und Middleware.
            $nav->remove('Tools', 'Utilities', __('statamic-funnels::messages.utility_nav'));

            // `SuiteNav` gibt es erst seit payments 1.18.0, der Constraint
            // erlaubt aelter. Ohne Wache faellt die ganze CP-Navigation mit
            // "Class not found"; mit ihr bekommt eine Installation mit
            // aelterem payments einen eigenen Abschnitt, wie booking es macht.
            $section = class_exists(SuiteNav::class)
                ? SuiteNav::section()
                : __('statamic-funnels::messages.utility_nav');

            $nav->create(__('statamic-funnels::messages.utility_nav'))
                ->section($section)
                ->icon('hierarchy-vertical-nav-flow')
                ->route('utilities.funnels')
                ->can('access funnels utility');
        });

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
                // What the listing's row menu and its bulk toolbar post to.
                // Above `{funnel}` on purpose, so `actions` is never read as a
                // funnel id.
                $router->post('actions', [FunnelActionsController::class, 'run'])->name('actions');
                $router->post('actions/list', [FunnelActionsController::class, 'bulkActions'])->name('actions.list');
                $router->post('{funnel}/preview', [FunnelsController::class, 'preview'])->name('preview');
                $router->get('{funnel}/edit', [FunnelsController::class, 'edit'])->name('edit');
                $router->patch('{funnel}', [FunnelsController::class, 'update'])->name('update');
            });
    }
}
