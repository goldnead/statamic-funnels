<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die gespeicherte Karte gehoert dem Menschen, nicht dem Rechner.
 *
 * Ein Funnel-Besuch haengt an einem Cookie, der einen Monat haelt. Wer daraus
 * schliesst, wer da sitzt, bucht der zweiten Person am selben Geraet die Karte
 * der ersten ab — auf einem Familienrechner, im Buero oder in einer Bibliothek
 * ist das kein Randfall. Diese Datei haelt beide Haelften fest: dass es dem
 * echten Wiederkaeufer weiter erspart bleibt, seine Karte noch einmal zu
 * tippen, und dass es bei jedem anderen nicht passiert.
 */
class SavedCardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Nachfassen ist ab Werk aus. Hier an, sonst waere der ganze Zweig
        // dieser Datei unerreichbar — was er bis zu dieser Fassung war.
        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'fruehlingskurs',
            'title' => 'Frühlingskurs',
            'published' => true,
        ]);

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

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function offers(): void
    {
        Offer::create([
            'handle' => 'kurs-angebot',
            'name' => 'Kurs zum Einführungspreis',
            'product' => 'kurs',
            'amount_cent' => 4900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        Offer::create([
            'handle' => 'cd-angebot',
            'name' => 'Begleit-CD',
            'product' => 'begleit-cd',
            'amount_cent' => 2900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);
    }

    /**
     * Einmal bezahlen, wie ein Besucher: Adresse eintragen, Angebot annehmen,
     * der Anbieter meldet die Zahlung.
     */
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

    #[Test]
    public function the_same_buyer_keeps_the_one_click_upsell(): void
    {
        $this->funnel();
        $this->offers();

        $first = $this->paysOnce('erste@example.com');

        $this->assertNotNull($first->customer_reference, 'Ohne Mandat gibt es nichts nachzufassen.');

        $this->asVisitor()->get('/f/fruehlingskurs/noch-etwas');
        $this->asVisitor()->post('/f/fruehlingskurs/offer_2/advance', ['accept' => '1', 'confirmed' => '1']);

        // Kein Umweg ueber den Anbieter: die zweite Zahlung haengt an der
        // ersten. Das ist der ganze Zweck der Sache und darf nicht verloren
        // gehen, nur weil der Missbrauch daneben verhindert wird.
        $second = Payment::latest('id')->first();
        $this->assertSame($first->id, $second->parent_payment_id);
        $this->assertSame('erste@example.com', $second->email);
    }

    #[Test]
    public function somebody_else_at_the_same_screen_is_not_charged_on_the_first_buyers_card(): void
    {
        $this->funnel();
        $this->offers();

        $first = $this->paysOnce('erste@example.com');

        // Gleiches Geraet, gleicher Cookie, andere Adresse. Fuer den Code war
        // das bisher derselbe Mensch, der ein zweites Angebot annimmt.
        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['email' => 'zweite@example.com']);
        $antwort = $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        // Ab zum Anbieter, mit eigener Karteneingabe.
        $antwort->assertRedirect();
        $this->assertStringContainsString('checkout.example', $antwort->headers->get('Location'));

        $second = Payment::latest('id')->first();
        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($second->parent_payment_id);
        $this->assertSame('zweite@example.com', $second->email);
    }

    #[Test]
    public function the_second_person_is_not_waved_through_on_the_first_ones_payment(): void
    {
        $this->funnel();
        $this->offers();

        $this->paysOnce('erste@example.com');
        $bisher = Payment::count();

        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['email' => 'zweite@example.com']);
        $this->asVisitor()->post('/f/fruehlingskurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        // Die zweite Falle desselben Cookies: der Schritt merkt sich, welche
        // Zahlung er gestartet hat. Bleibt die Erinnerung stehen, haelt er den
        // laengst bezahlten Kauf des Ersten fuer diesen hier und laesst die
        // zweite Person umsonst weiter.
        $this->assertSame($bisher + 1, Payment::count());
        $this->assertSame('zweite@example.com', Payment::latest('id')->first()->email);
    }

    #[Test]
    public function the_page_says_which_card_it_would_charge(): void
    {
        $this->funnel();
        $this->offers();

        $this->paysOnce('erste@example.com');

        // Was gespart wird, sind die Tastenanschlaege, nicht die Zustimmung:
        // § 312j Abs. 3 BGB will die wesentlichen Angaben unmittelbar ueber
        // dem Knopf, und womit abgebucht wird, gehoert dazu.
        $this->asVisitor()->get('/f/fruehlingskurs/noch-etwas')
            ->assertOk()
            ->assertSee('9996', false)
            ->assertSee('Mastercard', false);
    }

    #[Test]
    public function a_first_checkout_promises_nothing_about_a_saved_card(): void
    {
        $this->funnel();
        $this->offers();

        $this->asVisitor()->post('/f/fruehlingskurs/capture_1/advance', ['email' => 'erste@example.com']);

        // Vor der ersten Zahlung gibt es keine Karte, also auch keinen Satz
        // darueber. Ein Hinweis, der immer dasteht, ist keiner.
        $this->asVisitor()->get('/f/fruehlingskurs/angebot')
            ->assertOk()
            ->assertDontSee('9996', false);
    }
}
