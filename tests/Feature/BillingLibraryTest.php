<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Support\BillingFields;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Der Capture-Schritt liest die Feld-Bibliothek des Angebots.
 *
 * Kajabi haelt die Felder seitenweit und hakt je Angebot an, welche die Kasse
 * abfragt. Hier: `Offers::fieldLibrary()` und `Offer::checkoutFields()` aus
 * `statamic-offers`. Beide gibt es in der Fassung dieser Suite noch nicht; die
 * Tests haengen sie ueber die Resolver von {@see BillingFields} ein — so, wie
 * die echte Fassung sie liefern wird.
 */
class BillingLibraryTest extends TestCase
{
    protected const LIBRARY = [
        'name' => ['label' => 'Vollständiger Name', 'type' => 'text', 'required' => true],
        'company' => ['label' => 'Firma', 'type' => 'text', 'required' => false],
        'vat_id' => ['label' => 'USt-IdNr.', 'type' => 'text', 'required' => false],
        'country' => ['label' => 'Land', 'type' => 'country', 'required' => true],
        'phone' => ['label' => 'Telefon', 'type' => 'tel', 'required' => false],
        'salutation' => ['label' => 'Anrede', 'type' => 'select', 'required' => true, 'options' => ['frau' => 'Frau', 'herr' => 'Herr', 'divers' => 'Divers']],
        'agb' => ['label' => 'AGB gelesen', 'type' => 'checkbox', 'required' => false],
        'street' => ['label' => 'Straße', 'type' => 'text', 'required' => true],
    ];

    protected function tearDown(): void
    {
        BillingFields::resolveLibraryUsing(null);
        BillingFields::resolveFieldsUsing(null);

        parent::tearDown();
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * Einstieg → Formular → Seite → Angebot: das Angebot ist nicht der direkte
     * Nachfolger, die Suche muss vorwaerts laufen. Und eine Mail am Formular,
     * an der die Suche vorbei muss.
     */
    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Angaben', 'slug' => 'angaben', 'config' => ['billing' => CaptureStep::BILLING_OFFER]],
            ['node_key' => 'mail_1', 'type' => 'mail', 'label' => 'Mail', 'slug' => null, 'config' => []],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Zwischen', 'slug' => 'zwischen'],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'mail_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
            ['from_node_key' => 'page_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
        ]);

        Offer::create([
            'handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs',
            'amount_cent' => 34700, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function withLibrary(array $fields = ['name', 'company', 'vat_id', 'country', 'salutation', 'agb']): void
    {
        BillingFields::resolveLibraryUsing(fn () => self::LIBRARY);
        BillingFields::resolveFieldsUsing(fn (Offer $offer) => $fields);
    }

    #[Test]
    public function the_form_renders_the_offers_fields_with_labels_and_types(): void
    {
        $this->withLibrary();
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angaben')->assertOk()->getContent();

        // Das Namensfeld der Vorlage traegt jetzt Label und Pflicht der Bibliothek.
        $this->assertStringContainsString('Vollständiger Name', $html);
        $this->assertMatchesRegularExpression('/name="name"[^>]*required/', $html);
        $this->assertStringContainsString('name="company"', $html);
        $this->assertStringContainsString('name="vat_id"', $html);
        $this->assertStringContainsString('<select name="salutation" required>', $html);
        $this->assertStringContainsString('<option value="herr">Herr</option>', $html);
        $this->assertStringContainsString('type="checkbox" name="agb"', $html);
        $this->assertMatchesRegularExpression('/name="country"[^>]*maxlength="2"[^>]*required/', $html);
        // Und nichts, was das Angebot nicht verlangt.
        $this->assertStringNotContainsString('name="phone"', $html);
        $this->assertStringNotContainsString('name="street"', $html);
    }

    #[Test]
    public function required_comes_from_the_library_and_the_keys_land_one_to_one(): void
    {
        $this->withLibrary();
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angaben')->assertOk();

        // Pflicht: name, country, salutation. Nicht: company, vat_id, agb.
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com'])
            ->assertSessionHasErrors(['name', 'country', 'salutation'])
            ->assertSessionDoesntHaveErrors(['company', 'vat_id', 'agb']);

        // Eine Anrede, die es nicht gibt.
        $this->asVisitor()->post('/f/kurs/capture_1/advance', [
            'email' => 'maria@example.com', 'name' => 'Maria Beispiel', 'country' => 'de', 'salutation' => 'majestaet',
        ])->assertSessionHasErrors(['salutation']);

        $this->asVisitor()->post('/f/kurs/capture_1/advance', [
            'email' => 'maria@example.com', 'name' => 'Maria Beispiel', 'country' => 'de',
            'salutation' => 'frau', 'company' => 'Chor GmbH', 'vat_id' => 'DE123456789', 'agb' => '1',
        ])->assertSessionHasNoErrors();

        $visit = FunnelVisit::query()->sole();

        $this->assertSame('Maria Beispiel', $visit->name);

        $billing = $visit->meta['billing'];
        ksort($billing);

        $this->assertSame([
            'agb' => true,
            'company' => 'Chor GmbH',
            // Gross, weil `payments.country` ISO-Codes fuehrt.
            'country' => 'DE',
            'name' => 'Maria Beispiel',
            'salutation' => 'frau',
            'vat_id' => 'DE123456789',
        ], $billing);
    }

    #[Test]
    public function without_the_library_the_step_behaves_like_minimal(): void
    {
        // Kein Resolver, und das installierte offers hat die Methoden nicht.
        $funnel = $this->funnel();
        $offer = Offer::query()->sole();

        if (method_exists($offer, 'checkoutFields')) {
            $this->markTestSkipped('Das installierte statamic-offers hat die Feld-Bibliothek; der Rueckfall ist hier nicht zu pruefen.');
        }

        $this->assertNull(BillingFields::forStep($funnel, $funnel->stepByKey('capture_1')));

        $html = $this->asVisitor()->get('/f/kurs/angaben')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="company"', $html);

        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function without_an_offer_behind_the_step_it_behaves_like_minimal(): void
    {
        $this->withLibrary();
        $funnel = $this->funnel();
        $funnel->edges()->where('to_node_key', 'offer_1')->delete();
        $funnel = $funnel->fresh(['steps', 'edges']);

        $this->assertNull(BillingFields::forStep($funnel, $funnel->stepByKey('capture_1')));

        $this->asVisitor()->get('/f/kurs/angaben')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_search_finds_the_offer_past_a_page_and_a_mail(): void
    {
        $this->withLibrary();
        $funnel = $this->funnel();

        $this->assertSame('kurs-angebot', BillingFields::nextOffer($funnel, $funnel->stepByKey('capture_1'))?->handle);
    }
}
