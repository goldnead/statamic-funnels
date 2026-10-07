<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Der Knopf, der eine Zahlung ausloest, sagt es.
 *
 * § 312j Abs. 3 BGB: der Knopf muss eindeutig darauf hinweisen, dass die
 * Bestellung zahlungspflichtig ist. „Paket buchen", „Jetzt anmelden" oder
 * „Jetzt dazunehmen" tun das nicht, und die Beschriftung des Angebots
 * (`button_label`) ist ein Werbetext, den jemand im CP tippt. Der Knopf in der
 * Kasse und der Knopf, der einen Upsell mit einem Klick abbucht, zeigen deshalb
 * immer die Uebersetzung, nie das Label des Angebots.
 *
 * Beides ist derselbe Schritt der Vorlage; die Tests stehen trotzdem getrennt,
 * damit ein kuenftiger eigener Upsell-Block nicht still zurueckfaellt.
 */
class BestellknopfIstZahlungspflichtigTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('de');
    }

    #[Test]
    public function der_knopf_der_kasse_ignoriert_das_label_des_angebots(): void
    {
        $this->kasse(['button_label' => 'Paket buchen']);
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();

        $seite->assertSee('>Zahlungspflichtig bestellen</button>', false)
            ->assertDontSee('Paket buchen');
    }

    #[Test]
    public function der_annahme_knopf_eines_upsells_ignoriert_das_label_des_angebots(): void
    {
        $this->kasse([
            'slot' => Offer::SLOT_POST_PURCHASE,
            'button_label' => 'Jetzt dazunehmen',
        ]);
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();

        $seite->assertSee('>Zahlungspflichtig bestellen</button>', false)
            ->assertDontSee('Jetzt dazunehmen');
    }

    #[Test]
    public function ohne_label_steht_derselbe_text(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('>Zahlungspflichtig bestellen</button>', false);
    }

    #[Test]
    public function ein_abo_sagt_es_am_knopf(): void
    {
        $this->kasse([
            'button_label' => 'Jetzt anmelden',
            'pricing_options' => [
                ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 1900, 'interval' => '1 month'],
            ],
        ]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('>Zahlungspflichtig abonnieren</button>', false)
            ->assertDontSee('Jetzt anmelden');
    }

    #[Test]
    public function wechselt_die_zahlweise_zwischen_einmal_und_abo_bleibt_es_beim_allgemeinen_text(): void
    {
        $this->kasse([
            'pricing_options' => [
                ['key' => 'einmal', 'label' => 'Einmal', 'amount_cent' => 9900],
                ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 1900, 'interval' => '1 month'],
            ],
        ]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('>Zahlungspflichtig bestellen</button>', false);
    }

    #[Test]
    public function die_englische_fassung_ist_ebenso_eindeutig(): void
    {
        app()->setLocale('en');
        $this->kasse(['button_label' => 'Get started']);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('>Order with obligation to pay</button>', false)
            ->assertDontSee('Get started');
    }
}
