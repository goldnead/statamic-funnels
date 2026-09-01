<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Rechnungsanschrift am Capture-Schritt.
 *
 * **Warum es das gibt.** Der Schritt konnte `email` und `name`, mehr nicht.
 * Ueber 250 Euro verlangt § 14 UStG Name und Anschrift des Empfaengers; der
 * `InvoiceWriter` verweigert dann die Rechnung, statt zu raten. Belegt an
 * Payment 23 ueber 347 Euro vom 31.08.2026: bezahlt, keine Rechnung, nur eine
 * Warnung im Log. Damit war keiner der drei Coaching-Funnels verkaufbar.
 *
 * Die Frage, die jeder Test hier stellt: **kommt die Anschrift an der Zahlung
 * an, bevor der Anbieter gerufen wird?** Nachtragen ist zu spaet — der Webhook
 * kann vorher da sein, und eine ausgestellte Rechnung ist nicht mehr zu aendern.
 */
class BillingFieldsTest extends TestCase
{
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * Die Seite ansehen, bevor das Formular abgeschickt wird.
     *
     * Der Besuch entsteht beim Betrachten, nicht beim Absenden — ein Browser
     * macht das von selbst, der Testclient nicht. Ohne diesen Schritt liefe
     * jeder POST gegen einen Besuch, den es nicht gibt, und alle Zusicherungen
     * darunter pruefen nichts.
     */
    protected function amSchritt(string $slug): static
    {
        $this->asVisitor()->get('/f/coaching-fokus/'.$slug)->assertOk();

        return $this->asVisitor();
    }

