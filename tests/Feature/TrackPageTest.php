<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;

/** What `MarkPagePreview` does to a request that came with a good pass. */
class MarkAsLook
{
    public function handle(Request $request, \Closure $next)
    {
        ProbePreview::mark($request);

        return $next($request);
    }
}

/**
 * A page of the site that is a step of a funnel, counted by the route middleware.
 *
 * What is pinned here is what makes the numbers worth reading: one arrival per
 * person and walk, nothing for anything that is not a person looking, and a
 * page that is byte for byte the page it was before the middleware stood in
 * front of it.
 */
class TrackPageTest extends TestCase
{
    use WalksAFunnel;

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    private const BODY = '<html><body>Die Seite der Website</body></html>';

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/optin', fn () => response(self::BODY, 200, ['X-Seite' => 'ja']))->middleware('statamic-funnels.track:reise,optin');
            $router->get('/ohne', fn () => response(self::BODY, 200, ['X-Seite' => 'ja']));
            $router->get('/weg', fn () => response('nicht da', 404))->middleware('statamic-funnels.track:reise,optin');
            $router->get('/kaputt', fn () => response(self::BODY))->middleware('statamic-funnels.track:reise,gibt-es-nicht');
            // The marker `MarkPagePreview` puts on a request that came with a good pass.
            $router->get('/optin-look', fn () => response(self::BODY))->middleware([MarkAsLook::class, 'statamic-funnels.track:reise,optin']);
            $router->post('/optin-post', fn () => response('ok'))->middleware('statamic-funnels.track:reise,optin');
        });
    }

    protected function reise(bool $published = true): Funnel
    {
        $funnel = Funnel::create(['handle' => 'reise', 'title' => 'Die Reise', 'published' => $published]);

        $funnel->steps()->createMany([
            ['node_key' => 'optin', 'type' => 'external', 'label' => 'Seite', 'config' => ['path' => '/optin']],
            ['node_key' => 'ende', 'type' => 'finish', 'label' => 'Ende', 'slug' => 'ende'],
        ]);
        $funnel->edges()->create(['from_node_key' => 'optin', 'to_node_key' => 'ende', 'from_output' => 'default']);

        return $funnel;
    }

    protected function entered(): int
    {
        return FunnelStepEvent::query()->where('node_key', 'optin')->where('event', FunnelStepEvent::ENTERED)->count();
    }

    /** A browser, with only the headers given: Laravel keeps headers from one call to the next. */
    protected function browser(array $headers = []): static
    {
        return $this->flushHeaders()->withHeaders(array_merge(['User-Agent' => self::BROWSER], $headers));
    }

    #[Test]
    public function one_page_view_is_exactly_one_entered_and_a_reload_is_none(): void
    {
        $this->reise();

        $first = $this->browser()->get('/optin')->assertOk();

        $this->assertSame(1, $this->entered());
        $this->assertSame(1, FunnelVisit::count());
        $first->assertCookie(FunnelWalk::COOKIE);

        $token = FunnelVisit::first()->token;

        $this->browser()->asVisitor($token)->get('/optin')->assertOk();
        $this->browser()->asVisitor($token)->get('/optin')->assertOk();

        $this->assertSame(1, $this->entered());
        $this->assertSame(1, FunnelVisit::count());
    }

    #[Test]
    public function a_second_person_is_a_second_arrival(): void
    {
        $this->reise();

        $this->browser()->asVisitor(str_repeat('a', 32))->get('/optin');
        $this->browser()->asVisitor(str_repeat('b', 32))->get('/optin');

        $this->assertSame(2, $this->entered());
    }

    #[Test]
    public function the_response_is_the_same_with_and_without_the_middleware(): void
    {
        $this->reise();

        $with = $this->browser()->get('/optin');
        $without = $this->browser()->get('/ohne');

        $this->assertSame($without->getContent(), $with->getContent());
        $this->assertSame($without->getStatusCode(), $with->getStatusCode());
        $this->assertSame($without->headers->get('X-Seite'), $with->headers->get('X-Seite'));
        $this->assertSame($without->headers->get('Content-Type'), $with->headers->get('Content-Type'));
    }

    #[Test]
    public function an_inertia_visit_from_another_page_counts_but_a_partial_reload_and_a_plain_script_request_do_not(): void
    {
        $this->reise();

        $this->browser(['X-Requested-With' => 'XMLHttpRequest', 'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Seite', 'X-Inertia-Partial-Data' => 'x'])->get('/optin');
        $this->browser(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->get('/optin');
        $this->browser(['Sec-Fetch-Mode' => 'cors'])->get('/optin');

        $this->assertSame(0, $this->entered());
        $this->assertSame(0, FunnelVisit::count());

        $this->browser(['X-Requested-With' => 'XMLHttpRequest', 'X-Inertia' => 'true'])->get('/optin');

        $this->assertSame(1, $this->entered());
    }

    #[Test]
    public function bots_monitors_previews_and_prefetches_are_not_visitors(): void
    {
        $this->reise();

        foreach ([
            'Googlebot/2.1 (+http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
            'Uptime-Kuma/1.23.0',
            'Mozilla/5.0 HeadlessChrome/126.0 Safari/537.36',
            'curl/8.5.0',
            'WhatsApp/2.23',
            'facebookexternalhit/1.1',
            'python-requests/2.31',
        ] as $agent) {
            $this->browser(['User-Agent' => $agent])->get('/optin')->assertOk();
        }

        // No user agent at all.
        $this->flushHeaders()->withServerVariables(['HTTP_USER_AGENT' => ''])->get('/optin')->assertOk();

        // A browser that only guesses you will go there.
        $this->browser(['Purpose' => 'prefetch'])->get('/optin');
        $this->browser(['Sec-Purpose' => 'prefetch;prerender'])->get('/optin');

        // A monitor of the site's own that cannot change its agent.
        $this->browser(['X-Funnels-Ignore' => '1'])->get('/optin');

        $this->assertSame(0, $this->entered());
        $this->assertSame(0, FunnelVisit::count());
    }

    #[Test]
    public function only_a_get_that_was_answered_with_200_counts(): void
    {
        $this->reise();

        $this->browser()->post('/optin-post')->assertOk();
        $this->browser()->get('/weg')->assertNotFound();
        $this->browser()->call('HEAD', '/optin');

        $this->assertSame(0, $this->entered());
    }

    #[Test]
    public function the_page_the_probelauf_asks_for_is_a_look_and_not_a_visit(): void
    {
        $this->reise();

        $this->browser()->get('/optin-look')->assertOk();

        $this->assertSame(0, $this->entered());
        $this->assertSame(0, FunnelVisit::count());
    }

    #[Test]
    public function a_funnel_that_is_not_published_counts_nothing(): void
    {
        $this->reise(published: false);

        $this->browser()->get('/optin')->assertOk();

        $this->assertSame(0, FunnelVisit::count());
    }

    #[Test]
    public function a_step_that_is_not_an_external_step_or_does_not_exist_counts_nothing(): void
    {
        $funnel = $this->reise();
        $funnel->steps()->where('node_key', 'optin')->update(['type' => 'page', 'slug' => 'optin']);

        $this->browser()->get('/optin')->assertOk();
        $this->browser()->get('/kaputt')->assertOk()->assertSee('Die Seite der Website');

        $this->assertSame(0, FunnelVisit::count());
    }

    #[Test]
    public function a_failure_while_counting_never_reaches_the_page(): void
    {
        $this->reise();

        $this->app->bind(FunnelWalk::class, fn () => new class extends FunnelWalk
        {
            public function visit(Funnel $funnel): FunnelVisit
            {
                throw new \RuntimeException('Datenbank weg');
            }
        });

        $this->browser()->get('/optin')->assertOk()->assertSee('Die Seite der Website');
    }

    #[Test]
    public function with_a_consent_service_nothing_is_counted_until_it_is_granted(): void
    {
        $this->reise();
        config()->set('statamic-funnels.page_tracking.consent_service', 'funnel_visits');

        TrackingConsent::resolveUsing(fn (Request $request, ?string $service = null): array => ['addon' => true, 'granted' => false]);
        $this->browser()->get('/optin')->assertOk()->assertCookieMissing(FunnelWalk::COOKIE);

        $this->assertSame(0, FunnelVisit::count());

        TrackingConsent::resolveUsing(fn (Request $request, ?string $service = null): array => ['addon' => true, 'granted' => true]);
        $this->browser()->get('/optin')->assertOk();

        TrackingConsent::resolveUsing(null);

        $this->assertSame(1, $this->entered());
    }
}
