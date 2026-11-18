<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Listeners\AdvanceOnPayment;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Der Webhook einer Zahlung findet seinen Besuch, auch wenn inzwischen eine
 * zweite Zahlung im selben Lauf begonnen hat.
 *
 * `rememberPending` schreibt `payment_id` und `pending_step` bei jeder neuen
 * Zahlung neu. Wer den Upsell anklickt, bevor der Webhook der ersten Zahlung
 * da ist, hat den Zeiger auf die erste schon verloren; der Listener darf sich
 * also nicht auf ihn verlassen.
 */
class ZahlungFindetIhrenBesuchTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        parent::setUp();

        // Auf einer Site findet Statamic den Listener in src/Listeners. Ob das
        // im Testaufbau schon geschehen ist, haengt an der Reihenfolge der
        // Provider; zweimal angemeldet liefe er je Zahlung doppelt.
        if (! Event::hasListeners(PaymentPaid::class) || ! collect(Event::getRawListeners()[PaymentPaid::class] ?? [])
            ->contains(fn ($l) => (is_array($l) ? $l[0] : $l) === AdvanceOnPayment::class || $l === AdvanceOnPayment::class.'@handle')) {
            Event::listen(PaymentPaid::class, [AdvanceOnPayment::class, 'handle']);
        }
    }

    protected function mitUpsell(): void
    {
        $funnel = $this->kasse();
        Offer::create(['handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'slot' => 'post_purchase', 'active' => true]);
        $funnel->edges()->where('from_node_key', 'kasse')->where('from_output', 'accepted')->delete();
        $funnel->steps()->create(['node_key' => 'upsell', 'type' => 'offer', 'slug' => 'upsell', 'config' => ['offer' => 'cd']]);
        $funnel->edges()->create(['from_node_key' => 'kasse', 'to_node_key' => 'upsell', 'from_output' => 'accepted']);
        $funnel->edges()->create(['from_node_key' => 'upsell', 'to_node_key' => 'finish_1', 'from_output' => 'accepted']);
        $funnel->edges()->create(['from_node_key' => 'upsell', 'to_node_key' => 'finish_1', 'from_output' => 'declined']);
    }

    /**
     * Die Kasse hat Zahlung eins begonnen; dann beginnt der Upsell Zahlung
     * zwei, bevor der Webhook zu eins kam. So steht es nach `rememberPending`.
     *
     * @return array{0: Payment, 1: Payment}
     */
    protected function upsellVorDemWebhook(): array
    {
        $this->mitUpsell();
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $erste = Payment::query()->firstOrFail();
        $zweite = $erste->replicate();
        $zweite->provider_id = $erste->provider_id.'-upsell';
        $zweite->checkout_key = null;
        $zweite->product = 'offer:cd';
        $zweite->save();

        $visit = $this->besuch();
        $meta = $visit->meta ?? [];
        $meta['pending_step'] = 'upsell';
        $meta['payments']['upsell'] = $zweite->getKey();
        $visit->forceFill(['payment_id' => $zweite->getKey(), 'meta' => $meta])->save();

        return [$erste, $zweite];
    }

    #[Test]
    public function die_erste_zahlung_rueckt_die_kasse_vor_obwohl_der_upsell_schon_begonnen_hat(): void
    {
        [$erste] = $this->upsellVorDemWebhook();
        $this->assertSame($this->besuch()->getKey(), (int) data_get($erste->meta, 'funnel_visit_id'));

        Event::fake([FunnelOfferAccepted::class]);

        $this->bezahlen($erste);

        $visit = $this->besuch();
        $accepted = $visit->events()->where('event', FunnelStepEvent::ACCEPTED)->get();

        $this->assertSame(['kasse'], $accepted->pluck('node_key')->all());
        $this->assertSame($erste->getKey(), $accepted->first()->payload['payment_id'] ?? null);
        $this->assertSame('upsell', $visit->current_node_key);

        Event::assertDispatched(FunnelOfferAccepted::class, fn ($e) => $e->step->node_key === 'kasse' && $e->payment->is($erste));
    }

    #[Test]
    public function die_zweite_zahlung_rueckt_den_upsell_vor_und_nicht_noch_einmal_die_kasse(): void
    {
        [$erste, $zweite] = $this->upsellVorDemWebhook();
        Event::fake([FunnelOfferAccepted::class]);

        $this->bezahlen($erste);
        event(new PaymentPaid($zweite->fresh()));

        $accepted = $this->besuch()->events()->where('event', FunnelStepEvent::ACCEPTED)->pluck('node_key')->sort()->values()->all();

        $this->assertSame(['kasse', 'upsell'], $accepted);
        Event::assertDispatchedTimes(FunnelOfferAccepted::class, 2);
    }

    #[Test]
    public function eine_zahlung_ohne_schritt_im_lauf_steht_als_fehler_im_log(): void
    {
        [$erste] = $this->upsellVorDemWebhook();

        // Der Schritt wurde aus dem Funnel geloescht, waehrend das Geld unterwegs war.
        $this->besuch()->funnel->steps()->where('node_key', 'kasse')->delete();

        Log::spy();
        Event::fake([FunnelOfferAccepted::class]);

        event(new PaymentPaid($erste->fresh()));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => str_contains($message, 'statamic-funnels')
            && ($context['payment_id'] ?? null) === $erste->getKey())->once();
        Event::assertNotDispatched(FunnelOfferAccepted::class);
    }

    #[Test]
    public function scheitert_das_vorruecken_bleibt_der_kauf_nicht_als_angenommen_stehen(): void
    {
        [$erste] = $this->upsellVorDemWebhook();
        Event::fake([FunnelOfferAccepted::class]);

        // Das Speichern des neuen Schritts wirft einmal, wie eine kurz
        // weggebrochene Datenbank. Der Anbieter stellt den Webhook neu zu.
        $wurf = true;
        FunnelVisit::saving(function () use (&$wurf) {
            if ($wurf) {
                $wurf = false;
                throw new RuntimeException('db weg');
            }
        });

        try {
            event(new PaymentPaid($erste->fresh()));
            $this->fail('Der Fehler muss nach oben, damit der Anbieter neu zustellt.');
        } catch (RuntimeException $e) {
            $this->assertSame('db weg', $e->getMessage());
        }

        $this->assertFalse($this->besuch()->events()->where('event', FunnelStepEvent::ACCEPTED)->exists(), 'ACCEPTED ohne Vorruecken sperrt jeden Retry.');
        Event::assertNotDispatched(FunnelOfferAccepted::class);

        // Die zweite Zustellung geht durch.
        event(new PaymentPaid($erste->fresh()));

        $this->assertSame('upsell', $this->besuch()->current_node_key);
        Event::assertDispatchedTimes(FunnelOfferAccepted::class, 1);
    }
}