    protected function funnel(string $billing = CaptureStep::BILLING_FULL): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'coaching-fokus',
            'title' => 'Coaching Fokus',
            'published' => true,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Angaben', 'slug' => 'angaben', 'config' => ['billing' => $billing]],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'fokus-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function offer(): Offer
    {
        return Offer::create([
            'handle' => 'fokus-angebot',
            'name' => 'Fokus-Paket',
            'product' => 'kurs',
            // Ueber der Grenze von 250 Euro. Genau der Betrag, bei dem die
            // Rechnung ohne Anschrift ausfaellt.
            'amount_cent' => 34700,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $ueberschreiben
     * @return array<string, string>
     */
    protected function vollstaendig(array $ueberschreiben = []): array
    {
        return array_merge([
            'email' => 'maria@example.com',
            'name' => 'Maria Beispiel',
            'street' => 'Beispielweg 3',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'country' => 'de',
        ], $ueberschreiben);
    }

    #[Test]
    public function ohne_anschrift_kommt_der_besucher_nicht_weiter(): void
    {
        $this->funnel();

        // Der ganze Punkt: es ist besser, hier stehenzubleiben als bezahlt und
        // ohne Rechnung dazustehen. Vier Fehler, nicht einer — der Besucher
        // soll alles auf einmal sehen und nicht viermal absenden.
        $this->amSchritt('angaben')
            ->post('/f/coaching-fokus/capture_1/advance', ['email' => 'maria@example.com'])
            ->assertSessionHasErrors(['name', 'street', 'postal_code', 'city', 'country']);
    }

    #[Test]
    public function ein_lead_magnet_fragt_weiterhin_nur_nach_der_adresse(): void
    {
        $this->funnel(CaptureStep::BILLING_MINIMAL);

        // Die Gegenprobe zum Test darueber. Waere die Anschrift immer Pflicht,
        // haette dieser Umbau jeden bestehenden Funnel unbenutzbar gemacht —
        // und zwar erst beim naechsten Besucher, nicht beim Deploy.
        $this->amSchritt('angaben')
            ->post('/f/coaching-fokus/capture_1/advance', ['email' => 'maria@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('maria@example.com', FunnelVisit::query()->sole()->email);
    }

    #[Test]
    public function die_anschrift_liegt_danach_am_besuch(): void
    {
        $this->funnel();

        $this->amSchritt('angaben')
            ->post('/f/coaching-fokus/capture_1/advance', $this->vollstaendig())
            ->assertSessionHasNoErrors();

        $visit = FunnelVisit::query()->sole();

        $this->assertSame([
            'street' => 'Beispielweg 3',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            // Gross geschrieben abgelegt, weil `payments.country` ISO-Codes
            // fuehrt und „de" und „DE" sonst zwei Laender waeren.
            'country' => 'DE',
        ], $visit->meta['billing']);
    }

    #[Test]
    public function die_zahlung_traegt_anschrift_und_land(): void
    {
        $this->funnel();
        $this->offer();

        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', $this->vollstaendig());
        $this->amSchritt('angebot')->post('/f/coaching-fokus/offer_1/advance', ['accept' => 1, 'confirmed' => 1]);

        $payment = Payment::query()->sole();

        // Der eigentliche Prueffall: die Anschrift steht an der Zahlung, bevor
        // der Anbieter gerufen wurde. Nachgetragen waere sie ein Rennen gegen
        // den Webhook, und der `InvoiceWriter` liest sie in dem Moment, in dem
        // die Zahlung bezahlt gemeldet wird.
        $this->assertSame("Beispielweg 3\n12345 Musterstadt", $payment->meta['address'] ?? null);
        $this->assertSame('DE', $payment->country);
    }

    #[Test]
    public function auch_ein_upsell_ueber_die_gespeicherte_karte_traegt_die_anschrift(): void
    {
        $funnel = $this->funnel();
        $this->offer();

        // Ein zweites Angebot im selben Lauf: der Upsell nach der Danke-Seite.
        // Er wird ueber die gespeicherte Karte abgebucht und geht deshalb NICHT
        // durch `Checkout::start()`, sondern durch `FollowUp::accept()`.
        Offer::create([
            'handle' => 'fokus-upsell',
            'name' => 'Noch eine Sitzung',
            'product' => 'kurs',
            'amount_cent' => 30000,
            'slot' => Offer::SLOT_POST_PURCHASE,
            'active' => true,
        ]);

        $funnel->steps()->create([
            'node_key' => 'upsell_1', 'type' => 'offer', 'label' => 'Upsell',
            'slug' => 'noch-eine', 'config' => ['offer' => 'fokus-upsell'],
        ]);

        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', $this->vollstaendig());
        $this->amSchritt('angebot')->post('/f/coaching-fokus/offer_1/advance', ['accept' => 1, 'confirmed' => 1]);

        // Die erste Zahlung bezahlt melden, damit die zweite ueber die
        // gespeicherte Karte laufen kann.
        $erst = Payment::query()->sole();
        $erst->forceFill([
            'status' => Payment::STATUS_PAID,
            'paid_at' => now(),
            'customer_reference' => 'cst_test',
        ])->save();

        $this->amSchritt('noch-eine')->post('/f/coaching-fokus/upsell_1/advance', ['accept' => 1, 'confirmed' => 1]);

        $zweite = Payment::query()->where('id', '!=', $erst->id)->first();

        $this->assertNotNull($zweite, 'Ohne zweite Zahlung prueft der Rest hier nichts.');

        // `FollowUp::accept()` uebernimmt von der Vorgaengerzahlung Adresse,
        // Name und Mandat — aber NICHT Land und Anschrift. Ohne die Uebergabe
        // hier haette ein Upsell ueber 250 Euro wieder keine Rechnung bekommen,
        // an einer zweiten Stelle derselben Methode.
        $this->assertSame("Beispielweg 3\n12345 Musterstadt", $zweite->meta['address'] ?? null);
        $this->assertSame('DE', $zweite->country);
    }

    #[Test]
    public function eine_halbe_anschrift_kommt_gar_nicht_an_die_zahlung(): void
    {
        // Ein Funnel, der nur den Namen verlangt, laesst die Adressfelder
        // durch, wenn jemand sie doch mitschickt — sie sind `nullable`. Eine
        // Zeile ohne Ort ist aber keine Anschrift, und halb auf einer Rechnung
        // ist schlechter als gar nicht: ohne faellt sie laut aus, mit einer
        // halben entsteht sie falsch und ist danach nicht mehr zu aendern.
        $this->funnel(CaptureStep::BILLING_NAME);
        $this->offer();

        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', [
            'email' => 'maria@example.com',
            'name' => 'Maria Beispiel',
            'street' => 'Beispielweg 3',
        ]);
        $this->amSchritt('angebot')->post('/f/coaching-fokus/offer_1/advance', ['accept' => 1, 'confirmed' => 1]);

        $payment = Payment::query()->sole();

        $this->assertArrayNotHasKey('address', (array) ($payment->meta ?? []));
    }

    #[Test]
    public function ein_zweiter_schritt_ohne_adressfelder_loescht_die_anschrift_nicht(): void
    {
        $funnel = $this->funnel();

        // Ein zweiter Capture-Schritt, der nur die Adresse abfragt — etwa eine
        // Nachfrage spaeter im Weg. Wuerde er die Anschrift ueberschreiben,
        // platzte die Rechnung an einer Stelle, an der niemand nach der
        // Ursache sucht.
        $funnel->steps()->create([
            'node_key' => 'capture_2', 'type' => 'capture', 'label' => 'Nachfrage',
            'slug' => 'nachfrage', 'config' => ['billing' => CaptureStep::BILLING_MINIMAL],
        ]);

        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', $this->vollstaendig());
        $this->amSchritt('nachfrage')->post('/f/coaching-fokus/capture_2/advance', ['email' => 'maria@example.com']);

        $this->assertSame('Musterstadt', FunnelVisit::query()->sole()->meta['billing']['city'] ?? null);
    }

    #[Test]
    public function eine_andere_person_am_selben_rechner_erbt_die_anschrift_nicht(): void
    {
        $this->funnel();

        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', $this->vollstaendig());

        // Derselbe Cookie, andere Adresse: hier sitzt jemand anderes. Erbte er
        // die Anschrift, stuende sie auf seiner Rechnung — dieselbe
        // Fehlerklasse wie die fremde Karte, nur langsamer sichtbar.
        $this->amSchritt('angaben')->post('/f/coaching-fokus/capture_1/advance', [
            'email' => 'jonas@example.com',
            'name' => 'Jonas Beispiel',
            'street' => 'Andere Gasse 9',
            'postal_code' => '54321',
            'city' => 'Anderstadt',
            'country' => 'AT',
        ]);

        $billing = FunnelVisit::query()->sole()->meta['billing'];

        $this->assertSame('Anderstadt', $billing['city']);
        $this->assertSame('AT', $billing['country']);
    }
}
