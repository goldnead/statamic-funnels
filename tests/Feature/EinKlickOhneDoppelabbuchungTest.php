<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Discount;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Wirft der Ein-Klick-Kauf, nachdem der Anbieter schon belastet hat, darf die
 * Kasse nicht ein zweites Mal kassieren.
 *
 * `FollowUp::accept()` legt die Zeile an, ruft den Anbieter und arbeitet dann
 * weiter. Wirft es erst danach, liegt das Geld schon beim Anbieter; der
 * Rueckfall auf die Kasse liess den Kaeufer dieselbe Sache noch einmal zahlen.
 */
class EinKlickOhneDoppelabbuchungTest extends TestCase
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

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'fruehlingskurs', 'title' => 'Frühlingskurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'offer_2', 'type' => 'offer', 'label' => 'Noch etwas', 'slug' => 'noch-etwas', 'config' => ['offer' => 'cd-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'offer_2', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
            ['from_node_key' => 'offer_2', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_2', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
        ]);

        Offer::create(['handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs', 'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);
        Offer::create(['handle' => 'cd-angebot', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'amount_cent' => 2900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function paysOnce(string $email): Payment
    {
        $this->asVisitor()->get('/f/fruehlingskurs/anmeldung');
        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['email' => $email]);
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');
        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        $payment = Payment::latest('id')->first();
        $this->gateway->markPaid($payment->provider_id, $email, '9996', 'Mastercard');
        app(Fulfilment::class)->handle($payment->provider_id);

        return $payment->fresh();
    }

    /** Ein FollowUp, das abbucht und danach wirft, wie bei einem Fehler nach dem Anbieterruf. */
    protected function wirftNachDerAbbuchung(): void
    {
        $this->app->instance(FollowUp::class, new class($this->gateway) extends FollowUp
        {
            public function accept(Payment $original, string $productHandle, array $context = [], array|PaymentDetails $details = [], ?string $buyerEmail = null, ?Discount $discount = null): ?Payment
            {
                parent::accept($original, $productHandle, $context, $details, $buyerEmail, $discount);

                throw new RuntimeException('nach der Abbuchung');
            }
        });
    }

    /** Ein FollowUp, das wirft, bevor irgendetwas abgebucht ist. */
    protected function wirftVorDerAbbuchung(): void
    {
        $this->app->instance(FollowUp::class, new class($this->gateway) extends FollowUp
        {
            public function accept(Payment $original, string $productHandle, array $context = [], array|PaymentDetails $details = [], ?string $buyerEmail = null, ?Discount $discount = null): ?Payment
            {
                throw new RuntimeException('vor der Abbuchung');
            }
        });
    }

    #[Test]
    public function wirft_es_nach_der_abbuchung_gibt_es_keinen_zweiten_kassenweg(): void
    {
        $this->funnel();
        // Vor dem ersten Request: der Router haelt den Controller samt FollowUp fest.
        $this->wirftNachDerAbbuchung();
        $first = $this->paysOnce('erste@example.com');
        Exceptions::fake();

        $angelegt = $this->gateway->created;

        $this->asVisitor()->get('/f/fruehlingskurs/noch-etwas');
        $antwort = $this->asVisitor()->post('/f/fruehlingskurs/offer_2/advance', ['accept' => '1', 'confirmed' => '1']);

        $upsells = Payment::query()->where('id', '>', $first->id)->get();

        // Genau eine Zahlung fuer die CD: die Ein-Klick-Zeile. Keine Kasse dazu.
        $this->assertCount(1, $upsells, 'Der Rueckfall auf die Kasse hat ein zweites Mal kassiert.');
        $this->assertSame($first->id, $upsells->first()->parent_payment_id);
        $this->assertSame($angelegt + 1, $this->gateway->created, 'Nur die Folgeabbuchung, kein neuer Checkout.');
        $this->assertStringNotContainsString('checkout.example', (string) $antwort->headers->get('Location'));

        // Der Lauf kennt die Zahlung; ihr Webhook rueckt ihn vor.
        $visit = FunnelVisit::query()->firstOrFail();
        $this->assertSame($upsells->first()->id, data_get($visit->meta, 'payments.offer_2'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'nach der Abbuchung');
    }

    #[Test]
    public function wirft_es_vor_der_abbuchung_geht_es_weiter_ueber_die_kasse(): void
    {
        $this->funnel();
        $this->wirftVorDerAbbuchung();
        $first = $this->paysOnce('erste@example.com');
        Exceptions::fake();

        $this->asVisitor()->get('/f/fruehlingskurs/noch-etwas');
        $antwort = $this->asVisitor()->post('/f/fruehlingskurs/offer_2/advance', ['accept' => '1', 'confirmed' => '1']);

        // Nichts belastet, also der Umweg ueber die Kasse wie bisher.
        $antwort->assertSessionHasNoErrors();
        $this->assertStringContainsString('checkout.example', (string) $antwort->headers->get('Location'));
        $this->assertSame(1, Payment::query()->where('id', '>', $first->id)->count());

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'vor der Abbuchung');
    }
}
