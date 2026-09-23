<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * F6/F7 mit einer echten Einwilligung: dem Cookie, wie statamic-consent es
 * schreibt, gelesen von einem Registry, das es liest wie das echte
 * (`tests/Fakes/consent-registry.php`). Kein `resolveUsing()`.
 *
 * In eigenen Prozessen: die Klasse des Addons bleibt nach dem Laden stehen und
 * wuerde jeden anderen Test in den Modus „mit Consent-Addon" schicken.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TrackingWithConsentCookieTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        require_once __DIR__.'/../Fakes/consent-registry.php';

        parent::setUp();

        // Wie das echte Addon: sein Cookie schreibt JavaScript, also ist es
        // von der Verschluesselung ausgenommen.
        EncryptCookies::except('statamic_consent');

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        config([
            'statamic-funnels.tracking.meta.access_token' => 'token-geheim',
            'statamic-consent.version' => 3,
        ]);
    }

    /** @param  list<string>  $granted */
    protected function mitCookie(array $granted, int $version = 3): static
    {
        return $this->asVisitor()->withUnencryptedCookie('statamic_consent', rawurlencode((string) json_encode([
            'v' => $version, 'granted' => $granted, 'ts' => 1, 'how' => 'banner', 'id' => 'x',
        ])));
    }

    protected function funnelMitPixel(): void
    {
        $this->kasse([], [], ['settings' => [
            'meta_pixel_id' => '123456789012345',
            'tracking_head' => '<script>window.statistik = 1;</script>',
            'tracking_head_service' => 'statistik',
        ]]);
    }

    protected function pageViews(): int
    {
        $n = 0;

        Http::recorded(function (HttpRequest $r) use (&$n) {
            foreach ((array) ($r->data()['data'] ?? []) as $e) {
                $n += (($e['event_name'] ?? null) === 'PageView') ? 1 : 0;
            }

            return true;
        });

        return $n;
    }

    #[Test]
    public function mit_einwilligung_fuer_den_pixel_meldet_der_server_und_die_seite_parkt(): void
    {
        $this->funnelMitPixel();

        $this->mitCookie(['meta_pixel'])->get('/f/kurs')->assertOk()
            ->assertSee('data-consent-service="meta_pixel">!function', false)
            ->assertSee('data-consent-service="statistik">window.statistik', false);

        $this->assertSame(1, $this->pageViews());
    }

    #[Test]
    public function einwilligung_nur_fuer_statistik_meldet_nichts_an_meta(): void
    {
        $this->funnelMitPixel();

        $this->mitCookie(['statistik'])->get('/f/kurs')->assertOk();

        $this->assertSame(0, $this->pageViews());
    }

    #[Test]
    public function ohne_cookie_meldet_der_server_nichts(): void
    {
        $this->funnelMitPixel();

        $this->asVisitor()->get('/f/kurs')->assertOk();

        $this->assertSame(0, $this->pageViews());
    }

    #[Test]
    public function eine_einwilligung_einer_alten_fassung_gilt_nicht(): void
    {
        $this->funnelMitPixel();

        $this->mitCookie(['meta_pixel'], 2)->get('/f/kurs')->assertOk();

        $this->assertSame(0, $this->pageViews());
    }
}
