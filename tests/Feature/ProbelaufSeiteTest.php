<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\PagePreview;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Goldnead\StatamicFunnels\Support\Split;
use Goldnead\StatamicFunnels\Support\Tracking;
use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\ResponseCreated;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** A lead magnet whose way ends on a page: the page is what a Probelauf renders. */
class PageProbeLeadMagnet implements ProvidesMapObjects
{
    public static string $entry = '';

    public function type(): string
    {
        return 'lead_magnet';
    }

    public function typeLabel(): string
    {
        return 'Lead magnet';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return new MapObject('lead_magnet', $id, 'Lead magnet '.$id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function simulationAuthorized(string $id, $user): bool
    {
        return $user !== null && $user->can('see page probe');
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::REQUESTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [new Effect(Effect::PAGE, 'Page', '/masterclass', 0, ['url' => '/masterclass', 'entry' => self::$entry])];
    }
}

/**
 * The page of the dry run is a look, not a visit: it is rendered on the server, and nothing that counts counts it.
 *
 * One test per place that counts. Each of them first proves the place does count for a real visitor (so the test is no
 * green by accident), then that the same call on a request marked as a look writes nothing.
 */
class ProbelaufSeiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Simulator::class)) {
            $this->markTestSkipped('needs statamic-brand-context 1.21 (the dry run planner)');
        }

        Collection::make('pages')->routes('/{slug}')->save();
        $this->app['view']->addNamespace('funnels-test', __DIR__.'/../__fixtures__/views');
    }

    /** A page the application builds itself, ahead of Statamic's catch-all, as a site with a front-end framework does. */
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->get('/app-seite', fn () => 'framework page')->name('probe.app');
    }

    protected function page(string $slug = 'masterclass', bool $published = true, string $template = 'funnels-test::probe-page')
    {
        return tap(Entry::make()->collection('pages')->slug($slug)->published($published)->data(['title' => 'Masterclass', 'template' => $template, 'layout' => 'funnels-test::layout']))->save();
    }

    /** A request that arrives like a visitor's, with the walk cookie of a visitor that exists. */
    protected function visitorRequest(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): Request
    {
        $request = Request::create('/f/kurs', 'GET', [], [FunnelWalk::COOKIE => $token]);
        $this->app->instance('request', $request);

        return $request;
    }

    /** The tracking code is rendered for a visitor without waiting for a consent addon this suite does not have. */
    protected function withoutConsentAddon(): void
    {
        TrackingConsent::resolveUsing(fn () => ['addon' => false, 'granted' => false]);
        config(['statamic-funnels.tracking.without_consent_addon' => 'render']);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => ['headline' => 'Start']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'finish_1', 'from_output' => 'default']);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Every table the addon writes to, counted: a look must leave all of them as they were. */
    protected function counts(): array
    {
        return [
            'visits' => FunnelVisit::query()->count(),
            'events' => FunnelStepEvent::query()->count(),
            'tokens' => count(glob(storage_path('statamic/tokens/*')) ?: []),
        ];
    }

    // --- the page itself ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_page_statamic_serves_is_rendered_with_its_own_template(): void
    {
        $entry = $this->page();

        $page = app(PagePreview::class)->render($entry->id());

        $this->assertSame('rendered', $page['state']);
        $this->assertSame('/masterclass', $page['url']);
        $this->assertStringContainsString('<h1>Masterclass</h1>', $page['html']);
        $this->assertStringStartsWith('<!doctype html>', $page['html']);
    }

    #[Test]
    public function nothing_that_runs_or_asks_for_something_is_left_in_the_page(): void
    {
        $page = app(PagePreview::class)->render($this->page()->id());
        $html = $page['html'];

        // No script, and no `<noscript>`: a sandbox without scripts would show its pixel, and that is a request that counts.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('analytics/script.js', $html);
        $this->assertStringNotContainsString('<noscript', $html);
        $this->assertStringNotContainsString('pixel.example', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('action="/f/kurs/weiter"', $html);
        // Only the site's own stylesheet; a third party's web font and a preconnect are never asked.
        $this->assertStringContainsString('href="/css/funnel-pages.css"', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
        // The site's own picture stays.
        $this->assertStringContainsString('src="/img/hero.jpg"', $html);
    }

    #[Test]
    public function the_page_carries_a_policy_that_lets_it_load_from_the_site_and_from_nowhere_else(): void
    {
        $html = html_entity_decode(app(PagePreview::class)->render($this->page()->id())['html'], ENT_QUOTES);

        $this->assertMatchesRegularExpression("/Content-Security-Policy\" content=\"default-src 'none'; style-src 'unsafe-inline' http:\/\/[^ ;]+; img-src http:\/\/[^ ;]+ data:; font-src http:\/\/[^ ;]+ data:; form-action 'none'/", $html);
        // The policy comes before anything it must cover.
        $this->assertLessThan(strpos($html, '<h1'), strpos($html, 'Content-Security-Policy'));
        $this->assertLessThan(strpos($html, 'funnel-pages.css'), strpos($html, 'Content-Security-Policy'));
    }

    #[Test]
    public function a_link_is_kept_as_a_target_and_leads_nowhere(): void
    {
        $page = app(PagePreview::class)->render($this->page()->id());

        // The confirmation of a lead magnet, its download counter and a smartlink: shown, never followed.
        $this->assertSame([
            ['text' => 'Bestätigen', 'url' => '/!/lead-magnets/confirm/abc123'],
            ['text' => 'Download', 'url' => '/!/lead-magnets/download/abc123'],
            ['text' => 'Smartlink', 'url' => '/go/xyz'],
            ['text' => 'Bild-Link', 'url' => '/!/lead-magnets/confirm/svg789'],
        ], $page['links']);

        // No link has an address left, not even `#` (with the base element in the document a bare `#` is read against the site, and a
        // click on it would load the front page into the frame: a visit). What a click would do is only remembered, in `data-sim-href`.
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($page['html']);
        libxml_clear_errors();

        foreach ((new \DOMXPath($document))->query('//a') as $link) {
            $this->assertFalse($link->hasAttribute('href'), 'a link kept its href');
            $this->assertFalse($link->hasAttribute('xlink:href'), 'a link kept its xlink:href');
        }

        $this->assertStringNotContainsString('xlink:href', $page['html']);
        $this->assertStringContainsString('data-sim-href="/!/lead-magnets/confirm/svg789"', $page['html']);
        $this->assertStringNotContainsString('javascript:', $page['html']);
        $this->assertStringContainsString('data-sim-href="/!/lead-magnets/confirm/abc123"', $page['html']);
        // No link carries a target of its own (it could name the frame itself); the base element sends what is left to a new window, which
        // a sandbox without `allow-popups` refuses.
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*target=/', $page['html']);
        $this->assertStringContainsString('<base href="http://localhost/" target="_blank">', $page['html']);
        $this->assertStringNotContainsString('odd111', str_replace('data-sim-href', '', $page['html']));
    }

    #[Test]
    public function rendering_leaves_the_request_the_views_and_the_site_as_they_were(): void
    {
        $entry = $this->page();
        $request = app('request');
        $paths = view()->getFinder()->getPaths();

        app(PagePreview::class)->render($entry->id());
        app(PagePreview::class)->render($entry->id());

        $this->assertSame($request, app('request'));
        $this->assertSame($paths, view()->getFinder()->getPaths());
        $this->assertFalse(ProbePreview::active());
    }

    #[Test]
    public function a_draft_a_missing_entry_and_a_page_the_application_serves_itself_are_said_not_rendered(): void
    {
        // The entry exists, but a route of the application (see `defineRoutes()`) answers its address ahead of Statamic.
        $this->page('app-seite');
        $app = Entry::query()->where('slug', 'app-seite')->first();
        $draft = $this->page('entwurf', published: false);

        $this->assertSame(['state' => 'unavailable', 'reason' => PagePreview::DRAFT, 'url' => '/entwurf', 'address' => 'localhost/entwurf', 'thumbnail' => null], app(PagePreview::class)->render($draft->id()));
        $this->assertSame(PagePreview::GONE, app(PagePreview::class)->render('nope')['reason']);
        $this->assertSame(PagePreview::APP_ROUTE, app(PagePreview::class)->render($app->id())['reason']);
    }

    #[Test]
    public function a_page_that_fails_to_render_is_said_failed_and_does_not_break_the_screen(): void
    {
        $entry = $this->page('kaputt', template: 'funnels-test::does-not-exist');

        $page = app(PagePreview::class)->render($entry->id());

        $this->assertSame('unavailable', $page['state']);
        $this->assertContains($page['reason'], [PagePreview::FAILED, PagePreview::NOT_A_PAGE]);
        $this->assertFalse(ProbePreview::active());
    }

    // --- the places that count ----------------------------------------------------------------------------------------

    #[Test]
    public function the_walk_writes_no_visit_and_no_cookie_for_a_look(): void
    {
        $funnel = $this->funnel();

        // For a visitor it does: a row and a cookie.
        $this->visitorRequest();
        app(FunnelWalk::class)->visit($funnel);
        $this->assertSame(1, FunnelVisit::query()->count());

        FunnelVisit::query()->delete();
        cookie()->flushQueuedCookies();

        $this->visitorRequest('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz');
        ProbePreview::mark(app('request'));
        $visit = app(FunnelWalk::class)->visit($funnel);

        $this->assertSame(0, FunnelVisit::query()->count());
        $this->assertFalse($visit->exists);
        $this->assertSame([], cookie()->getQueuedCookies());
        // And it finds no walk to look at, not even a real one.
        FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz']);
        $this->assertNull(app(FunnelWalk::class)->existingVisit($funnel));
    }

    #[Test]
    public function arriving_at_a_step_is_recorded_for_a_visitor_and_not_for_a_look(): void
    {
        Event::fake([FunnelStepEntered::class]);
        $funnel = $this->funnel();
        $step = $funnel->steps->firstWhere('node_key', 'entry_1');
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']);

        $this->visitorRequest();
        app(FunnelWalk::class)->enter($visit, $step);
        $this->assertSame(1, FunnelStepEvent::query()->count());
        Event::assertDispatchedTimes(FunnelStepEntered::class, 1);

        FunnelStepEvent::query()->delete();
        Event::fake([FunnelStepEntered::class]);

        ProbePreview::mark($this->visitorRequest());
        app(FunnelWalk::class)->enter($visit, $step);

        $this->assertSame(0, FunnelStepEvent::query()->count());
        Event::assertNotDispatched(FunnelStepEntered::class);
    }

    #[Test]
    public function going_on_from_a_step_writes_no_event_and_ends_no_walk_for_a_look(): void
    {
        $funnel = $this->funnel();
        $step = $funnel->steps->firstWhere('node_key', 'entry_1');
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']);

        ProbePreview::mark($this->visitorRequest());

        $this->assertNull(app(FunnelWalk::class)->advance($visit, $step, 'default', 'continued'));
        app(FunnelWalk::class)->complete($visit, $step);

        $this->assertSame(0, FunnelStepEvent::query()->count());
        $this->assertNull($visit->fresh()->completed_at);
    }

    #[Test]
    public function a_split_test_shares_out_a_visitor_and_not_a_look(): void
    {
        $funnel = $this->funnel();
        $step = $funnel->steps->firstWhere('node_key', 'entry_1');
        $step->config = ['split_share' => 50, 'variant_headline' => 'B'];
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']);

        if (! Split::running($step)) {
            $this->markTestSkipped('the split test is not switched on by this config shape');
        }

        $this->visitorRequest();
        Split::variantFor($step, $visit);
        $this->assertArrayHasKey('variants', $visit->fresh()->meta ?? []);

        $other = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'bbcdefghijklmnopqrstuvwxyz012345']);
        ProbePreview::mark($this->visitorRequest());

        $this->assertSame(Split::A, Split::variantFor($step, $other));
        $this->assertArrayNotHasKey('variants', $other->fresh()->meta ?? []);
    }

    #[Test]
    public function the_tracking_code_and_the_server_side_pixel_are_for_a_visitor_and_not_for_a_look(): void
    {
        Http::fake();
        $this->withoutConsentAddon();
        $funnel = $this->funnel();
        $funnel->update(['meta' => ['settings' => ['meta_pixel_id' => '123456789012345', 'tracking_head' => '<script>track()</script>']]]);
        $funnel = $funnel->fresh(['steps', 'edges']);
        $step = $funnel->steps->firstWhere('node_key', 'entry_1');
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']);

        $request = $this->visitorRequest();
        $seen = Tracking::forPage($funnel, $step, $visit, $request, null);

        ProbePreview::mark($request);
        $look = Tracking::forPage($funnel, $step, $visit, $request, null);

        $this->assertSame(['head' => '', 'body' => ''], $look);
        // The visitor got the code, so the empty answer is the guard and not an unconfigured host.
        $this->assertStringContainsString('track()', $seen['head']);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_server_side_pixel_event_is_sent_for_a_visitor_and_not_for_a_look(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x'])]);
        config(['statamic-funnels.tracking.meta.access_token' => 'token-geheim']);
        TrackingConsent::resolveUsing(fn () => ['addon' => true, 'granted' => true]);

        $funnel = $this->funnel();
        $funnel->update(['meta' => ['settings' => ['meta_pixel_id' => '123456789012345']]]);
        $funnel = $funnel->fresh(['steps', 'edges']);
        $step = $funnel->steps->firstWhere('node_key', 'entry_1');
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'abcdefghijklmnopqrstuvwxyz012345']);

        $request = $this->visitorRequest();
        Tracking::initiateCheckout($funnel, $step, $visit, $request, 9900, 'EUR');

        // For a visitor the event goes to the platform ...
        $this->assertCount(1, Http::recorded());

        // ... and the same call as a look sends nothing more.
        ProbePreview::mark($request);
        Tracking::initiateCheckout($funnel, $step, $visit, $request, 9900, 'EUR');

        $this->assertCount(1, Http::recorded());
        TrackingConsent::resolveUsing(null);
    }

    #[Test]
    public function rendering_a_page_marks_the_request_it_renders_on_as_a_look(): void
    {
        $seen = [];
        Event::listen(ResponseCreated::class, function () use (&$seen) {
            $seen[] = ProbePreview::active();
        });

        $this->assertFalse(ProbePreview::active());

        app(PagePreview::class)->render($this->page()->id());

        // While Statamic built the response, the request in the container was the look; before and after it is the real one again.
        $this->assertSame([true], $seen);
        $this->assertFalse(ProbePreview::active());
    }

    #[Test]
    public function a_funnel_page_asked_as_a_look_writes_no_visit_no_event_no_cookie_and_carries_no_tracking(): void
    {
        $this->withoutConsentAddon();
        $funnel = $this->funnel();
        $funnel->update(['meta' => ['settings' => ['meta_pixel_id' => '123456789012345', 'tracking_head' => '<script>track()</script>']]]);
        $before = $this->counts();

        // The same address a visitor would open, through the whole stack, on a request that is only a look.
        $request = ProbePreview::mark(Request::create('/f/kurs', 'GET'));
        $response = $this->app->make(Kernel::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($before, $this->counts());
        $this->assertSame([], collect($response->headers->getCookies())->filter(fn ($c) => $c->getName() === FunnelWalk::COOKIE)->all());
        $this->assertStringNotContainsString('fbq(', (string) $response->getContent());
        $this->assertStringNotContainsString('track()', (string) $response->getContent());

        // For a visitor the same address does write, and carries the code.
        $visitor = $this->app->make(Kernel::class)->handle(Request::create('/f/kurs', 'GET'));
        $this->assertSame(1, FunnelVisit::query()->count());
        $this->assertStringContainsString('track()', (string) $visitor->getContent());
    }

    // --- the endpoint ---------------------------------------------------------------------------------------------------

    protected function landkarte(): Landkarte
    {
        $landkarte = Landkarte::query()->create(['name' => 'Seite']);
        $stage = $landkarte->stages()->create(['name' => 'Phase 1', 'position' => 0]);
        $stage->refs()->create(['type' => 'lead_magnet', 'ref_id' => 'freebie', 'position' => 0]);

        return $landkarte;
    }

    protected function asProber(bool $may = true)
    {
        Permission::register('see page probe');
        Role::make('page-prober')->title('Prober')->permissions(array_merge(['access cp', 'access funnels utility'], $may ? ['see page probe'] : []))->save();

        return $this->actingAs(tap(User::make()->email('prober@example.com')->assignRole('page-prober'))->save());
    }

    protected function pageEntryId(Landkarte $landkarte, array $person = []): string
    {
        $json = $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf", ['person' => 'test', 'start' => 'lead_magnet:freebie:requested', ...$person])->assertOk()->json();

        return collect($json['entries'])->firstWhere('kind', 'page')['id'];
    }

    #[Test]
    public function the_endpoint_renders_the_page_of_the_way_and_writes_nothing(): void
    {
        PageProbeLeadMagnet::$entry = $this->page()->id();
        app(MapObjectRegistry::class)->register(PageProbeLeadMagnet::class);
        $this->funnel();
        $landkarte = $this->landkarte();
        $this->asProber();
        $id = $this->pageEntryId($landkarte);
        Http::fake();
        $before = $this->counts();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $json = $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/page", ['start' => 'lead_magnet:freebie:requested', 'entry' => $id, 'label' => 'Page'])->assertOk()->json();

        $this->assertSame('rendered', $json['state']);
        $this->assertSame('/masterclass', $json['url']);
        $this->assertStringContainsString('<h1>Masterclass</h1>', $json['html']);
        $this->assertCount(4, $json['links']);
        $this->assertSame($before, $this->counts());
        // Nothing asked of the network: no link of the page, no tracking host, no stylesheet.
        Http::assertNothingSent();
        $this->assertSame([], $writes);
    }

    #[Test]
    public function the_endpoint_names_the_page_by_its_place_in_the_way_and_to_the_right_user_only(): void
    {
        PageProbeLeadMagnet::$entry = $this->page()->id();
        app(MapObjectRegistry::class)->register(PageProbeLeadMagnet::class);
        $landkarte = $this->landkarte();
        $this->asProber();
        $id = $this->pageEntryId($landkarte);
        $url = "/cp/funnels/landkarten/{$landkarte->id}/probelauf/page";
        $start = 'lead_magnet:freebie:requested';

        // Not a page of this way, another name for the place, a start the map does not offer, a shape that is none.
        $this->postJson($url, ['start' => $start, 'entry' => 'e1', 'label' => 'Page'])->assertNotFound();
        $this->postJson($url, ['start' => $start, 'entry' => $id, 'label' => 'Something else'])->assertStatus(409);
        $this->postJson($url, ['start' => 'automation:x:started', 'entry' => $id, 'label' => 'Page'])->assertStatus(422);
        $this->postJson($url, ['start' => $start, 'entry' => '../../x', 'label' => 'Page'])->assertStatus(422);

        // And the entry the way names is the one rendered, whatever the client says besides.
        $this->postJson($url, ['start' => $start, 'entry' => $id, 'label' => 'Page', 'entry_id' => 'other'])->assertOk()->assertJsonPath('state', 'rendered');
    }

    #[Test]
    public function a_user_who_may_not_see_the_owner_gets_no_page(): void
    {
        PageProbeLeadMagnet::$entry = $this->page()->id();
        app(MapObjectRegistry::class)->register(PageProbeLeadMagnet::class);
        $landkarte = $this->landkarte();
        $this->asProber(may: false);

        // Without the right the planner names the card and nothing it does: no entry is a page.
        foreach (range(1, 4) as $i) {
            $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/page", ['start' => 'lead_magnet:freebie:requested', 'entry' => "e{$i}", 'label' => 'Page'])->assertNotFound();
        }
    }
}
