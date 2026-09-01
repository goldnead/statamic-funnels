<?php

namespace Goldnead\StatamicFunnels\Tests;

use Goldnead\StatamicFunnels\ServiceProvider;
use Goldnead\StatamicFunnels\Tests\Support\FakeGateway;
use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Support\Catalogue;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected FakeGateway $gateway;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            \Goldnead\StatamicPayments\ServiceProvider::class,
            \Goldnead\StatamicOffers\ServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('statamic.system.multisite', false);

        // Wie in jeder Statamic-Installation: der Benutzer-Provider ist
        // Statamics eigener, nicht Eloquent. Testbench bringt die Laravel-
        // Vorgabe mit, und `Auth::login()` fiele dann auf eine `users`-Tabelle,
        // die es hier nicht gibt.
        $app['config']->set('auth.providers.users.driver', 'statamic');
        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Kurs', 'amount_cent' => 9900],
            'begleit-cd' => ['name' => 'Begleit-CD', 'amount_cent' => 2900],
        ]);
    }

    protected function defineRoutes($router): void
    {
        // The sibling addons are plain packages here, so their routes are not
        // registered and the checkout cannot build the webhook URL it hands the
        // provider. Only the name is needed.
        $router->post('/!/statamic-payments/webhook', fn () => response()->json(['received' => true]))
            ->name('statamic-payments.webhook');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-payments/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/goldnead/statamic-offers/database/migrations');

        // A fake provider, because a test that needs the network is a test that
        // gets skipped.
        $this->gateway = new FakeGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        Catalogue::forgetResolvers();

        parent::tearDown();
    }
}
