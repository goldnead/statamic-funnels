<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\LeadHub\Facades\LeadHub;
use Goldnead\Leadhub\Services\ContactResolver;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Kauf und Newsletter getrennt.
 *
 * Der Capture-Schritt reichte die Adresse an LeadHub weiter, ohne zwischen
 * „hat gekauft" und „will Post" zu unterscheiden. Genau darauf kommt es an:
 * die Adresse wird ein Kontakt, die Einwilligung kommt allein vom Haken, und
 * der Kauf ist ein Tag.
 */
class NewsletterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/leadhub-facade.php';

        LeadHub::$ingested = [];
        ContactResolver::$resolved = [];

        config()->set('statamic-funnels.integrations.leadhub', true);
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * @param  array<string, mixed>  $captureConfig
     */
    protected function funnel(array $captureConfig = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung', 'config' => $captureConfig],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
        ]);

        Offer::create([
            'handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function the_form_shows_an_unticked_box_under_the_email_field(): void
    {
        $this->funnel(['newsletter_label' => 'Ja, schick mir den Chor-Brief.']);

        $html = $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk()->getContent();

        $this->assertStringContainsString('name="newsletter"', $html);
        $this->assertStringContainsString('Ja, schick mir den Chor-Brief.', $html);
        $this->assertStringNotContainsString('name="newsletter" value="1" checked', $html);
        // Unter dem E-Mail-Feld, nicht irgendwo.
        $this->assertGreaterThan(strpos($html, 'name="email"'), strpos($html, 'name="newsletter"'));
    }

    #[Test]
    public function hidden_means_no_box_and_no_consent(): void
    {
        $this->funnel(['newsletter' => CaptureStep::NEWSLETTER_HIDDEN]);

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk()->assertDontSee('name="newsletter"', false);

        // Auch wenn eine fremde Vorlage das Feld trotzdem schickt.
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com', 'newsletter' => '1']);

        $visit = FunnelVisit::query()->sole();
        $this->assertArrayNotHasKey('newsletter', (array) $visit->meta);

        $this->assertCount(1, LeadHub::$ingested);
        $this->assertSame([], ContactResolver::$resolved);
    }

    #[Test]
    public function ticked_means_a_contact_with_consent_and_the_wording_on_the_visit(): void
    {
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', [
            'email' => 'maria@example.com', 'name' => 'Maria Beispiel', 'newsletter' => '1',
        ])->assertSessionHasNoErrors();

        $newsletter = FunnelVisit::query()->sole()->meta['newsletter'];
        $this->assertTrue($newsletter['opted_in']);
        $this->assertNotEmpty($newsletter['at']);
        $this->assertSame(__('statamic-funnels::messages.newsletter_label'), $newsletter['text']);

        // Der Kontakt ohne Einwilligung ueber `ingest`, die Einwilligung ueber
        // den Resolver — der einzige Weg, der sie kennt.
        $this->assertSame('maria@example.com', LeadHub::$ingested[0]['email']);
        $this->assertSame('Maria Beispiel', LeadHub::$ingested[0]['contact']['full_name']);
        $this->assertArrayNotHasKey('consent', LeadHub::$ingested[0]);

        $this->assertCount(1, ContactResolver::$resolved);
        $this->assertTrue(ContactResolver::$resolved[0]->consent);
        $this->assertSame('maria@example.com', ContactResolver::$resolved[0]->email);
    }

    #[Test]
    public function unticked_means_a_contact_without_consent(): void
    {
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com'])->assertSessionHasNoErrors();

        $this->assertFalse(FunnelVisit::query()->sole()->meta['newsletter']['opted_in']);

        $this->assertCount(1, LeadHub::$ingested);
        $this->assertSame([], ContactResolver::$resolved);
    }

    #[Test]
    public function a_later_step_without_the_tick_does_not_undo_a_yes(): void
    {
        $funnel = $this->funnel();
        $funnel->steps()->create(['node_key' => 'capture_2', 'type' => 'capture', 'label' => 'Nachfrage', 'slug' => 'nachfrage']);

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com', 'newsletter' => '1']);
        $this->asVisitor()->get('/f/kurs/nachfrage')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_2/advance', ['email' => 'maria@example.com']);

        $this->assertTrue(FunnelVisit::query()->sole()->meta['newsletter']['opted_in']);
    }

    #[Test]
    public function a_purchase_tags_the_contact_as_customer_without_consent(): void
    {
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com']);
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        $payment = Payment::query()->sole();
        $this->gateway->markPaid($payment->provider_id, 'maria@example.com');
        app(Fulfilment::class)->handle($payment->provider_id);

        $purchase = collect(LeadHub::$ingested)->firstWhere('type', 'funnel_purchase');

        $this->assertNotNull($purchase, 'Der Kauf kam nicht bei LeadHub an.');
        $this->assertSame(['kunde'], $purchase['tags']);
        $this->assertSame('maria@example.com', $purchase['email']);
        // Gekauft heisst nicht eingewilligt.
        $this->assertSame([], ContactResolver::$resolved);
    }

    #[Test]
    public function nothing_reaches_leadhub_while_the_switch_is_off(): void
    {
        config()->set('statamic-funnels.integrations.leadhub', false);
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com', 'newsletter' => '1']);

        $this->assertSame([], LeadHub::$ingested);
        $this->assertSame([], ContactResolver::$resolved);
        // Am Besuch steht es trotzdem.
        $this->assertTrue(FunnelVisit::query()->sole()->meta['newsletter']['opted_in']);
    }
}
