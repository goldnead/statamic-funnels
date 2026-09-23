<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Offers;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;

/**
 * Ein Funnel mit Anmeldung und Kasse, und ein Besucher, der ihn geht.
 *
 * Fuer die Tests der zweiten Suite-Welle (F1 bis F7). Jede dieser Dateien
 * braucht dieselbe Strecke; einmal hier statt siebenmal leicht verschieden.
 */
trait WalksAFunnel
{
    protected string $token = 'abcdefghijklmnopqrstuvwxyz012345';

    protected function asVisitor(?string $token = null): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token ?? $this->token);
    }

    /**
     * Einstieg → Anmeldung → Kasse → Danke.
     *
     * @param  array<string, mixed>  $offer  Spalten des Angebots `kurs`
     * @param  array<string, mixed>  $step  Konfiguration des Kassenschritts
     * @param  array<string, mixed>  $meta  `funnels.meta`
     */
    protected function kasse(array $offer = [], array $step = [], array $meta = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true, 'meta' => $meta ?: null]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null, 'config' => ['headline' => 'Start']],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'kasse', 'type' => 'offer', 'label' => 'Kasse', 'slug' => 'kasse', 'config' => array_merge(['offer' => 'kurs'], $step)],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke', 'config' => ['headline' => 'Danke']],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'kasse', 'from_output' => 'default'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
        ]);

        Offer::create(array_merge([
            'handle' => 'kurs',
            'name' => 'Kurs',
            'product' => 'kurs',
            'amount_cent' => 9900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $offer));

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function bisZurKasse(string $email = 'k@example.com', array $extra = []): void
    {
        $this->asVisitor()->get('/f/kurs');
        $this->asVisitor()->post('/f/kurs/entry_1/advance');
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', array_merge(['email' => $email], $extra));
        $this->asVisitor()->get('/f/kurs/kasse');
    }

    /** Den Anbieter „bezahlt" melden lassen, wie der Webhook es taete. */
    protected function bezahlen(Payment $zahlung, string $email = 'k@example.com'): Payment
    {
        $this->gateway->markPaid($zahlung->provider_id, $email, '9996', 'Mastercard');
        app(Fulfilment::class)->handle($zahlung->provider_id);

        return $zahlung->fresh();
    }

    protected function besuch(): FunnelVisit
    {
        return FunnelVisit::query()->where('token', $this->token)->firstOrFail();
    }

    /** Die Tests, die offers ab 1.12 brauchen, laufen gegen ein aelteres offers nicht. */
    protected function brauchtOffers112(): void
    {
        if (! method_exists(Offers::class, 'couponFromRequest')) {
            $this->markTestSkipped('statamic-offers < 1.12 im vendor: Coupon-Link, PWYW und Laenderregel gibt es dort nicht.');
        }
    }
}
