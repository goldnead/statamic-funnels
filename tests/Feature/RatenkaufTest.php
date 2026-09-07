<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Http\Controllers\Web\FunnelController;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Angebot, das in Raten bezahlt wird.
 *
 * **Der Fehler, gegen den diese Datei geschrieben ist, sah aus wie ein
 * Verkauf.** Die Kasse rief `Checkout::start()`, und das kennt keine
 * Zahlungsrhythmen. Ein Angebot mit `interval` (statamic-offers 1.8.0) wurde
 * damit genau einmal abgebucht: bei „3 × 520 €" flossen 520 €, der Zugang wurde
 * vollstaendig erteilt, und die beiden fehlenden Raten tauchten nirgends auf —
 * keine Fehlermeldung, keine offene Forderung, kein Log-Eintrag. Der Katalog
 * gab den Plan seit 1.8.0 korrekt heraus; auf diesem Weg fragte ihn niemand.
 *
 * Vier Eigenschaften, jede mit einem Test, der sie zu brechen versucht:
 *
 * 1. Aus einem Ratenangebot wird eine Vereinbarung, nicht eine Zahlung.
 * 2. Ein gewoehnliches Angebot bleibt eine Zahlung — die Weiche darf nicht
 *    alles einsammeln.
 * 3. Die Abkuerzung ueber die gespeicherte Karte gilt fuer Raten nicht. Sie
 *    belastet einmal und legt keine Vereinbarung an; sie waere also genau
 *    derselbe stille Fehler noch einmal, eine Methode weiter oben.
 * 4. Kann der Betrieb keine Vereinbarungen, wird die Ratenoption abgelehnt
 *    statt einmal abgebucht.
 */
class RatenkaufTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * Die Kette, die ohne eine echte Auswahl in der Kasse verkaufbar ist:
     * voller Preis, und wer ablehnt, sieht die Raten.
     */
    protected function funnel(): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'kurs',
            'title' => 'Kurs',
            'published' => true,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'kasse', 'type' => 'offer', 'label' => 'Kasse', 'slug' => 'kasse', 'config' => ['offer' => 'kurs-voll']],
            ['node_key' => 'kasse_raten', 'type' => 'offer', 'label' => 'In Raten', 'slug' => 'raten', 'config' => ['offer' => 'kurs-raten']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'kasse', 'from_output' => 'default'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'kasse_raten', 'from_output' => 'declined'],
            ['from_node_key' => 'kasse_raten', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse_raten', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
        ]);

        // Ein Produkt, zwei Angebote. Genau der Punkt von statamic-offers 1.8.0:
        // „dasselbe in drei Raten" braucht kein zweites Produkt.
        Offer::create([
            'handle' => 'kurs-voll',
            'name' => 'Kurs',
            'product' => 'kurs',
            'amount_cent' => 9900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        Offer::create([
            'handle' => 'kurs-raten',
            'name' => 'Kurs in drei Raten',
            'product' => 'kurs',
            'amount_cent' => 3500,
            'interval' => '1 month',
            'times' => 3,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Bis vor die Kasse laufen, wie ein Besucher. */
    protected function bisZurKasse(string $email = 'k@example.com'): void
    {
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => $email]);
    }

    #[Test]
    public function ein_ratenangebot_legt_eine_vereinbarung_an_statt_einer_einzelzahlung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/raten');
        $this->asVisitor()->post('/f/kurs/kasse_raten/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();

        $this->assertNotNull($zahlung, 'es wurde gar keine Zahlung angelegt');
        $this->assertSame(3500, $zahlung->amount_cent);

        // Der Kern. Ohne diese Absicht ist die Zahlung eine gewoehnliche
        // Einmalzahlung — 35 € statt 105 €, und niemand erfaehrt davon.
        $absicht = $zahlung->meta['subscription_intent'] ?? null;

        $this->assertIsArray($absicht, 'die Zahlung traegt keine Absicht, eine Vereinbarung zu beginnen');
        $this->assertSame('offer:kurs-raten', $absicht['product']);
        $this->assertSame('1 month', $absicht['interval']);
        $this->assertSame(3, $absicht['times']);
    }

    #[Test]
    public function aus_der_bezahlten_ersten_rate_wird_die_vereinbarung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/raten');
        $this->asVisitor()->post('/f/kurs/kasse_raten/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();
        $this->gateway->markPaid($zahlung->provider_id, 'k@example.com', '9996', 'Mastercard');
        app(Fulfilment::class)->handle($zahlung->provider_id);

        $abo = Subscription::first();

        $this->assertNotNull($abo, 'die bezahlte erste Rate hat keine Vereinbarung hinterlassen');
        $this->assertSame('offer:kurs-raten', $abo->product);
        $this->assertSame(3500, $abo->amount_cent);

        // Zwei verbleibende Einzuege: die erste Rate ist geflossen.
        $this->assertSame(2, $abo->times);
    }

    #[Test]
    public function ein_gewoehnliches_angebot_bleibt_eine_einzelne_zahlung(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse');
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();

        $this->assertSame(9900, $zahlung->amount_cent);
        $this->assertArrayNotHasKey('subscription_intent', (array) $zahlung->meta);
    }

    #[Test]
    public function die_abkuerzung_ueber_die_gespeicherte_karte_gilt_fuer_raten_nicht(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        // Erst den vollen Preis kaufen. Danach liegt ein Mandat vor, und der
        // Ein-Klick-Weg stuende offen.
        $this->asVisitor()->get('/f/kurs/kasse');
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $erste = Payment::latest('id')->first();
        $this->gateway->markPaid($erste->provider_id, 'k@example.com', '9996', 'Mastercard');
        app(Fulfilment::class)->handle($erste->provider_id);

        $this->assertNotNull($erste->fresh()->customer_reference, 'ohne Mandat prueft dieser Test nichts');

        // Jetzt dasselbe Produkt in Raten, im selben Lauf.
        $this->asVisitor()->get('/f/kurs/raten');
        $this->asVisitor()->post('/f/kurs/kasse_raten/advance', ['accept' => '1', 'confirmed' => '1']);

        $zweite = Payment::latest('id')->first();

        $this->assertNotSame($erste->id, $zweite->id, 'es wurde gar keine zweite Zahlung angelegt');

        // Waere der Ein-Klick-Weg genommen worden, hinge die Zahlung an der
        // ersten und truege keine Absicht: die hinterlegte Karte einmal
        // belastet, Vereinbarung keine.
        $this->assertNull($zweite->parent_payment_id);
        $this->assertIsArray($zweite->meta['subscription_intent'] ?? null);
    }

    /**
     * Die Kassenseite muss den Rhythmus nennen, nicht nur den heutigen Betrag.
     *
     * § 312j Abs. 2 BGB will Gesamtpreis und Laufzeit unmittelbar ueber dem
     * Bestellknopf. Ohne diesen Schluessel kann eine Vorlage nur raten — und
     * die auf adriangoldner.com riet falsch: sie schrieb „Einmalig · kein Abo"
     * ueber einen Vertrag ueber drei Raten, weil sie nichts Besseres wusste.
     */
    #[Test]
    public function die_kassenseite_bekommt_den_rhythmus_mit(): void
    {
        $this->funnel();
        $this->app->setLocale('de');

        $raten = $this->angebotsdaten('kasse_raten');

        $this->assertIsArray($raten['plan'] ?? null, 'die Seite erfaehrt nichts vom Rhythmus');
        $this->assertSame('1 month', $raten['plan']['interval']);
        $this->assertSame('monatlich', $raten['plan']['interval_label']);
        $this->assertSame(3, $raten['plan']['times']);
        $this->assertSame(2, $raten['plan']['times_remaining']);

        // Der Gesamtpreis, nicht die Rate: 3 x 35 €.
        $this->assertSame('105.00', $raten['plan']['total']);
        $this->assertSame('105,00', $raten['plan']['total_local']);
    }

    #[Test]
    public function ein_angebot_ohne_rhythmus_meldet_keinen(): void
    {
        $this->funnel();

        // Null, nicht ein leeres Feld: eine Vorlage fragt `{{ if plan }}`, und
        // ein leeres Array waere dort wahr.
        $this->assertNull($this->angebotsdaten('kasse')['plan']);
    }

    /**
     * Was die Vorlage ueber das Angebot dieses Schritts erfaehrt.
     *
     * @return array<string, mixed>
     */
    protected function angebotsdaten(string $nodeKey): array
    {
        $steuerung = app(FunnelController::class);
        $methode = new \ReflectionMethod($steuerung, 'offerFor');
        $methode->setAccessible(true);

        return $methode->invoke($steuerung, FunnelStep::where('node_key', $nodeKey)->firstOrFail());
    }

    #[Test]
    public function ohne_mandate_wird_eine_ratenoption_abgelehnt_statt_einmal_abgebucht(): void
    {
        config(['statamic-payments.follow_up.collect_mandate' => false]);

        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/raten');
        $antwort = $this->asVisitor()->post('/f/kurs/kasse_raten/advance', ['accept' => '1', 'confirmed' => '1']);

        // Wer „3 × 35 €" gelesen hat und 35 € einmal bezahlt, hat nicht
        // dasselbe gekauft. Lieber gar kein Verkauf als der falsche.
        $antwort->assertSessionHasErrors('offer');
        $this->assertSame(0, Payment::count());
    }
}
