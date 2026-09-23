<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * F5 und die Uebergabe aus statamic-offers 1.12.
 *
 * Der Gutschein aus dem Link, der frei gewaehlte Betrag, die Laenderregel, die
 * Danke-Stufe und die Gutschein-Bedingungen fuer Folgezahlungen. Jeder Test
 * prueft, was **danach in der Datenbank steht**, nicht nur, was die Seite zeigt.
 */
class CouponLinkAndBasketTest extends TestCase
{
    use WalksAFunnel;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Sonst kann dieser Betrieb keine Abos beginnen, und ein Angebot mit
        // Rhythmus wird abgelehnt, bevor der Gutschein eine Rolle spielt.
        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->brauchtOffers112();
    }

    protected function coupon(array $spalten = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'CHOR20',
            'name' => 'Chorrabatt',
            'percent' => 20,
            'active' => true,
        ], $spalten));
    }

    // ------------------------------------------------------------ Coupon-Link

    #[Test]
    public function der_code_aus_dem_link_steht_im_feld(): void
    {
        $this->kasse();
        $this->coupon();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse?coupon=CHOR20')
            ->assertOk()
            ->assertSee('name="coupon" value="CHOR20"', false);
    }

    #[Test]
    public function der_code_vom_einstieg_reist_bis_zur_kasse_mit(): void
    {
        // Der Flyer zeigt auf den Funnel, nicht auf die Kasse: zwischen Link
        // und Code-Feld liegen zwei Schritte ohne den Parameter.
        $this->kasse();
        $this->coupon();

        $this->asVisitor()->get('/f/kurs?coupon=chor20');
        $this->asVisitor()->post('/f/kurs/entry_1/advance');
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'k@example.com']);

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertSee('name="coupon" value="CHOR20"', false);
    }

    #[Test]
    public function ein_unbekannter_code_bricht_nichts_und_belegt_nichts_vor(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse?coupon=GIBTSNICHT')
            ->assertOk()
            ->assertSee('name="coupon" value=""', false)
            ->assertDontSee('GIBTSNICHT');
    }

    #[Test]
    public function vorbelegt_heisst_nicht_eingeloest(): void
    {
        $this->kasse();
        $coupon = $this->coupon(['max_uses' => 5]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse?coupon=CHOR20');

        $this->assertSame(0, (int) $coupon->fresh()->used_count);
    }

    #[Test]
    public function lehnt_die_kasse_ab_wird_der_code_nicht_verbraucht(): void
    {
        if (! method_exists(Basket::class, 'releaseCoupon')) {
            $this->markTestSkipped('statamic-offers ohne Basket::releaseCoupon().');
        }

        $this->kasse();
        $coupon = $this->coupon(['max_uses' => 1]);
        $this->bisZurKasse();

        // Der Anbieter nimmt die Zahlung nicht an.
        $this->gateway->refuse = true;

        // Zurueck zur Kasse mit einem Satz, keine Fehlerseite.
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'coupon' => 'CHOR20'])
            ->assertRedirect()
            ->assertSessionHasErrors('offer');

        // Der eine erlaubte Einsatz steht noch zur Verfuegung.
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
    }

    // ------------------------------------------------------ Zahl, was du willst

    #[Test]
    public function die_kasse_zeigt_das_betragsfeld_mit_vorschlag_und_grenzen(): void
    {
        $this->kasse(['price_mode' => 'pwyw', 'amount_cent' => null, 'pwyw_min_cent' => 1000, 'pwyw_suggested_cent' => 2500, 'pwyw_max_cent' => 20000]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertOk()
            ->assertSee('name="amount"', false)
            ->assertSee('value="25.00"', false)
            ->assertSee('min="10"', false)
            ->assertSee('max="200"', false);
    }

    #[Test]
    public function der_gewaehlte_betrag_wird_abgebucht(): void
    {
        $this->kasse(['price_mode' => 'pwyw', 'amount_cent' => null, 'pwyw_min_cent' => 1000, 'pwyw_suggested_cent' => 2500, 'pwyw_max_cent' => 20000]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'amount' => '42,50'])
            ->assertRedirect();

        $zahlung = Payment::query()->firstOrFail();
        $this->assertSame(4250, $zahlung->amount_cent);
        $this->assertSame('offer:kurs:=4250', $zahlung->product);
    }

    #[Test]
    public function ohne_betragsfeld_gilt_der_vorschlag_und_nie_null(): void
    {
        // Eine eigene Vorlage ohne Feld. Aus dem fehlenden Feld darf keine 0
        // werden: bei Mindestpreis 0 waere das ein Gratiskauf.
        $this->kasse(['price_mode' => 'pwyw', 'amount_cent' => null, 'pwyw_min_cent' => 0, 'pwyw_suggested_cent' => 2500, 'pwyw_max_cent' => 20000]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])->assertRedirect();

        $this->assertSame(2500, Payment::query()->firstOrFail()->amount_cent);
    }

    #[Test]
    public function ein_betrag_unter_dem_minimum_kauft_nichts_und_sagt_warum(): void
    {
        $this->kasse(['price_mode' => 'pwyw', 'amount_cent' => null, 'pwyw_min_cent' => 1000, 'pwyw_max_cent' => 20000]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'amount' => '5'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function die_danke_seite_nennt_die_danke_stufe_des_betrags(): void
    {
        $this->kasse([
            'price_mode' => 'pwyw', 'amount_cent' => null, 'pwyw_min_cent' => 1000, 'pwyw_max_cent' => 20000,
            'pwyw_thanks' => [
                ['from_cent' => 0, 'text' => 'Danke, dass du dabei bist.'],
                ['from_cent' => 5000, 'text' => 'Du traegst den Workshop fuer andere mit.'],
            ],
        ]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'amount' => '60']);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->asVisitor()->get('/f/kurs/danke')
            ->assertOk()
            ->assertSee('Du traegst den Workshop fuer andere mit.');
    }

    // ------------------------------------------------------------ Laenderregel

    #[Test]
    public function mit_laenderregel_fragt_die_kasse_nach_dem_land(): void
    {
        $this->kasse(['country_mode' => 'only', 'countries' => ['DE', 'AT']]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertOk()
            ->assertSee('name="country"', false)
            ->assertSee('value="AT"', false)
            // Nur die erlaubten Laender stehen zur Wahl.
            ->assertDontSee('value="FR"', false);
    }

    #[Test]
    public function ohne_laenderregel_fragt_die_kasse_nicht(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertDontSee('name="country"', false);
    }

    #[Test]
    public function ein_ausgeschlossenes_land_kauft_nicht_und_liest_den_satz_fuer_kaeufer(): void
    {
        $this->kasse(['country_mode' => 'except', 'countries' => ['FR']]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'country' => 'FR'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertSee(__('statamic-offers::messages.country_not_available'), false);
    }

    #[Test]
    public function ein_erlaubtes_land_kauft_und_steht_an_der_zahlung(): void
    {
        $this->kasse(['country_mode' => 'only', 'countries' => ['DE', 'AT']]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'country' => 'at'])
            ->assertRedirect();

        $zahlung = Payment::query()->firstOrFail();
        $this->assertSame('AT', $zahlung->country);
        $this->assertSame('AT', $this->besuch()->meta['billing']['country'] ?? null);
    }

    #[Test]
    public function das_land_aus_der_anmeldung_genuegt(): void
    {
        $this->kasse(['country_mode' => 'only', 'countries' => ['DE']]);
        $this->funnelFragtNachAnschrift();
        $this->bisZurKasse('k@example.com', ['name' => 'K', 'street' => 'Weg 1', 'postal_code' => '12345', 'city' => 'Ort', 'country' => 'DE']);

        $this->asVisitor()->get('/f/kurs/kasse')->assertDontSee('name="country"', false);

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])->assertRedirect();

        $this->assertSame(1, Payment::count());
    }

    protected function funnelFragtNachAnschrift(): void
    {
        $schritt = FunnelStep::query()->where('node_key', 'capture_1')->firstOrFail();
        $schritt->forceFill(['config' => ['billing' => 'full']])->save();
    }

    // ------------------------------------------------ Gutschein ueber den Kauf

    #[Test]
    public function die_bedingungen_fuer_folgezahlungen_haengen_an_der_ersten_zahlung(): void
    {
        $this->kasse(['amount_cent' => 2900, 'interval' => '1 month']);
        $this->coupon(['duration' => 'repeating', 'duration_cycles' => 3, 'applies_to' => 'main']);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'coupon' => 'CHOR20'])
            ->assertRedirect();

        $zahlung = Payment::query()->firstOrFail();

        $this->assertSame('CHOR20', $zahlung->meta['coupon']['code'] ?? null);
        $this->assertSame('repeating', $zahlung->meta['coupon']['duration'] ?? null);
        $this->assertSame(3, $zahlung->meta['coupon']['cycles'] ?? null);
    }

    #[Test]
    public function ein_funnelweiter_code_steht_bei_der_naechsten_kasse_wieder_da(): void
    {
        $funnel = $this->kasse();
        $this->zweiteKasse($funnel);
        $this->coupon(['funnel_wide' => true]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'coupon' => 'CHOR20']);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->asVisitor()->get('/f/kurs/zusatz')
            ->assertOk()
            ->assertSee('name="coupon" value="CHOR20"', false);
    }

    #[Test]
    public function ein_code_nur_fuer_diesen_kauf_reist_nicht_weiter(): void
    {
        $funnel = $this->kasse();
        $this->zweiteKasse($funnel);
        $this->coupon(['funnel_wide' => false]);
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'coupon' => 'CHOR20']);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->asVisitor()->get('/f/kurs/zusatz')
            ->assertOk()
            ->assertSee('name="coupon" value=""', false);
    }

    protected function zweiteKasse($funnel): void
    {
        Offer::create([
            'handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd',
            'slot' => 'standalone', 'active' => true,
        ]);

        $funnel->edges()->where('from_node_key', 'kasse')->where('from_output', 'accepted')->delete();
        $funnel->steps()->create(['node_key' => 'zusatz', 'type' => 'offer', 'slug' => 'zusatz', 'config' => ['offer' => 'cd']]);
        $funnel->edges()->create(['from_node_key' => 'kasse', 'to_node_key' => 'zusatz', 'from_output' => 'accepted']);
        $funnel->edges()->create(['from_node_key' => 'zusatz', 'to_node_key' => 'finish_1', 'from_output' => 'accepted']);
    }
}
