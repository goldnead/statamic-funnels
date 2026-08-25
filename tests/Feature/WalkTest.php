<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Walking a funnel.
 *
 * A funnel is judged by where people stop, so almost everything here is about
 * that record being true: entered once however often a page is reloaded,
 * declined counted as an answer rather than a failure, and nothing marked paid
 * until the payment addon says so.
 */
class WalkTest extends TestCase
{
    /**
     * A browser keeps the walk token between requests; the test client does
     * not, so it is handed over by hand. Without this every request would be a
     * new visitor, which is exactly the bug this cookie exists to prevent.
     */
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(bool $published = true): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'fruehlingskurs',
            'title' => 'Frühlingskurs',
            'published' => $published,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Schade', 'slug' => 'schade'],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'page_1', 'from_output' => 'declined'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function paymentGateway()
    {
        return $this->gateway;
    }

    protected function offer(): Offer
    {
        return Offer::create([
            'handle' => 'kurs-angebot',
            'name' => 'Kurs zum Einführungspreis',
            'product' => 'kurs',
            'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);
    }

    #[Test]
    public function an_unpublished_funnel_is_simply_not_there(): void
    {
        $this->funnel(published: false);

        // 404, not an error page. A half-built funnel handed to a visitor is
        // worse than a missing page, because it takes money in the middle.
        $this->asVisitor()->get('/f/fruehlingskurs')->assertNotFound();
    }

    #[Test]
    public function the_entry_lives_under_the_funnels_own_url(): void
    {
        $this->funnel();

        $this->asVisitor()->get('/f/fruehlingskurs')->assertOk();

        // A visitor should not have to know they are in a funnel to be in one.
        $this->assertSame(1, FunnelVisit::count());
    }

    #[Test]
    public function a_reload_is_not_a_second_visit(): void
    {
        $this->funnel();

        $this->asVisitor()->get('/f/fruehlingskurs');
        $this->asVisitor()->get('/f/fruehlingskurs');
        $this->asVisitor()->get('/f/fruehlingskurs');

        // A drop-off report built on repeat views is a report about refreshing.
        $visit = FunnelVisit::first();
        $this->assertSame(1, $visit->events()->where('event', FunnelStepEvent::ENTERED)->count());
    }

    #[Test]
    public function a_form_step_records_who_it_was_and_moves_on(): void
    {
        $this->funnel();
        $this->asVisitor()->get('/f/fruehlingskurs/anmeldung');

        $response = $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', [
            'email' => 'maria@example.com',
            'name' => 'Maria Beispiel',
        ]);

        $response->assertRedirect('/f/fruehlingskurs/angebot');

        $visit = FunnelVisit::first();
        $this->assertSame('maria@example.com', $visit->email);
        $this->assertSame(1, $visit->events()->where('event', FunnelStepEvent::SUBMITTED)->count());
    }

    #[Test]
    public function a_form_step_refuses_a_missing_address(): void
    {
        $this->funnel();
        $this->asVisitor()->get('/f/fruehlingskurs/anmeldung');

        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['name' => 'Ohne Adresse'])
            ->assertSessionHasErrors('email');

        $this->assertNull(FunnelVisit::first()?->email);
    }

    #[Test]
    public function declining_is_an_answer_and_not_a_failure(): void
    {
        $this->funnel();
        $this->offer();
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');

        $response = $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '0']);

        // Most visitors decline. A funnel that treats that as an error has
        // nowhere to send them.
        $response->assertRedirect('/f/fruehlingskurs/schade');
        $this->assertSame(1, FunnelVisit::first()->events()->where('event', FunnelStepEvent::DECLINED)->count());
    }

    #[Test]
    public function accepting_sends_the_visitor_to_the_provider_and_nothing_is_paid_yet(): void
    {
        $this->funnel();
        $this->offer();
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');
        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['email' => 'maria@example.com']);

        $response = $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        $response->assertRedirectContains('checkout.example');

        // The price came from the offer, not from the page, and the walk is
        // *not* advanced: only the webhook decides that money moved.
        $payment = Payment::first();
        $this->assertSame(4900, $payment->amount_cent);

        $visit = FunnelVisit::first();
        $this->assertSame($payment->id, $visit->payment_id);
        $this->assertSame(0, $visit->events()->where('event', FunnelStepEvent::ACCEPTED)->count());
    }

    #[Test]
    public function accepting_without_the_confirmation_is_refused(): void
    {
        $this->funnel();
        $this->offer();
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');

        // The order button's own checkbox. Without it there is no record that
        // somebody clicked something labelled as an order.
        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1'])
            ->assertSessionHasErrors('confirmed');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function an_offer_step_pointing_at_nothing_sellable_takes_no_money(): void
    {
        $this->funnel();
        // No offer created at all.
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');

        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_step_that_is_not_part_of_this_funnel_is_not_there(): void
    {
        $this->funnel();

        $this->asVisitor()->post('/f/fruehlingskurs/erfunden/advance', [])->assertNotFound();
        $this->get('/f/fruehlingskurs/gibt-es-nicht')->assertNotFound();
    }

    #[Test]
    public function the_provider_sends_the_buyer_back_into_the_funnel(): void
    {
        $this->funnel();
        $this->offer();
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');

        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        // Not the site's configured thank-you page: a buyer who returns outside
        // the flow they were walking has been dropped halfway through, and the
        // part of the funnel that was meant to follow the sale never happens.
        $this->assertStringContainsString(
            '/f/fruehlingskurs/danke',
            $this->gateway->lastPayload['redirectUrl'] ?? '',
        );
    }

    #[Test]
    public function the_walk_moves_on_only_when_the_payment_says_so(): void
    {
        $this->funnel();
        $this->offer();
        $this->asVisitor()->get('/f/fruehlingskurs/angebot');
        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        $visit = FunnelVisit::first();
        $this->assertSame('offer_1', $visit->current_node_key);

        // The provider confirms. Only now does the funnel advance — and it does
        // so once, however often the provider redelivers.
        $payment = Payment::first();
        $this->gateway->markPaid($payment->provider_id);

        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $visit = $visit->fresh();
        $this->assertSame('finish_1', $visit->current_node_key);
        $this->assertSame(1, $visit->events()->where('event', FunnelStepEvent::ACCEPTED)->count());
    }
}
