<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Events\UpsellDeclined;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Uebergabe aus statamic-payments (P7, P8) und ein Ereignis fuer automations.
 *
 * - **Captcha (P7).** Ist es eingeschaltet, lehnt payments jede Kasse ohne
 *   Token ab. Die Kassenseite muss das Widget zeichnen, und eine Ablehnung an
 *   der Tuer sagt der Kaeuferin, woran es lag.
 * - **Erinnerung (P8).** Abbruch-Mails nur mit eigenem Haken, nicht mit der
 *   Kaufzustimmung. Der Haken reist als `meta.reminder_consent` mit.
 * - **Upsell abgelehnt** fuer automations: ein Nein auf ein Angebot nach einem
 *   bezahlten Kauf im selben Lauf.
 */
class PaymentsGuardAndReminderTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('Goldnead\StatamicPayments\Support\CheckoutGuard')) {
            $this->markTestSkipped('statamic-payments ohne Checkout-Schutz (P7) im vendor.');
        }
    }

    protected function captchaAn(): void
    {
        config([
            'statamic-payments.protection.captcha.provider' => 'turnstile',
            'statamic-payments.protection.captcha.site_key' => 'site-key-probe',
            'statamic-payments.protection.captcha.secret' => 'geheim',
        ]);
    }

    #[Test]
    public function die_kasse_zeichnet_das_captcha(): void
    {
        $this->captchaAn();
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertOk()
            ->assertSee('data-sitekey="site-key-probe"', false);
    }

    #[Test]
    public function ohne_captcha_steht_keins_da(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()->assertDontSee('data-sitekey', false);
    }

    #[Test]
    public function eine_ablehnung_an_der_tuer_nennt_den_grund(): void
    {
        $this->captchaAn();
        $this->kasse();
        $this->bisZurKasse();

        // Kein Token: payments lehnt ab, bevor eine Zahlung entsteht.
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors(['offer' => __('statamic-funnels::messages.checkout_blocked_captcha')]);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function zu_viele_versuche_sagen_das(): void
    {
        config(['statamic-payments.protection.rate_limit' => ['enabled' => true, 'per_ip' => 1, 'per_email' => 50, 'decay_minutes' => 10]]);
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])->assertRedirect();

        // Zweiter Kauf von derselben Adresse (neuer Besuch, gleiche IP).
        $this->token = 'zweiterbesuchzweiterbesuchzweite';
        $this->bisZurKasse('zwei@example.com');

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasErrors(['offer' => __('statamic-funnels::messages.checkout_blocked_rate_limited')]);
    }

    #[Test]
    public function mit_erinnerungen_fragt_die_kasse_und_gibt_den_haken_weiter(): void
    {
        config(['statamic-payments.abandoned.enabled' => true, 'statamic-payments.abandoned.capture' => 'consent']);
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertSee('name="reminder_consent"', false);

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'reminder_consent' => '1'])->assertRedirect();

        $meta = Payment::query()->firstOrFail()->meta;
        $this->assertTrue($meta['reminder_consent'] ?? null);
        $this->assertSame(__('statamic-funnels::messages.reminder_consent_label'), $meta['reminder_consent_text'] ?? null);
    }

    #[Test]
    public function ohne_haken_keine_erinnerung(): void
    {
        config(['statamic-payments.abandoned.enabled' => true, 'statamic-payments.abandoned.capture' => 'consent']);
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1'])->assertRedirect();

        $this->assertArrayNotHasKey('reminder_consent', (array) Payment::query()->firstOrFail()->meta);
    }

    #[Test]
    public function ohne_erinnerungen_fragt_die_kasse_nicht(): void
    {
        config(['statamic-payments.abandoned.enabled' => false]);
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertDontSee('name="reminder_consent"', false);

        // Ein mitgeschickter Haken zaehlt nicht, wenn die Seite nicht fragt.
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1', 'reminder_consent' => '1']);
        $this->assertArrayNotHasKey('reminder_consent', (array) Payment::query()->firstOrFail()->meta);
    }

    // ------------------------------------------------------------ Upsell

    protected function mitUpsell(): void
    {
        $funnel = $this->kasse();
        Offer::create(['handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'slot' => 'post_purchase', 'active' => true]);
        $funnel->edges()->where('from_node_key', 'kasse')->where('from_output', 'accepted')->delete();
        $funnel->steps()->create(['node_key' => 'upsell', 'type' => 'offer', 'slug' => 'upsell', 'config' => ['offer' => 'cd']]);
        $funnel->edges()->create(['from_node_key' => 'kasse', 'to_node_key' => 'upsell', 'from_output' => 'accepted']);
        $funnel->edges()->create(['from_node_key' => 'upsell', 'to_node_key' => 'finish_1', 'from_output' => 'declined']);
    }

    #[Test]
    public function ein_nein_nach_dem_kauf_ist_ein_abgelehnter_upsell(): void
    {
        $this->mitUpsell();
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);
        $zahlung = $this->bezahlen(Payment::query()->firstOrFail());

        Event::fake([UpsellDeclined::class]);

        $this->asVisitor()->get('/f/kurs/upsell');
        $this->asVisitor()->post('/f/kurs/upsell/advance', ['accept' => '0'])->assertRedirect();

        Event::assertDispatched(UpsellDeclined::class, fn (UpsellDeclined $e) => $e->step->node_key === 'upsell'
            && $e->offerHandle === 'cd'
            && $e->payment?->is($zahlung)
            && $e->visit->email === 'k@example.com');
    }

    #[Test]
    public function ein_nein_ohne_kauf_davor_ist_kein_upsell(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        Event::fake([UpsellDeclined::class]);

        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '0'])->assertRedirect();

        Event::assertNotDispatched(UpsellDeclined::class);
    }
}
