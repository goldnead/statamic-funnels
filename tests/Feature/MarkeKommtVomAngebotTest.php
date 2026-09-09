<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Marke kommt vom Angebot, nicht von der Anfrage.
 *
 * **Der Fehler, gegen den diese Datei geschrieben ist, sah aus wie ein
 * Verkauf.** Ein Funnel laeuft unter `/f/<handle>`: kein Pfadsegment einer
 * Marke, kein eigener Host. `SetBrandForSite` findet also nichts, worauf es
 * eine Marke abbilden koennte, und faellt auf die Standardmarke zurueck —
 * woraufhin `Brands::stampId()` jeden Kauf jeder Marke auf diese eine
 * stempelte. Am 09.09.2026 im Playground gemessen: drei Kaeufe aus drei
 * Marken, alle drei Zahlungen auf Marke 1. Mit Rechnungsserie, Absender und
 * Widerrufstext der falschen Marke daran, und ohne eine einzige Fehlermeldung.
 *
 * Drei Eingaenge legen im Kaufweg eines Funnels Zeilen an, und jeder einzelne
 * muss die Marke des Angebots tragen. Sie scheitern verschieden, deshalb steht
 * je ein Test dafuer:
 *
 * 1. **Die Kasse.** `AdvanceController::offer()` legt die Zahlung an, waehrend
 *    die Anfrage unter der fremden Marke laeuft.
 * 2. **Der Webhook.** `POST /!/statamic-payments/webhook` haengt in gar keiner
 *    Marken-Middleware: der Anbieter ruft von aussen, ohne Sitzung und ohne
 *    Host dieser Marke. Was hier entsteht — die Vereinbarung aus der ersten
 *    Rate — darf die Marke nicht neu erfinden, sondern erbt sie.
 * 3. **Das Nachfassen.** `Checkout::resume()` legt aus einer liegengebliebenen
 *    Zahlung eine neue an. Sie uebernimmt die Marke des Originals — was nur
 *    hilft, wenn das Original die richtige trug.
 *
 * Der Aufbau ist in allen dreien derselbe: die *fremde* Marke ist die aktive,
 * das Angebot gehoert der *eigenen*. Damit das keine Behauptung bleibt, prueft
 * jeder Test vor der Tat, dass wirklich die fremde Marke gilt — sonst wuerde
 * ein Fehler im Aufbau als bestandener Test durchgehen.
 */
class MarkeKommtVomAngebotTest extends TestCase
{
    /** Die Marke, unter der die Anfrage laeuft. Nicht die des Angebots. */
    protected Brand $fremde;

