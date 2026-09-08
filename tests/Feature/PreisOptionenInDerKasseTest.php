<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Angebot, drei Zahlweisen, ausgewaehlt in der Kasse.
 *
 * Der Vorgaenger dieser Datei ({@see RatenkaufTest}) belegt dieselbe Strecke
 * fuer **ein** Angebot mit einem Rhythmus. Hier waehlt der Kaeufer, und das ist
 * die Stelle, an der ein Preis aus dem Browser kommen koennte — deshalb prueft
 * jeder Test, was **danach in der Datenbank steht**: die Zahlung, die
 * Vereinbarung und die Rechnungszeile. Ein Test, der nur den Rueckgabewert der
 * Kasse liest, belegt, dass geantwortet wurde, und nicht, dass jemand das
 * Richtige gekauft hat.
 */
class PreisOptionenInDerKasseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);

        // Der Wortlaut der Rechnungszeile ist hier Gegenstand, nicht Beiwerk.
        $app['config']->set('app.locale', 'de');
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /** Ein Funnel mit genau einer Kasse, und dahinter ein Angebot mit drei Zahlweisen. */
    protected function funnel(?array $optionen = null): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'kurs',
            'title' => 'Kurs',
            'published' => true,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'kasse', 'type' => 'offer', 'label' => 'Kasse', 'slug' => 'kasse', 'config' => ['offer' => 'kurs']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'kasse', 'from_output' => 'default'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
        ]);

        Offer::create([
            'handle' => 'kurs',
            'name' => 'Kurs',
            'product' => 'kurs',
            'amount_cent' => 9900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
            'pricing_options' => $optionen ?? [
                ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 9900],
                ['key' => 'raten3', 'label' => 'In 3 Raten', 'amount_cent' => 3500, 'interval' => '1 month', 'times' => 3],
                ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 1900, 'interval' => '1 month'],
            ],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function bisZurKasse(string $email = 'k@example.com'): void
    {
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => $email]);
    }

    /** Kaufen und den Anbieter „bezahlt" melden lassen. */
    protected function kaufen(string $option): Payment
    {
        $this->asVisitor()->get('/f/kurs/kasse');
        $this->asVisitor()->post('/f/kurs/kasse/advance', [
            'accept' => '1',
            'confirmed' => '1',
            'pricing_option' => $option,
        ]);

        $zahlung = Payment::latest('id')->first();

        $this->assertNotNull($zahlung, 'es wurde gar keine Zahlung angelegt');

        $this->gateway->markPaid($zahlung->provider_id, 'k@example.com', '9996', 'Mastercard');
        app(Fulfilment::class)->handle($zahlung->provider_id);

        return $zahlung->fresh();
    }

    #[Test]
    public function die_kasse_zeigt_die_drei_zahlweisen_zur_auswahl(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse');

        $seite->assertOk()
            ->assertSee('name="pricing_option" value="voll"', false)
            ->assertSee('name="pricing_option" value="raten3"', false)
            ->assertSee('name="pricing_option" value="abo"', false)
            ->assertSee('In 3 Raten');

        // Der Rhythmus steht an der Zeile, nicht irgendwo darunter: § 312j
        // Abs. 2 BGB will Gesamtpreis und Laufzeit dort, wo bestellt wird.
        $seite->assertSee('3 × 35,00 EUR', false);
    }

    #[Test]
    public function einmalig_bucht_einmal_ab_und_laesst_keine_vereinbarung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $zahlung = $this->kaufen('voll');

        $this->assertSame(9900, $zahlung->amount_cent);
        $this->assertSame('offer:kurs:voll', $zahlung->product);
        $this->assertSame(0, Subscription::count());
        $this->assertArrayNotHasKey('subscription_intent', (array) $zahlung->meta);
        $this->assertSame('Kurs', $zahlung->items()->first()->name);
    }

    #[Test]
    public function raten_bucht_die_rate_ab_und_nennt_das_ganze_auf_der_rechnung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $zahlung = $this->kaufen('raten3');

        // Die Rate, nicht die Summe. Der Fehler, den diese Familie am
        // teuersten bezahlt hat, sah genau andersherum aus: 35 € geflossen,
        // Zugang erteilt, zwei Raten nirgends.
        $this->assertSame(3500, $zahlung->amount_cent);
        $this->assertSame('offer:kurs:raten3', $zahlung->product);

        $abo = Subscription::query()->firstOrFail();
        $this->assertSame('offer:kurs:raten3', $abo->product);
        $this->assertSame('1 month', $abo->interval);
        // Zwei verbleibende Einzuege: die erste Rate ist geflossen.
        $this->assertSame(2, $abo->times);

        $this->assertSame(
            'Kurs — Rate 1 von 3 (Gesamt 105,00 €)',
            $zahlung->items()->first()->name,
        );
    }

    #[Test]
    public function abo_laeuft_ohne_ende_und_ohne_gesamtsumme_auf_der_rechnung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $zahlung = $this->kaufen('abo');

        $this->assertSame(1900, $zahlung->amount_cent);

        $abo = Subscription::query()->firstOrFail();
        $this->assertSame('1 month', $abo->interval);
        $this->assertNull($abo->times);

        // Eine Gesamtsumme steht erst fest, wenn gekuendigt wird.
        $this->assertStringNotContainsString('Gesamt', $zahlung->items()->first()->name);
    }

    #[Test]
    public function eine_ratenoption_ohne_mandatfaehige_zahlart_startet_keinen_kauf(): void
    {
        // Ein Betrieb, der nur Klarna und Ueberweisung freigeschaltet hat.
        // Keine der beiden hinterlaesst ein Mandat: die erste Rate flosse, und
        // die zweite und dritte kaemen nie — ohne Fehlermeldung, ohne offene
        // Forderung.
        config(['statamic-payments.methods' => ['klarna', 'banktransfer']]);

        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse');
        $this->asVisitor()
            ->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'pricing_option' => 'raten3'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Subscription::count());
    }

    #[Test]
    public function eine_zahlweise_die_es_nicht_gibt_kauft_nichts(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse');

        // Ein veraltetes Formular, ein alter Link, eine geloeschte Option.
        // „Dann eben der Grundpreis" waere eine Abbuchung ueber 99 €, die
        // niemand angeklickt hat.
        $this->asVisitor()
            ->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'pricing_option' => 'gibtsnicht'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());

        // **Und der Kaeufer erfaehrt es.** Eine Verweigerung, die auf einer
        // Seite landet, die schweigt, ist von aussen nicht von einem kaputten
        // Knopf zu unterscheiden: gedrueckt, nichts passiert, kein Grund.
        $this->asVisitor()
            ->get('/f/kurs/kasse')
            ->assertSee(__('statamic-funnels::messages.pricing_option_missing'), false);
    }

    #[Test]
    public function ohne_auswahl_wird_nichts_gekauft_wenn_das_angebot_zahlweisen_fuehrt(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse');
        $this->asVisitor()
            ->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function ein_angebot_ohne_zahlweisen_verhaelt_sich_unveraendert(): void
    {
        // Der Bestandsschutz. Jedes Angebot, das es heute gibt, hat keine
        // `pricing_options` — und muss genau so weiter verkaufen wie gestern.
        $this->funnel(optionen: []);
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse');
        $seite->assertOk()->assertDontSee('name="pricing_option"', false);

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();

        $this->assertNotNull($zahlung);
        $this->assertSame(9900, $zahlung->amount_cent);
        $this->assertSame('offer:kurs', $zahlung->product);
        $this->assertSame(0, Subscription::count());
    }
}
