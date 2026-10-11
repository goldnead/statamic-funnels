<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The step against the real `goldnead/statamic-lead-magnets`.
 *
 * `LeadMagnetStepTest` runs against a stand-in, and a stand-in can do less than
 * the thing it stands for: it has no mail, no entitlements, no signature check
 * of its own. This file is the check that the stand-in's promises are the
 * sibling's. It runs only where the sibling is installed (a checkout beside
 * this package, `composer require --dev` on a developer machine) and skips,
 * saying why, everywhere else, CI included. A skipped file proves nothing, so
 * the pull request that adds the step records that it was run by hand.
 */
class LeadMagnetsRealPackageTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        if (! class_exists('Goldnead\LeadMagnets\Support\ReturnUrl')) {
            $this->markTestSkipped('goldnead/statamic-lead-magnets is not installed here; the stand-in suite is all that runs.');
        }

        parent::setUp();

        $root = dirname((new \ReflectionClass('Goldnead\LeadMagnets\ServiceProvider'))->getFileName(), 2);

        $this->loadMigrationsFrom($root.'/database/migrations');
        $this->loadMigrationsFrom(dirname((new \ReflectionClass('Goldnead\Entitlements\ServiceProvider'))->getFileName()).'/../database/migrations');

        // `AddonServiceProvider` boots only the addon under test, so the
        // sibling's routes, views and words are wired by hand, as in its own suite.
        $this->app['view']->addNamespace('lead-magnets', $root.'/resources/views');
        $this->app['translator']->addNamespace('lead-magnets', $root.'/lang');

        config()->set('app.url', 'http://localhost');
        config()->set('mail.default', 'array');
        config()->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Test']);
        config()->set('lead-magnets.requests.throttle', '10000,1');
        config()->set('filesystems.disks.lead-magnets', ['driver' => 'local', 'root' => storage_path('framework/testing/lead-magnets')]);
        config()->set('lead-magnets.delivery.disk', 'lead-magnets');
    }

    protected function tearDown(): void
    {
        // Skipped before the application was built (see setUp).
        if ($this->app === null) {
            return;
        }

        parent::tearDown();
    }

    /**
     * The sibling's routes, registered while the application boots. Added
     * later they would sit behind Statamic's catch-all and answer 404.
     */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        if (class_exists('Goldnead\LeadMagnets\ServiceProvider')) {
            $root = dirname((new \ReflectionClass('Goldnead\LeadMagnets\ServiceProvider'))->getFileName(), 2);

            $router->middleware('web')->group($root.'/routes/web.php');
        }
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
            \Goldnead\LeadMagnets\ServiceProvider::class,
        ]);
    }

    protected function gift(bool $confirmation): void
    {
        Storage::disk('lead-magnets')->put('einsing.pdf', 'the file');

        \Goldnead\LeadMagnets\Models\Resource::query()->create([
            'handle' => 'einsing',
            'title' => 'Einsing-Freebie',
            'delivery_type' => \Goldnead\LeadMagnets\Models\Resource::TYPE_FILE,
            'file_path' => 'einsing.pdf',
            'requires_confirmation' => $confirmation,
            'published' => true,
        ]);

        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Geschenk', 'slug' => 'geschenk', 'config' => ['resource' => 'einsing']],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'slug' => 'danke', 'config' => ['headline' => 'Danke und Angebot']],
        ]);
        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'lm_1', 'from_output' => 'default'],
            ['from_node_key' => 'lm_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
        ]);
    }

    protected function submit(): void
    {
        $this->asVisitor()->get('/f/kurs');
        $this->asVisitor()->post('/f/kurs/entry_1/advance');
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com', 'newsletter' => '1']);
    }

    /** The plaintext token out of the confirmation mail, like a reader's mail client sees it. */
    protected function tokenFromMail(): string
    {
        foreach (array_reverse(app('mailer')->getSymfonyTransport()->messages()->all()) as $message) {
            $body = quoted_printable_decode($message->getMessage()->toString());

            if (preg_match('#/confirm/([a-f0-9]{64})#', $body, $matches)) {
                return $matches[1];
            }
        }

        $this->fail('no confirmation mail was sent');
    }

    #[Test]
    public function the_step_type_is_registered_and_lists_the_real_resources(): void
    {
        $this->gift(true);

        $registry = new StepRegistry;
        $this->assertTrue($registry->has('lead_magnet'));

        $entry = collect($registry->library()['pages'])->firstWhere('handle', 'lead_magnet');
        $options = collect($entry['schema'])->firstWhere('handle', 'resource')['options'];

        $this->assertSame([['value' => 'einsing', 'label' => 'Einsing-Freebie']], $options);
    }

    #[Test]
    public function the_confirmation_link_leads_back_into_the_funnel(): void
    {
        $this->gift(true);
        $this->submit();

        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk()->assertSee('maria@example.com');

        $grant = Grant::query()->sole();
        $this->assertTrue($grant->isPending());
        $this->assertSame('maria@example.com', $grant->email);

        // The newsletter box travelled with the request.
        $this->assertTrue($grant->meta['newsletter']['opted_in']);

        // lead-magnets kept the funnel's signed link, nothing else.
        $this->assertStringStartsWith('http://localhost/f/kurs/_resume/lm_1/', $grant->meta['return_url']);

        // The click: lead-magnets confirms and sends the reader back, 303.
        $resume = $this->get(route('lead-magnets.confirm', ['token' => $this->tokenFromMail()]));
        $resume->assertStatus(303)->assertRedirect($grant->meta['return_url']);

        // And the funnel carries on from there, in a browser without a cookie.
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');

        $visit = FunnelVisit::query()->sole();
        $this->assertSame(
            [FunnelStepEvent::ENTERED, FunnelStepEvent::LEAD_MAGNET_REQUESTED, FunnelStepEvent::LEAD_MAGNET_CONFIRMED],
            $visit->events()->where('node_key', 'lm_1')->pluck('event')->all(),
        );
    }

    #[Test]
    public function the_return_link_expires_and_a_second_click_changes_nothing(): void
    {
        $this->gift(true);
        $this->submit();
        $this->asVisitor()->get('/f/kurs/geschenk');

        $grant = Grant::query()->sole();

        // The sibling accepted a link with an expiry (its own signature check ran).
        $this->assertStringContainsString('expires=', $grant->meta['return_url']);

        $this->get(route('lead-magnets.confirm', ['token' => $this->tokenFromMail()]))->assertStatus(303);

        $this->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');
        $this->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');

        $this->assertSame(1, FunnelVisit::query()->sole()->events()->where('event', FunnelStepEvent::LEAD_MAGNET_CONFIRMED)->count());
    }

    #[Test]
    public function a_resource_without_double_opt_in_goes_straight_on(): void
    {
        $this->gift(false);
        $this->submit();

        $this->asVisitor()->get('/f/kurs/geschenk')->assertRedirect('/f/kurs/danke');

        $grant = Grant::query()->sole();
        $this->assertTrue($grant->isRedeemable());
        $this->assertArrayNotHasKey('return_url', $grant->meta ?? []);
    }
}
