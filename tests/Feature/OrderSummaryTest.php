<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Bestelluebersicht auf der Danke-Seite.
 *
 * **Was vorher dastand.** Der ganze Text nach einem bezahlten Kauf war
 * „Danke. Das war alles von dieser Seite." Kein Produkt, kein Betrag, keine
 * Nummer, mit der jemand haette nachfragen koennen.
 *
 * Zwei Dinge duerfen dabei nicht passieren, und beide haben hier einen Test:
 * eine Bestaetigung zeigen, bevor bezahlt ist — und den Kauf verschweigen,
 * wegen dem der Kaeufer ueberhaupt da ist, weil danach noch ein Upsell kam.
 */
class OrderSummaryTest extends TestCase
{
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'kurs',
            'title' => 'Kurs',
            'published' => true,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->create([
            'from_node_key' => 'entry_1', 'to_node_key' => 'finish_1', 'from_output' => 'default',
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function zahlung(int $centBetrag, string $status = Payment::STATUS_PAID, string $produkt = 'offer:kurs'): Payment
    {
        return Payment::create([
            'provider' => 'mollie',
            'provider_id' => 'tr_'.uniqid(),
            'product' => $produkt,
            'amount_cent' => $centBetrag,
            'currency' => 'EUR',
            'status' => $status,
            'email' => 'maria@example.com',
            'paid_at' => $status === Payment::STATUS_PAID ? now() : null,
        ]);
    }

    /**
     * @param  array<array-key, int>  $paymentIds
     */
    protected function besuchMit(array $paymentIds, ?int $primary = null): FunnelVisit
    {
        $this->asVisitor()->get('/f/kurs/danke');

        $visit = FunnelVisit::query()->sole();

        $visit->forceFill([
            'email' => 'maria@example.com',
            'payment_id' => $primary ?? (end($paymentIds) ?: null),
            'meta' => ['payments' => $paymentIds],
        ])->save();

        return $visit;
    }

    #[Test]
    public function ohne_zahlung_gibt_es_keine_uebersicht(): void
    {
        $this->funnel();

        // Ein Funnel ohne Kauf hat keine Bestellung. Eine leere Uebersicht mit
        // „0,00 EUR" waere schlechter als keine.
        $this->asVisitor()
            ->get('/f/kurs/danke')
            ->assertOk()
            ->assertDontSee('Your order');
    }

    #[Test]
    public function eine_haengende_zahlung_wird_nicht_als_bestellung_gezeigt(): void
    {
        $this->funnel();
        $payment = $this->zahlung(1400, Payment::STATUS_OPEN);
        $this->besuchMit([$payment->id]);

        // Der Webhook entscheidet, ob Geld geflossen ist. Bis dahin waere eine
        // Bestaetigung eine Zusage, die noch zurueckgenommen werden kann.
        $this->asVisitor()->get('/f/kurs/danke')->assertOk()->assertDontSee('Your order');
    }

    #[Test]
    public function eine_bezahlte_zahlung_steht_mit_betrag_und_nummer_da(): void
    {
        $this->funnel();
        $payment = $this->zahlung(123900);
        $this->besuchMit([$payment->id]);

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee('Your order')
            // Deutsch geschrieben. `1,239.00` war in diesem Addon schon einmal
            // ein Fehler, und auf einer Kaufbestaetigung faellt er teuer aus.
            ->assertSee('1.239,00 EUR')
            ->assertSee('Order number:')
            ->assertSee((string) $payment->id)
            ->assertSee('maria@example.com');
    }

    #[Test]
    public function ein_upsell_verdeckt_den_ersten_kauf_nicht(): void
    {
        $this->funnel();

        $erst = $this->zahlung(1400);
        $upsell = $this->zahlung(3900, Payment::STATUS_PAID, 'offer:kurs-upsell');

        // `payment_id` zeigt auf den zuletzt gemachten Kauf. Wer nur den liest,
        // zeigt dem Kaeufer den Upsell und verschweigt das, wofuer er
        // eigentlich gekommen ist.
        $this->besuchMit(['offer_1' => $erst->id, 'upsell_1' => $upsell->id], $upsell->id);

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee('14,00 EUR')
            ->assertSee('39,00 EUR')
            // Summe ueber beide Zahlungen, nicht nur die letzte.
            ->assertSee('53,00 EUR')
            // Die Nummer der ersten: das ist der Kauf, wegen dem er hier steht.
            ->assertSee((string) $erst->id);
    }

    #[Test]
    public function die_zeilen_einer_zahlung_werden_einzeln_aufgefuehrt(): void
    {
        $this->funnel();

        $payment = $this->zahlung(3100);
        $payment->items()->createMany([
            ['product' => 'offer:kurs', 'name' => 'Der Kurs', 'amount_cent' => 1400, 'quantity' => 1, 'discount_cent' => 0, 'kind' => 'primary'],
            ['product' => 'offer:noten', 'name' => 'Das Notenpaket dazu', 'amount_cent' => 1700, 'quantity' => 1, 'discount_cent' => 0, 'kind' => 'bump'],
        ]);

        $this->besuchMit([$payment->id]);

        // Ein Bump ist eine eigene Zeile und wurde einzeln gekauft. Als
        // Sammelposten „31,00 EUR" koennte der Kaeufer nicht pruefen, wofuer
        // er bezahlt hat.
        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee('Der Kurs')
            ->assertSee('Das Notenpaket dazu')
            ->assertSee('14,00 EUR')
            ->assertSee('17,00 EUR');
    }
}
