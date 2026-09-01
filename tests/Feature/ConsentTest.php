<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\Consent;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Einwilligung am Bestellknopf.
 *
 * § 356 Abs. 5 BGB verlangt eine ausdrueckliche Zustimmung, und ein Haken ohne
 * Zeitpunkt und Textfassung ist keine, sobald sich der Text aendert. Bisher
 * wurde `confirmed` geprueft und verworfen. Jetzt gehen Zeitpunkt und Wortlaut
 * **in** die Zahlung — und eine Seite, die einen anderen Wortlaut gezeigt hat
 * als den geltenden, bestellt nicht.
 *
 * Die Konditionen kommen vom Angebot (`Offer::withdrawalTerms()`), das in
 * dieser Suite noch keine hat: die Tests haengen eine Fassung ueber
 * {@see Consent::resolveTermsUsing()} ein, so wie eine Site es koennte.
 */
class ConsentTest extends TestCase
{
    protected const TERMS = [
        'days' => 14,
        'text' => 'Du hast 14 Tage Widerrufsrecht. Es erlischt, wenn du der sofortigen Lieferung zustimmst.',
        'waiver_text' => 'Ich verlange die sofortige Lieferung und weiß, dass mein Widerrufsrecht damit erlischt.',
        'checkbox_required' => true,
        'b2b_text' => 'Ich bestelle als Unternehmer; ein Widerrufsrecht besteht nicht.',
        'version' => '2026-09',
    ];

    protected function tearDown(): void
    {
        Consent::resolveTermsUsing(null);
        Consent::resolveAccessUsing(null);

        parent::tearDown();
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

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

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Was an der Zahlung als Einwilligung steht — Spalte oder `meta`, je nach payments. */
    protected function consentOn(Payment $payment): array
    {
        if (in_array('consent_text', PaymentDetails::ALLOWED, true)) {
            return ['at' => $payment->getAttribute('consent_at'), 'text' => $payment->getAttribute('consent_text')];
        }

        $meta = (array) ($payment->meta['consent'] ?? []);

        return ['at' => $meta['at'] ?? null, 'text' => $meta['text'] ?? null];
    }

    #[Test]
    public function the_page_shows_the_terms_and_the_wording_with_its_version(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->getContent();

        $this->assertStringContainsString('Du hast 14 Tage Widerrufsrecht.', $html);
        // Der Satz am Haken ohne die Fassung; die Fassung als Fussnote daneben.
        // Sichtbar heisst: ohne das versteckte Feld, das den vollen Wortlaut
        // traegt.
        $visible = (string) preg_replace('/<input type="hidden"[^>]*>/', '', $html);
        $this->assertStringContainsString(self::TERMS['waiver_text'], $visible);
        $this->assertStringNotContainsString('erlischt. [2026-09]', $visible);
        $this->assertStringContainsString('<small class="funnel-consent-version">', $html);
        $this->assertStringContainsString('2026-09</small>', $html);
        // Das versteckte Feld traegt den vollen protokollierten Wortlaut.
        $this->assertStringContainsString('name="consent_text" value="'.e(self::TERMS['waiver_text'].' [2026-09]').'"', $html);
    }

    #[Test]
    public function the_terms_render_as_paragraphs_with_their_headings(): void
    {
        Consent::resolveTermsUsing(fn () => ['text' => "Widerrufsrecht\n\nSie haben das Recht, binnen 14 Tagen zu widerrufen.\n\nFolgen des Widerrufs\n\nWir erstatten alle Zahlungen."] + self::TERMS);
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->getContent();

        $this->assertStringContainsString('<p><strong>Widerrufsrecht</strong></p>', $html);
        $this->assertStringContainsString('<p><strong>Folgen des Widerrufs</strong></p>', $html);
        $this->assertStringContainsString('<p>Sie haben das Recht, binnen 14 Tagen zu widerrufen.</p>', $html);
        $this->assertStringContainsString('<p>Wir erstatten alle Zahlungen.</p>', $html);

        $this->assertSame(
            [['text' => 'Eins.', 'heading' => false], ['text' => 'Titel', 'heading' => true]],
            Consent::paragraphs("Eins.\n\n\nTitel\n"),
        );
    }

    #[Test]
    public function without_terms_on_the_offer_the_page_reads_as_before(): void
    {
        // Ein Angebot, das keine Konditionen fuehrt — ein aelteres offers, oder
        // eine Site, die sie woanders regelt. Ausdruecklich, weil das
        // installierte offers inzwischen fuer jedes Angebot welche kennt.
        Consent::resolveTermsUsing(fn () => null);
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')
            ->assertOk()
            ->assertSee(__('statamic-funnels::messages.order_confirmation'))
            ->assertDontSee('funnel-offer__withdrawal');
    }

    #[Test]
    public function the_consent_lands_on_the_payment_before_the_provider_is_called(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        Consent::resolveAccessUsing(fn () => ['starts_at' => '2026-10-01T00:00:00+00:00', 'days' => 365]);
        $this->funnel();

        $expected = self::TERMS['waiver_text'].' [2026-09]';

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', [
            'accept' => '1', 'confirmed' => '1', 'consent_text' => $expected,
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();
        $consent = $this->consentOn($payment);

        $this->assertNotNull($consent['at']);
        $this->assertSame($expected, $consent['text']);

        // Die zum Kaufzeitpunkt geltende Fassung, eingefroren an der Zahlung.
        $this->assertSame('2026-09', $payment->meta['withdrawal']['version'] ?? null);
        $this->assertSame(14, $payment->meta['withdrawal']['days'] ?? null);

        // Und das Zugangsfenster, das die Freischaltung spaeter liest.
        $this->assertSame(365, $payment->meta['access']['days'] ?? null);
        $this->assertSame('2026-10-01T00:00:00+00:00', $payment->meta['access']['starts_at'] ?? null);
    }

    #[Test]
    public function a_stale_or_altered_wording_does_not_order(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', [
            'accept' => '1', 'confirmed' => '1',
            // Die Fassung von gestern.
            'consent_text' => self::TERMS['waiver_text'].' [2026-08]',
        ])->assertSessionHasErrors('consent_text');