    /** Die Marke, der das Angebot gehoert. Sie muss auf jeder Zeile stehen. */
    protected Brand $eigene;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Der Ratenkauf im Webhook-Test braucht beides, sonst lehnt die Kasse
        // eine Ratenoption ab, statt eine Vereinbarung zu beginnen.
        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);

        // Mehrmarkigkeit wird hier **nicht** eingeschaltet, sondern erst in
        // `setUp()`, nach dem Hochfahren. Der Grund steht dort.
    }

    /**
     * Die echten Routen von statamic-payments statt der Attrappe in
     * {@see TestCase}.
     *
     * Zwei der drei Eingaenge sind Routen und keine Methodenaufrufe, und was
     * an ihnen zu pruefen ist, sind gerade die Schichten davor: der Webhook
     * traegt keine einzige Marken-Middleware, der Nachfass-Link haengt in der
     * `web`-Gruppe. Ueber `Fulfilment::handle()` direkt zu gehen wuerde beide
     * Schichten ueberspringen. Die fremde Marke setzt in diesem Aufbau
     * allerdings der Test selbst, nicht die Middleware — siehe unten, warum
     * sie hier nicht laeuft.
     */
    protected function defineRoutes($router): void
    {
        require __DIR__.'/../../vendor/goldnead/statamic-payments/routes/web.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // **Erst jetzt, und das ist eine Entscheidung.**
        //
        // `brand-context` verdrahtet beim Hochfahren `SetBrandForSite` in die
        // `web`-Gruppen — aber nur, wenn Mehrmarkigkeit schon in der Config
        // steht, und nur, wenn es in der Gruppe `SubstituteBindings` findet,
        // vor das es sich haengen kann. In Testbench ist `statamic.web` leer,
        // also wirft es dort ausdruecklich, statt die falsche Reihenfolge
        // still herzustellen (ServiceProvider.php:201). Das ist eine Luecke
        // des Testaufbaus, nicht des Codes: diese Datei ist die erste im
        // Paket, die Mehrmarkigkeit ueberhaupt einschaltet.
        //
        // Nach dem Hochfahren eingeschaltet, laeuft die Verdrahtung gar nicht
        // erst — und damit setzt in der Anfrage auch keine Middleware eine
        // Marke. Die fremde Marke gilt dann, weil sie hier ausdruecklich
        // gesetzt wird, statt weil ein Rueckfall sie waehlt. Fuer das, was
        // hier zu pruefen ist, ist das dieselbe Lage: `AdvanceController`
        // sieht eine fremde Marke im Kontext und muss sie ueberstimmen.
        config(['brand-context.multi_brand' => true]);

        $this->fremde = Brand::create(['handle' => 'fremde', 'name' => 'Fremde Marke']);
        $this->eigene = Brand::create(['handle' => 'eigene', 'name' => 'Marke des Angebots']);

        BrandContext::setCurrent($this->fremde);
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * Ein Funnel mit zwei Angeboten, beide der eigenen Marke.
     *
     * Das Ratenangebot steht daneben, weil nur ein Rhythmus im Webhook eine
     * Vereinbarung entstehen laesst — und die Vereinbarung ist die Zeile, an
     * der sich zeigt, ob der Webhook die Marke erbt oder neu stempelt.
     */
    protected function funnel(): Funnel
    {
        $funnel = Funnel::create([
            'handle' => 'markenkurs',
            'title' => 'Markenkurs',
            'published' => true,
        ]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'kasse', 'type' => 'offer', 'label' => 'Kasse', 'slug' => 'kasse', 'config' => ['offer' => 'kurs-einmal']],
            ['node_key' => 'kasse_raten', 'type' => 'offer', 'label' => 'In Raten', 'slug' => 'raten', 'config' => ['offer' => 'kurs-raten']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'kasse', 'from_output' => 'default'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'kasse_raten', 'from_output' => 'declined'],
            ['from_node_key' => 'kasse_raten', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse_raten', 'to_node_key' => 'finish_1', 'from_output' => 'declined'],
        ]);

        // `brand_id` ausdruecklich: `Offer` stempelt seit offers 1.11.1 zwar
        // selbst, aber nur bei `null` und dann mit der gerade aktuellen Marke
        // — hier also der fremden. Geraten wird nichts.
        Offer::create([
            'handle' => 'kurs-einmal',
            'brand_id' => $this->eigene->id,
            'name' => 'Kurs',
            'product' => 'kurs',
            'amount_cent' => 9900,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        Offer::create([
            'handle' => 'kurs-raten',
            'brand_id' => $this->eigene->id,
            'name' => 'Kurs in drei Raten',
            'product' => 'kurs',
            'amount_cent' => 3500,
            'interval' => '1 month',
            'times' => 3,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Bis vor die Kasse laufen, wie ein Besucher. */
    protected function bisZurKasse(string $email = 'kaeuferin@example.com'): void
    {
        $this->asVisitor()->get('/f/markenkurs/anmeldung');
        $this->asVisitor()->post('/f/markenkurs/capture_1/advance', ['email' => $email]);
    }

    /**
     * Dass wirklich die fremde Marke gilt.
     *
     * Vor der Tat gefragt und nicht danach: der Fix setzt die Marke des
     * Angebots waehrend der Bestellung, hinterher stuende also die richtige
     * da und der Aufbau bliebe ungeprueft. Ohne diese Zusicherung wuerde ein
     * Test, bei dem versehentlich schon die eigene Marke gilt, gruen sein,
     * ohne irgendetwas zu belegen.
     */
    protected function fremdeMarkeGiltJetzt(): void
    {
        $this->assertSame(
            $this->fremde->id,
            BrandContext::currentId(),
            'Der Aufbau taugt nur, solange vor dem Kauf eine fremde Marke gilt.',
        );
    }

    #[Test]
    public function die_kasse_stempelt_die_marke_des_angebots(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/markenkurs/kasse');

        $this->fremdeMarkeGiltJetzt();

        $this->asVisitor()->post('/f/markenkurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();

        $this->assertNotNull($zahlung, 'es wurde gar keine Zahlung angelegt');

        // Der gemessene Fehler: die Zahlung trug die Marke des Lesers, also
        // die, auf die der Funnel mangels Host und Pfad zurueckfiel.
        $this->assertSame(
            $this->eigene->id,
            (int) $zahlung->brand_id,
            'die Zahlung traegt die Marke der Anfrage statt die des Angebots',
        );
    }

    #[Test]
    public function der_webhook_erbt_die_marke_des_angebots_statt_sie_neu_zu_stempeln(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/markenkurs/raten');

        $this->fremdeMarkeGiltJetzt();

        $this->asVisitor()->post('/f/markenkurs/kasse_raten/advance', ['accept' => '1', 'confirmed' => '1']);

        $zahlung = Payment::latest('id')->first();

        $this->assertNotNull($zahlung, 'es wurde gar keine Zahlung angelegt');

        $this->gateway->markPaid($zahlung->provider_id, 'kaeuferin@example.com', '9996', 'Mastercard');

        // Der Anbieter meldet sich von aussen. Diese Route haengt in keiner
        // Marken-Middleware, es gilt also weiter, was zuletzt gesetzt war —
        // die fremde Marke. Genau darin liegt der Reiz: was hier entsteht,
        // darf nicht danach fragen, wo es gerade steht.
        BrandContext::setCurrent($this->fremde);

        $this->post('/!/statamic-payments/webhook', ['id' => $zahlung->provider_id])
            ->assertOk();

        $abo = Subscription::first();

        $this->assertNotNull($abo, 'die bezahlte erste Rate hat keine Vereinbarung hinterlassen');

        // Zweimal dieselbe Frage, an beide Zeilen. Zuerst an die Vereinbarung:
        // sie ist das, was im Webhook **neu entsteht**, und sie erbt die Marke
        // der Zahlung (`Subscriptions::startFromPayment()`) — was nur traegt,
        // wenn an der Zahlung die richtige stand.
        $this->assertSame(
            $this->eigene->id,
            (int) $abo->brand_id,
            'die Vereinbarung traegt die Marke der Anfrage statt die des Angebots',
        );

        // Und an die Zahlung selbst: der Webhook laeuft unter der fremden
        // Marke, er darf die bezahlte Zeile nicht auf sie umschreiben.
        $this->assertSame(
            $this->eigene->id,
            (int) $zahlung->fresh()->brand_id,
            'die Zahlung hat im Webhook die Marke des Angebots verloren',
        );
    }

    #[Test]
    public function das_nachfassen_traegt_die_marke_des_angebots_weiter(): void
    {
        $this->funnel();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/markenkurs/kasse');

        $this->fremdeMarkeGiltJetzt();

        $this->asVisitor()->post('/f/markenkurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $original = Payment::latest('id')->first();

        // Liegengeblieben: zum Anbieter geschickt, nie bezahlt. Genau der
        // Zustand, aus dem die Erinnerungsmail ihren Link baut.
        $this->assertNotNull($original, 'es wurde gar keine Zahlung angelegt');
        $this->assertFalse($original->isPaid(), 'nur eine unbezahlte Zahlung ist fortzusetzen');

        BrandContext::setCurrent($this->fremde);

        // Der Link aus der Mail, signiert wie im Betrieb. Der POST ist der
        // Bestellknopf, nicht der Aufruf der Seite.
        $link = URL::temporarySignedRoute(
            'statamic-payments.resume.start',
            Carbon::now()->addHour(),
            ['payPayment' => $original->getKey()],
        );

        $this->post($link)->assertRedirect();

        $neue = Payment::latest('id')->first();

        $this->assertNotSame(
            $original->getKey(),
            $neue->getKey(),
            'aus dem Nachfass-Link ist keine neue Zahlung entstanden',
        );

        // `Checkout::resume()` schreibt die Marke des Originals fest. Das
        // hilft nur, wenn das Original sie hatte — sonst wird hier ein
        // falscher Wert sauber weitergereicht.
        $this->assertSame(
            $this->eigene->id,
            (int) $neue->brand_id,
            'die nachgefasste Zahlung traegt die Marke der Anfrage statt die des Angebots',
        );
    }
}
