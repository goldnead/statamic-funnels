<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ein Funnel ohne Formular-Schritt verkaufte anonym (Staging, Zahlung 99): die
 * Zahlung ging durch, ohne Kaeufer, ohne Adresse, ohne Zugang, die Rechnung
 * ohne Empfaenger. Die Kasse verweigert deshalb jede Zahlung, zu der der Besuch
 * keine Adresse hat, auch wenn ein alter Funnel so schon live ist.
 */
class KeinKaufOhneAdresseTest extends TestCase
{
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnelOhneFormular(): void
    {
        $funnel = Funnel::create(['handle' => 'direkt', 'title' => 'Direkt', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
        ]);

        Offer::create([
            'handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);
    }

    #[Test]
    public function the_checkout_refuses_a_payment_when_the_visit_has_no_address(): void
    {
        $this->funnelOhneFormular();
        $this->asVisitor()->get('/f/direkt/angebot');

        $this->asVisitor()->post('/f/direkt/offer_1/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count(), 'Ohne Adresse darf keine Zahlung angelegt werden.');
        $this->assertNull(FunnelVisit::first()->payment_id);
    }

    #[Test]
    public function the_checkout_still_sells_when_the_visit_has_an_address(): void
    {
        $this->funnelOhneFormular();
        $this->asVisitor()->get('/f/direkt/angebot');
        FunnelVisit::first()->forceFill(['email' => 'maria@example.com'])->save();

        $this->asVisitor()->post('/f/direkt/offer_1/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertRedirectContains('checkout.example');

        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function declining_needs_no_address(): void
    {
        $this->funnelOhneFormular();
        $this->asVisitor()->get('/f/direkt/angebot');

        $this->asVisitor()->post('/f/direkt/offer_1/advance', ['accept' => '0'])
            ->assertSessionHasNoErrors();
    }
}