        $this->assertSame(0, Payment::count());

        // Als JSON: derselbe Fall ist ein 422. `withCredentials()`, weil der
        // Testclient JSON-Anfragen sonst ohne Cookies schickt — und ohne den
        // Cookie ist es ein neuer Besuch, der den Schritt nie betreten hat.
        $this->asVisitor()->withCredentials()->postJson('/f/kurs/offer_1/advance', [
            'accept' => '1', 'confirmed' => '1', 'consent_text' => 'etwas ganz anderes',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_business_buyer_gets_the_b2b_wording(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        $funnel = $this->funnel();

        // Ein Besuch mit USt-IdNr in den Rechnungsangaben.
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $visit = $funnel->visits()->sole();
        $visit->forceFill(['meta' => ['billing' => ['vat_id' => 'DE123456789']]])->save();

        $this->asVisitor()->get('/f/kurs/angebot')
            ->assertSee('Ich bestelle als Unternehmer')
            ->assertDontSee('Ich verlange die sofortige Lieferung');

        $this->asVisitor()->post('/f/kurs/offer_1/advance', [
            'accept' => '1', 'confirmed' => '1', 'consent_text' => self::TERMS['b2b_text'].' [2026-09]',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::TERMS['b2b_text'].' [2026-09]', $this->consentOn(Payment::query()->sole())['text']);
    }

    #[Test]
    public function the_checkbox_can_be_switched_off_by_the_terms_and_the_consent_is_still_recorded(): void
    {
        Consent::resolveTermsUsing(fn () => ['checkbox_required' => false, 'waiver_text' => 'Lieferung beginnt sofort.', 'version' => '1'] + self::TERMS);
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')
            ->assertOk()
            ->assertDontSee('name="confirmed"', false);

        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('Lieferung beginnt sofort. [1]', $this->consentOn(Payment::query()->sole())['text']);
    }

    #[Test]
    public function without_terms_the_lang_wording_is_what_is_recorded(): void
    {
        Consent::resolveTermsUsing(fn () => null);
        Consent::resolveAccessUsing(fn () => null);
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1'])->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();

        $this->assertSame(__('statamic-funnels::messages.order_confirmation'), $this->consentOn($payment)['text']);
        // Keine Konditionen, kein Fenster: nichts erfunden.
        $this->assertArrayNotHasKey('withdrawal', (array) $payment->meta);
        $this->assertArrayNotHasKey('access', (array) $payment->meta);
    }

    #[Test]
    public function the_real_terms_of_the_installed_offers_are_used_when_it_has_them(): void
    {
        $this->funnel();
        $offer = Offer::query()->sole();

        if (! method_exists($offer, 'withdrawalTerms')) {
            $this->markTestSkipped('Das installierte statamic-offers fuehrt noch keine Widerrufskonditionen.');
        }

        $terms = $offer->withdrawalTerms();
        $expected = Consent::text($terms, false);

        $this->assertStringEndsWith('['.$terms['version'].']', $expected);

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->assertSee($expected);
        $this->asVisitor()->post('/f/kurs/offer_1/advance', [
            'accept' => '1', 'confirmed' => '1', 'consent_text' => $expected,
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->sole();

        $this->assertSame($expected, $this->consentOn($payment)['text']);
        $this->assertSame($terms['version'], $payment->meta['withdrawal']['version'] ?? null);
    }

    #[Test]
    public function an_older_payments_without_the_columns_is_not_thrown_at(): void
    {
        // Was `PaymentDetails` annimmt, entscheidet, ob die Einwilligung als
        // Spalte oder unter `meta['consent']` landet. In keinem Fall wirft der
        // Kauf, und in keinem Fall geht der Beleg verloren.
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payment = Payment::query()->sole();

        if (in_array('consent_text', PaymentDetails::ALLOWED, true)) {
            $this->assertNotNull($payment->getAttribute('consent_text'));
        } else {
            $this->assertNotNull($payment->meta['consent']['text'] ?? null);
        }
    }
}
