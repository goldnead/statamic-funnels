<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Page counting without a cookie (`page_tracking.mode = cookieless`).
 *
 * The visit is told apart by a hash that changes every day (app key, date, funnel, IP, user agent). Nothing is
 * set in the browser, nothing is asked, and neither the IP nor the user agent is stored.
 */
class CookielessPageTrackingTest extends TestCase
{
    use WalksAFunnel;

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware('web')->group(function ($router): void {
            $router->get('/optin', fn () => response('<html>Seite</html>'))->middleware('statamic-funnels.track:reise,optin');
            $router->get('/ohne', fn () => response('<html>Seite</html>'));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic-funnels.page_tracking.mode', 'cookieless');

        $funnel = Funnel::create(['handle' => 'reise', 'title' => 'Reise', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'optin', 'type' => 'external', 'label' => 'Seite', 'config' => ['path' => '/optin']],
            ['node_key' => 'ende', 'type' => 'finish', 'label' => 'Ende', 'slug' => 'ende'],
        ]);
        $funnel->edges()->create(['from_node_key' => 'optin', 'to_node_key' => 'ende', 'from_output' => 'default']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TrackingConsent::resolveUsing(null);

        parent::tearDown();
    }

    private function seite(string $agent = self::BROWSER, string $ip = '203.0.113.7')
    {
        return $this->flushHeaders()->withHeaders(['User-Agent' => $agent])->withServerVariables(['REMOTE_ADDR' => $ip])->get('/optin');
    }

    private function entered(): int
    {
        return FunnelStepEvent::query()->where('node_key', 'optin')->where('event', FunnelStepEvent::ENTERED)->count();
    }

    #[Test]
    public function a_visit_counts_once_and_the_browser_gets_no_cookie(): void
    {
        $this->seite()->assertOk()->assertCookieMissing(FunnelWalk::COOKIE);

        $this->assertSame(1, $this->entered());
        $this->assertSame(1, FunnelVisit::count());
    }

    #[Test]
    public function the_same_person_reloading_the_same_day_is_still_one_visit(): void
    {
        $this->seite();
        $this->seite();
        $this->seite();

        $this->assertSame(1, $this->entered());
        $this->assertSame(1, FunnelVisit::count());
    }

    #[Test]
    public function another_person_is_another_visit(): void
    {
        $this->seite();
        $this->seite(ip: '198.51.100.9');
        $this->seite(agent: self::BROWSER.' Edg/126.0');

        $this->assertSame(3, $this->entered());
    }

    #[Test]
    public function the_next_day_is_a_new_visit(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $this->seite();

        Carbon::setTestNow('2026-10-23 10:00:00');
        $this->seite();

        $this->assertSame(2, $this->entered());
        $this->assertSame(2, FunnelVisit::count());
    }

    #[Test]
    public function neither_the_ip_nor_the_user_agent_is_stored(): void
    {
        $this->seite();

        $row = json_encode(FunnelVisit::query()->first()->getAttributes()).json_encode(FunnelStepEvent::query()->get()->toArray());

        $this->assertStringNotContainsString('203.0.113.7', $row);
        $this->assertStringNotContainsString('Mozilla', $row);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', FunnelVisit::query()->first()->token);
    }

    #[Test]
    public function no_consent_is_asked_in_this_mode(): void
    {
        config()->set('statamic-funnels.page_tracking.consent_service', 'funnel_visits');
        TrackingConsent::resolveUsing(fn (Request $request, ?string $service = null): array => ['addon' => true, 'granted' => false]);

        $this->seite()->assertOk();

        $this->assertSame(1, $this->entered());
    }

    #[Test]
    public function the_response_is_the_same_and_bots_and_prefetches_still_do_not_count(): void
    {
        $with = $this->seite();
        $without = $this->flushHeaders()->withHeaders(['User-Agent' => self::BROWSER])->get('/ohne');

        $this->assertSame($without->getContent(), $with->getContent());
        $this->assertSame($without->getStatusCode(), $with->getStatusCode());

        FunnelVisit::query()->delete();
        FunnelStepEvent::query()->delete();

        $this->seite('Googlebot/2.1')->assertOk();
        $this->flushHeaders()->withHeaders(['User-Agent' => self::BROWSER, 'Purpose' => 'prefetch'])->get('/optin');
        $this->flushHeaders()->withHeaders(['User-Agent' => self::BROWSER, 'X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'x'])->get('/optin');

        $this->assertSame(0, FunnelVisit::count());
    }

    #[Test]
    public function the_default_mode_is_still_the_cookie(): void
    {
        config()->set('statamic-funnels.page_tracking.mode', 'cookie');

        $this->seite()->assertOk()->assertCookie(FunnelWalk::COOKIE);
    }
}
