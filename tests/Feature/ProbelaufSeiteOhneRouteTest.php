<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicFunnels\Integrations\Map\EntryMapObjects;
use Goldnead\StatamicFunnels\Support\EntryAddress;
use Goldnead\StatamicFunnels\Support\PagePreview;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The pages of adriangoldner.com live in a collection with no route (`route: null`): the application's own
 * controllers serve them, and `Entry::url()` is null. Their address is written on the entry (`route_path`).
 * The site says so in `statamic-funnels.page_preview.address_field`; the addon asks the entry for that field
 * when Statamic has no address for it, and for nothing else.
 */
class ProbelaufSeiteOhneRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Simulator::class)) {
            $this->markTestSkipped('needs statamic-brand-context 1.21 (the dry run planner)');
        }

        // The real shape: no route at all.
        Collection::make('pages')->routes(null)->save();
    }

    /** The site's own controller serves the page, ahead of Statamic's catch-all (routes/web.php of adriangoldner.com). */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/cvt-im-chor-minikurs', fn () => 'framework page')->name('probe.minikurs');
    }

    protected function entry(?string $routePath, bool $published = true)
    {
        return tap(Entry::make()->collection('pages')->slug('cvt-im-chor-minikurs')->published($published)->data(array_filter(
            ['title' => 'CVT im Chor Minikurs', 'route_path' => $routePath],
            fn ($v) => $v !== null,
        )))->save();
    }

    protected function framework(): void
    {
        Http::fake(['*' => Http::response('<!doctype html><html><head><title>React</title></head><body><div id="app"><h1>Minikurs</h1><a href="/kontakt">Kontakt</a></div></body></html>', 200, ['Content-Type' => 'text/html'])]);
    }

    #[Test]
    public function the_real_shape_has_no_address_from_statamic(): void
    {
        $entry = $this->entry('/cvt-im-chor-minikurs');

        $this->assertNull($entry->url());
    }

    #[Test]
    public function a_page_with_no_route_is_asked_of_the_application_at_the_address_written_on_the_entry(): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        $this->framework();
        $entry = $this->entry('/cvt-im-chor-minikurs');

        $page = app(PagePreview::class)->render($entry->id());

        $this->assertSame('rendered', $page['state']);
        $this->assertSame('/cvt-im-chor-minikurs', $page['url']);
        $this->assertStringContainsString('<h1>Minikurs</h1>', $page['html']);
        Http::assertSent(fn ($request) => $request->url() === 'http://localhost/cvt-im-chor-minikurs' && ($request->header('Host')[0] ?? null) === 'localhost');
    }

    #[Test]
    public function without_the_setting_the_page_has_no_address_as_before(): void
    {
        $this->framework();
        $entry = $this->entry('/cvt-im-chor-minikurs');

        $page = app(PagePreview::class)->render($entry->id());

        $this->assertSame('unavailable', $page['state']);
        $this->assertSame(PagePreview::NO_ADDRESS, $page['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_entry_without_the_field_has_no_address(): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        $this->framework();

        $page = app(PagePreview::class)->render($this->entry(null)->id());

        $this->assertSame(PagePreview::NO_ADDRESS, $page['reason']);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function hostileAddresses(): array
    {
        return [
            'another host' => ['//evil.example/x'],
            'a full address' => ['https://evil.example/x'],
            'a backslash' => ['/\\evil.example'],
            'not a path' => ['cvt-im-chor-minikurs'],
            'a control character' => ["/x\r\nHost: evil.example"],
            'empty' => ['  '],
            'a query' => ['/cvt-im-chor-minikurs?x=1'],
            'a fragment' => ['/cvt-im-chor-minikurs#a'],
            'a dot segment' => ['/a/../cvt-im-chor-minikurs'],
        ];
    }

    #[Test]
    #[DataProvider('hostileAddresses')]
    public function an_address_that_is_no_path_of_this_site_is_not_followed(string $address): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        $this->framework();

        $page = app(PagePreview::class)->render($this->entry($address)->id());

        $this->assertSame('unavailable', $page['state']);
        $this->assertSame(PagePreview::NO_ADDRESS, $page['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_draft_stays_a_draft(): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        $this->framework();

        $page = app(PagePreview::class)->render($this->entry('/cvt-im-chor-minikurs', false)->id());

        $this->assertSame(PagePreview::DRAFT, $page['reason']);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_address_of_statamic_wins_over_the_field(): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        Collection::make('pages')->routes('/seiten/{slug}')->save();
        $entry = $this->entry('/etwas-anderes');

        $this->assertSame('/seiten/cvt-im-chor-minikurs', EntryAddress::path($entry));
    }

    #[Test]
    public function the_dry_run_names_the_page_with_the_address_written_on_the_entry(): void
    {
        config(['statamic-funnels.page_preview.address_field' => 'route_path']);
        $entry = $this->entry('/cvt-im-chor-minikurs');

        $effects = (new EntryMapObjects)->react($entry->id(), new SimEvent(SimEvent::ENTERED), SimPerson::known(), CarbonImmutable::now());

        $this->assertSame(Effect::PAGE, $effects[0]->kind);
        $this->assertSame('/cvt-im-chor-minikurs', $effects[0]->detail);
        $this->assertSame(['url' => '/cvt-im-chor-minikurs', 'entry' => $entry->id()], $effects[0]->meta);
    }
}
