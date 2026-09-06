<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\Consent;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Was in welcher Reihenfolge ueber dem Bestellknopf steht.
 *
 * Diese Reihenfolge ist eine Rechtsaussage, keine Gestaltung. § 312j Abs. 3 BGB
 * verlangt die Angaben nach Abs. 2 — wesentliche Merkmale, Gesamtpreis,
 * Laufzeit, Mindestdauer — "unmittelbar bevor" der Verbraucher die Bestellung
 * abgibt, klar und hervorgehoben.
 *
 * Heute stehen zwischen Preisblock und Knopf rund 900 Zeichen
 * Widerrufsbelehrung. Ob das zulaessig ist, ist NICHT entschieden: die Frage
 * liegt beim Anwalt (`STATE/tasks/task-suite-register-pruefliste-adrian.md`,
 * Abschnitt "Dazugekommen am 02.09. mittags"; Ticket
 * `backlog-kasse-reihenfolge-ueber-dem-bestellknopf`). Adrians Vorgabe dazu
 * lautet woertlich: "Vorher nichts umsortieren."
 *
 * Genau deshalb gibt es diesen Test. Er entscheidet nichts und verbessert
 * nichts — er haelt den heutigen Stand fest, damit
 *
 *  1. niemand die Reihenfolge beilaeufig aendert, waehrend die Rechtsfrage
 *     offen ist, und
 *  2. der Tag, an dem die Antwort kommt, eine einzige rot werdende Datei hat
 *     statt einer Suche durch das Template.
 *
 * Wenn dieser Test rot wird, ist das kein Bug: dann hat jemand eine
 * Rechtsaussage geaendert. Erst die Freigabe, dann die neue Erwartung.
 */
class CheckoutOrderTest extends TestCase
{
    protected const TERMS = [
        'days' => 14,
        'text' => "Widerrufsrecht\n\nSie haben das Recht, binnen 14 Tagen zu widerrufen.\n\nFolgen des Widerrufs\n\nWir erstatten alle Zahlungen.",
        'waiver_text' => 'Ich verlange die sofortige Lieferung und weiß, dass mein Widerrufsrecht damit erlischt.',
        'checkbox_required' => true,
        'version' => '2026-09',
    ];

    protected function tearDown(): void
    {
        Consent::resolveTermsUsing(null);

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

    /**
     * Die Stelle, an der eine Marke im HTML steht — oder null, wenn sie fehlt.
     *
     * Gemessen wird die Position im Dokument, nicht die Zeilennummer: das
     * Template darf umgebrochen und eingerueckt werden, ohne diesen Test zu
     * beruehren. Nur die Abfolge zaehlt.
     */
    protected function positionOf(string $html, string $needle): ?int
    {
        $at = strpos($html, $needle);

        return $at === false ? null : $at;
    }

    #[Test]
    public function the_withdrawal_notice_stands_between_the_price_and_the_order_button(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->getContent();

        $preis = $this->positionOf($html, 'funnel-offer__price');
        $belehrung = $this->positionOf($html, 'funnel-offer__withdrawal');
        $haken = $this->positionOf($html, 'name="confirmed"');
        $knopf = $this->positionOf($html, 'funnel-offer__decline');

        $this->assertNotNull($preis, 'Der Preisblock fehlt auf der Kassenseite.');
        $this->assertNotNull($belehrung, 'Die Widerrufsbelehrung fehlt auf der Kassenseite.');
        $this->assertNotNull($haken, 'Der Einwilligungshaken fehlt auf der Kassenseite.');
        $this->assertNotNull($knopf, 'Das Ablehnen-Formular fehlt — ohne es ist der Bestellknopf nicht eingegrenzt.');

        // Der heutige Stand, festgehalten und NICHT als richtig behauptet:
        // Preis, dann Belehrung, dann Haken, dann Bestellknopf.
        $this->assertLessThan($belehrung, $preis, 'Der Preisblock steht nicht mehr vor der Belehrung.');
        $this->assertLessThan($haken, $belehrung, 'Der Haken steht nicht mehr hinter der Belehrung.');
        $this->assertLessThan($knopf, $haken, 'Der Haken steht nicht mehr vor dem Bestellknopf.');
    }

    #[Test]
    public function the_block_between_notice_and_button_is_watched_for_growth(): void
    {
        Consent::resolveTermsUsing(fn () => self::TERMS);
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->getContent();

        $von = $this->positionOf($html, 'funnel-offer__withdrawal');
        $bis = $this->positionOf($html, 'funnel-offer__decline');

        $this->assertNotNull($von);
        $this->assertNotNull($bis);

        // Der sichtbare Text ab der Belehrung bis zum Ende des Bestellformulars.
        // Das ist die Groesse, um die es in der Rechtsfrage geht: § 312j Abs. 3
        // sagt "unmittelbar", und was hier steht, schiebt die Pflichtangaben
        // vom Knopf weg.
        $dazwischen = trim(strip_tags(substr($html, $von, $bis - $von)));
        $laenge = mb_strlen((string) preg_replace('/\s+/', ' ', $dazwischen));

        // Kein Grenzwert mit Anspruch auf Richtigkeit — eine Wache. Waechst der
        // Block deutlich, wird das Problem groesser, und jemand soll es merken,
        // bevor es live geht.
        $this->assertGreaterThan(0, $laenge, 'Zwischen Belehrung und Knopf steht kein Text mehr — Reihenfolge geaendert?');
        $this->assertLessThan(2000, $laenge, "Der Block zwischen Belehrung und Bestellknopf ist auf {$laenge} Zeichen gewachsen. § 312j Abs. 3 verlangt die Pflichtangaben unmittelbar ueber dem Knopf; die Frage liegt beim Anwalt (backlog-kasse-reihenfolge-ueber-dem-bestellknopf). Nicht ohne Freigabe anheben.");
    }

    #[Test]
    public function without_a_withdrawal_notice_nothing_stands_between_the_price_and_the_button(): void
    {
        // Ohne Belehrung am Angebot ist die Kasse bereits so, wie § 312j Abs. 3
        // sie in der strengsten Lesart verlangt. Der Fall ist der Gegenbeweis
        // dafuer, dass die Reihenfolge an der Belehrung haengt und an nichts
        // sonst.
        $this->funnel();

        $html = $this->asVisitor()->get('/f/kurs/angebot')->assertOk()->getContent();

        $this->assertNull($this->positionOf($html, 'funnel-offer__withdrawal'));
        $this->assertNotNull($this->positionOf($html, 'funnel-offer__price'));
        $this->assertNotNull($this->positionOf($html, 'funnel-offer__accept'));
    }
}
