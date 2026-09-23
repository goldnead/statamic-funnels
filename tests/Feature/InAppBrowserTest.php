<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Support\InAppBrowser;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * F3: der Hinweis im In-App-Browser.
 *
 * Instagram, Facebook, TikTok und LinkedIn oeffnen Links im eigenen Browser.
 * Dort fehlen gespeicherte Passwoerter, Apple Pay und die Banking-App, und ein
 * Kauf bricht an der Zahlung ab. Die Seite sagt es und bietet den Weg in den
 * echten Browser an. Abschaltbar je Funnel, Text anpassbar.
 */
class InAppBrowserTest extends TestCase
{
    use WalksAFunnel;

    public const INSTAGRAM = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 339.0.3.12.91 (iPhone15,3; iOS 17_5; de_DE; de; scale=3.00; 1290x2796; 618153451)';

    public const SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /** @return array<string, array{string, string|null}> */
    public static function agents(): array
    {
        return [
            'Instagram' => [self::INSTAGRAM, 'Instagram'],
            'Facebook iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.108;FBBV/620000000;FBDV/iPhone15,3]', 'Facebook'],
            'Facebook Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/470.0.0.40.108;]', 'Facebook'],
            'TikTok' => ['Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0 Mobile Safari/537.36 trill_350003 BytedanceWebview/d8a21c6', 'TikTok'],
            'TikTok iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 musical_ly_35.0.0 JsSdk/2.0 NetType/WIFI Channel/App Store', 'TikTok'],
            'LinkedIn' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [LinkedInApp]/9.30.1', 'LinkedIn'],
            'Threads' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Barcelona 339.0.0.26.107 (iPhone15,3; iOS 17_5; de_DE; de-DE; scale=3.00; 1290x2796; 618153452) NW/3', 'Threads'],
            'Pinterest' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]', 'Pinterest'],
            'Pinterest Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0 Mobile Safari/537.36 [Pinterest/Android]', 'Pinterest'],
            'Snapchat' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Snapchat/13.10.0.40 (like Safari/8617.2.4.10.8, panda)', 'Snapchat'],
            'Safari' => [self::SAFARI, null],
            'Chrome Android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', null],
        ];
    }

    #[Test]
    #[DataProvider('agents')]
    public function erkennt_die_app_am_user_agent(string $agent, ?string $app): void
    {
        $this->assertSame($app, InAppBrowser::detect($agent));
    }

    #[Test]
    public function im_instagram_browser_steht_der_hinweis_auf_der_seite(): void
    {
        $this->kasse();

        $this->asVisitor()->withHeaders(['User-Agent' => self::INSTAGRAM])->get('/f/kurs')
            ->assertOk()
            ->assertSee('class="funnel-inapp"', false)
            ->assertSee(__('statamic-funnels::messages.in_app_default', ['app' => 'Instagram']), false)
            ->assertSee('data-funnel-copy-link', false)
            ->assertSee('funnels.js', false);
    }

    #[Test]
    public function im_echten_browser_steht_nichts(): void
    {
        $this->kasse();

        $this->asVisitor()->withHeaders(['User-Agent' => self::SAFARI])->get('/f/kurs')
            ->assertOk()
            ->assertDontSee('class="funnel-inapp"', false);
    }

    #[Test]
    public function abgeschaltet_je_funnel_steht_nichts(): void
    {
        $this->kasse([], [], ['settings' => ['in_app_enabled' => false]]);

        $this->asVisitor()->withHeaders(['User-Agent' => self::INSTAGRAM])->get('/f/kurs')
            ->assertOk()
            ->assertDontSee('class="funnel-inapp"', false);
    }

    #[Test]
    public function der_eigene_text_ersetzt_den_vorgegebenen(): void
    {
        $this->kasse([], [], ['settings' => ['in_app_text' => 'Tipp oben rechts auf die drei Punkte und öffne die Seite in :app nicht, sondern im Browser.']]);

        $this->asVisitor()->withHeaders(['User-Agent' => self::INSTAGRAM])->get('/f/kurs')
            ->assertSee('Tipp oben rechts auf die drei Punkte und öffne die Seite in Instagram nicht, sondern im Browser.', false);
    }

    #[Test]
    public function auf_android_fuehrt_ein_link_direkt_in_chrome(): void
    {
        $this->kasse();

        $agent = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0 Mobile Safari/537.36 Instagram 339.0.0.12.91 Android';

        $this->asVisitor()->withHeaders(['User-Agent' => $agent])->get('/f/kurs')
            ->assertSee('intent://localhost/f/kurs#Intent;scheme=http;package=com.android.chrome;end', false)
            // Auf Android gibt es kein Apple Pay; dort steht der Text fuer Chrome.
            ->assertSee(__('statamic-funnels::messages.in_app_default_android', ['app' => 'Instagram']), false)
            ->assertDontSee('Apple Pay');
    }

    #[Test]
    public function global_abgeschaltet_steht_nichts(): void
    {
        config(['statamic-funnels.in_app_browser.enabled' => false]);
        $this->kasse();

        $this->asVisitor()->withHeaders(['User-Agent' => self::INSTAGRAM])->get('/f/kurs')
            ->assertDontSee('class="funnel-inapp"', false);
    }
}
