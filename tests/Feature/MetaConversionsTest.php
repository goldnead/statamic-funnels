<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * F7: Meta-Pixel und Conversions API.
 *
 * Der Pixel im Browser und dieselben Ereignisse vom Server: PageView,
 * InitiateCheckout, Purchase. **Jedes mit derselben Ereignis-ID auf beiden
 * Wegen**, sonst zaehlt Meta doppelt und jede Kennzahl ist zu hoch, ohne dass
 * es jemand merkt. Nur mit Einwilligung, auch fuer den Kauf, der erst im
 * Webhook bezahlt gemeldet wird: dort gilt, was beim Besuch galt.
 */
class MetaConversionsTest extends TestCase
{
    use WalksAFunnel;

    protected const PIXEL = '123456789012345';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'x'])]);
        config(['statamic-funnels.tracking.meta.access_token' => 'token-geheim']);
    }

    protected function tearDown(): void
    {
        TrackingConsent::resolveUsing(null);

        parent::tearDown();
    }

    protected function einwilligung(bool $granted): void
    {
        TrackingConsent::resolveUsing(fn () => ['addon' => true, 'granted' => $granted]);
    }

    protected function funnelMitPixel(): void
    {
        $this->kasse([], [], ['settings' => ['meta_pixel_id' => self::PIXEL]]);
    }

    /** @return list<array<string, mixed>> */
    protected function gesendet(string $name): array
    {
        $events = [];

        Http::recorded(function (HttpRequest $request) use (&$events, $name) {
            foreach ((array) ($request->data()['data'] ?? []) as $event) {
                $event = is_string($event) ? json_decode($event, true) : $event;

                if (($event['event_name'] ?? null) === $name) {
                    $events[] = $event + ['_url' => $request->url()];
                }
            }

            return true;
        });

        return $events;
    }

    #[Test]
    public function der_pixel_steht_geparkt_auf_der_seite_und_der_server_meldet_dasselbe_pageview(): void
    {
        $this->einwilligung(true);
        $this->funnelMitPixel();

        $seite = $this->asVisitor()->get('/f/kurs')->assertOk();

        $seite->assertSee('data-consent-service="meta_pixel"', false)
            ->assertSee("fbq('init', '".self::PIXEL."')", false);

        preg_match("/fbq\('track', 'PageView', \{\}, \{eventID: '([^']+)'\}\)/", $seite->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'kein PageView mit eventID im Pixel');

        $server = $this->gesendet('PageView');
        $this->assertCount(1, $server);
        $this->assertSame($m[1], $server[0]['event_id']);
        $this->assertStringContainsString('/'.self::PIXEL.'/events', $server[0]['_url']);
        $this->assertSame('website', $server[0]['action_source']);
    }

    #[Test]
    public function ohne_einwilligung_meldet_der_server_nichts(): void
    {
        $this->einwilligung(false);
        $this->funnelMitPixel();

        $this->asVisitor()->get('/f/kurs')->assertOk()
            // Der Pixel steht geparkt da, damit er nach der Einwilligung
            // startet, ohne dass die Seite neu laden muss.
            ->assertSee('data-consent-service="meta_pixel"', false);

        Http::assertNothingSent();
    }

    #[Test]
    public function ohne_zugangsschluessel_gibt_es_nur_den_pixel(): void
    {
        config(['statamic-funnels.tracking.meta.access_token' => null]);
        $this->einwilligung(true);
        $this->funnelMitPixel();

        $this->asVisitor()->get('/f/kurs')->assertOk()->assertSee("fbq('init'", false);

        Http::assertNothingSent();
    }

    #[Test]
    public function ohne_pixel_id_passiert_nichts(): void
    {
        $this->einwilligung(true);
        $this->kasse();

        $this->asVisitor()->get('/f/kurs')->assertOk()->assertDontSee('fbq(', false);

        Http::assertNothingSent();
    }

    #[Test]
    public function initiate_checkout_traegt_auf_beiden_wegen_dieselbe_id(): void
    {
        $this->einwilligung(true);
        $this->funnelMitPixel();
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();
        preg_match("/fbq\('track', 'InitiateCheckout', \{[^}]*\}, \{eventID: '([^']+)'\}\)/", $seite->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, 'kein InitiateCheckout mit eventID an der Kasse');

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])->assertRedirect();

        $server = $this->gesendet('InitiateCheckout');
        $this->assertCount(1, $server);
        $this->assertSame($m[1], $server[0]['event_id']);
        $this->assertEquals(99.0, $server[0]['custom_data']['value']);
        $this->assertSame('EUR', $server[0]['custom_data']['currency']);
    }

    #[Test]
    public function purchase_kommt_vom_webhook_und_vom_danke_pixel_mit_derselben_id(): void
    {
        $this->einwilligung(true);
        $this->funnelMitPixel();
        $this->bisZurKasse('Kaeufer@Example.com');
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = $this->bezahlen(Payment::query()->firstOrFail(), 'kaeufer@example.com');

        $server = $this->gesendet('Purchase');
        $this->assertCount(1, $server);
        $this->assertSame('purchase-'.$zahlung->id, $server[0]['event_id']);
        $this->assertEquals(99.0, $server[0]['custom_data']['value']);
        $this->assertSame((string) $zahlung->id, $server[0]['custom_data']['order_id']);
        // Gehasht, kleingeschrieben, und nur das: keine Adresse im Klartext.
        $this->assertSame([hash('sha256', 'kaeufer@example.com')], $server[0]['user_data']['em']);
        $this->assertStringNotContainsString('kaeufer@example.com', json_encode($server[0]));

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee("fbq('track', 'Purchase', {value: 99.00, currency: 'EUR'}, {eventID: 'purchase-".$zahlung->id."'})", false);
    }

    #[Test]
    public function auch_ein_frueherer_kauf_des_laufs_wird_gemeldet(): void
    {
        // Der Webhook des ersten Kaufs kommt, nachdem der Lauf schon den
        // naechsten angelegt hat: `payment_id` am Besuch zeigt dann auf den.
        $this->einwilligung(true);
        $this->funnelMitPixel();
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $erste = Payment::query()->firstOrFail();
        $this->besuch()->forceFill(['payment_id' => $erste->id + 1000])->save();

        $this->bezahlen($erste);

        $server = $this->gesendet('Purchase');
        $this->assertCount(1, $server);
        $this->assertSame('purchase-'.$erste->id, $server[0]['event_id']);
    }

    #[Test]
    public function ohne_einwilligung_beim_besuch_meldet_der_webhook_keinen_kauf(): void
    {
        $this->einwilligung(false);
        $this->funnelMitPixel();
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        // Die Einwilligung spaeter, im Webhook, zaehlt nicht: dort gibt es
        // keinen Browser, und gilt, was beim Besuch galt.
        $this->einwilligung(true);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->assertCount(0, $this->gesendet('Purchase'));
    }

    #[Test]
    public function ein_kauf_ausserhalb_eines_funnels_meldet_nichts(): void
    {
        $this->einwilligung(true);
        $this->funnelMitPixel();

        $zahlung = Payment::create(['provider' => 'fake', 'provider_id' => 'tr_x', 'product' => 'kurs', 'amount_cent' => 100, 'currency' => 'EUR', 'status' => Payment::STATUS_OPEN]);
        $this->gateway->remote['tr_x'] = new RemotePayment('tr_x', Payment::STATUS_OPEN);
        $this->bezahlen($zahlung);

        $this->assertCount(0, $this->gesendet('Purchase'));
    }

    #[Test]
    public function der_testcode_geht_mit(): void
    {
        config(['statamic-funnels.tracking.meta.test_event_code' => 'TEST123']);
        $this->einwilligung(true);
        $this->funnelMitPixel();

        $this->asVisitor()->get('/f/kurs');

        Http::assertSent(fn (HttpRequest $r) => ($r->data()['test_event_code'] ?? null) === 'TEST123'
            && ($r->data()['access_token'] ?? null) === 'token-geheim');
    }
}
