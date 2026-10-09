<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Was die Kasse als „heute faellig" nennt, ist das, was gebucht wird.
 *
 * **Der Fehler, gegen den diese Datei geschrieben ist** (Staging-Testkauf
 * 09.10.2026): ein Abo mit bezahltem Testmonat zeigte „Gesamt heute 65 EUR",
 * Mollie buchte 45 EUR; ein Gutschein ueber 10 EUR auf 37 EUR zeigte 37, gebucht
 * wurden 27. Die Kasse gab der Vorlage nur den Angebotspreis mit. § 312j BGB
 * will den Gesamtpreis vor dem Kaufknopf, und der muss der sein, der abgebucht
 * wird.
 *
 * Jeder Test liest die Zahl, die die Seite nennt (`data-funnel-due-table`, so wie
 * das Skript sie nachschlaegt), kauft dann **durch dieselbe Kasse** und
 * vergleicht mit `payments.amount_cent`. Keine Zahl ist hier von Hand
 * ausgerechnet, ausser der Erwartung, damit „beide falsch, aber gleich" auffaellt.
 */
class KasseZeigtDieErsteZahlungTest extends TestCase
{
    use WalksAFunnel;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Ohne das kann dieser Betrieb keine Abos beginnen.
        $app['config']->set('statamic-payments.follow_up.enabled', true);
        $app['config']->set('statamic-payments.follow_up.collect_mandate', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->brauchtOffers112();
    }

    protected function bump(string $handle, int $cent): void
    {
        Offer::create(['handle' => $handle, 'name' => ucfirst($handle), 'product' => 'begleit-cd', 'amount_cent' => $cent, 'slot' => 'bump', 'active' => true]);
    }

    protected function gutschein(array $spalten): Coupon
    {
        return Coupon::create(array_merge(['code' => 'CHOR', 'name' => 'Chorrabatt', 'active' => true], $spalten));
    }

    /**
     * Die Zahl, die die Seite fuer diese Auswahl nennt, in Cent.
     *
     * @param  list<string>  $bumps
     */
    protected function angezeigt(array $bumps = [], string $link = '/f/kurs/kasse', string $option = ''): int
    {
        $html = $this->asVisitor()->get($link)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-funnel-due-table="([^"]*)"/', $html, 'die Kasse nennt keine Zahl fuer „heute faellig"');
        preg_match('/data-funnel-due-table="([^"]*)"/', $html, $treffer);

        $tabelle = json_decode(html_entity_decode($treffer[1]), true);
        $sortiert = $bumps;
        sort($sortiert);

        $this->assertIsInt($tabelle[$option][implode(',', $sortiert)] ?? null, 'fuer diese Auswahl steht keine Zahl in der Tabelle');

        return $tabelle[$option][implode(',', $sortiert)];
    }

    /** Durch dieselbe Kasse kaufen und sagen, was gebucht wurde. */
    protected function gebucht(array $felder = []): int
    {
        $this->asVisitor()->post('/f/kurs/kasse/advance', array_merge(['accept' => '1', 'confirmed' => '1'], $felder))->assertRedirect();

        return (int) Payment::query()->latest('id')->firstOrFail()->amount_cent;
    }

    /** Das Abo aus dem Staging-Testkauf: 65 EUR im Monat, der erste Monat 45 EUR. */
    protected function stimmfest(array $zusatz = []): void
    {
        $this->kasse(array_merge([
            'amount_cent' => 6500,
            'interval' => '1 month',
            'trial_days' => 30,
            'trial_amount_cent' => 4500,
        ], $zusatz));
    }

    #[Test]
    public function a_bezahlter_testzeitraum_zeigt_die_erste_zahlung_und_bucht_sie(): void
    {
        $this->stimmfest();
        $this->bisZurKasse();

        $angezeigt = $this->angezeigt();

        $this->assertSame(4500, $angezeigt);
        $this->assertSame($angezeigt, $this->gebucht());
    }

    #[Test]
    public function a_die_kasse_sagt_auch_was_danach_kommt_und_ab_wann(): void
    {
        $this->stimmfest();
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')
            ->assertSee('data-funnel-due="4500"', false)
            // Der Preis danach, der Rhythmus und der Tag der ersten Abbuchung:
            // ohne sie ist die Preisangabe unvollstaendig.
            ->assertSee(__('statamic-funnels::messages.due_after', [
                'amount' => Offer::localise(6500),
                'currency' => 'EUR',
                'interval' => __('statamic-funnels::messages.interval_1 month'),
                'date' => now()->addDays(30)->isoFormat('L'),
            ]), false);
    }

    #[Test]
    public function b_gutschein_ueber_einen_betrag_wird_in_der_zahl_abgezogen(): void
    {
        $this->kasse(['amount_cent' => 3700]);
        $this->gutschein(['amount_cent' => 1000, 'currency' => 'EUR']);
        $this->bisZurKasse();

        $angezeigt = $this->angezeigt([], '/f/kurs/kasse?coupon=CHOR');

        $this->assertSame(2700, $angezeigt);
        $this->assertSame($angezeigt, $this->gebucht(['coupon' => 'CHOR']));
    }

    #[Test]
    public function c_gutschein_in_prozent_wird_in_der_zahl_abgezogen(): void
    {
        $this->kasse();
        $this->gutschein(['percent' => 20]);
        $this->bisZurKasse();

        $angezeigt = $this->angezeigt([], '/f/kurs/kasse?coupon=CHOR');

        $this->assertSame(7920, $angezeigt);
        $this->assertSame($angezeigt, $this->gebucht(['coupon' => 'CHOR']));
    }

    #[Test]
    public function d_ein_gewoehnliches_angebot_zeigt_den_preis(): void
    {
        $this->kasse();
        $this->bisZurKasse();

        $angezeigt = $this->angezeigt();

        $this->assertSame(9900, $angezeigt);
        $this->assertSame($angezeigt, $this->gebucht());
    }

    #[Test]
    public function e_bump_und_hauptangebot_zusammen(): void
    {
        $this->bump('cd', 1900);
        $this->kasse(['bumps' => ['cd']]);
        $this->bisZurKasse();

        $ohne = $this->angezeigt([]);
        $mit = $this->angezeigt(['cd']);

        $this->assertSame(9900, $ohne);
        $this->assertSame(11800, $mit);
        $this->assertSame($mit, $this->gebucht(['bumps' => ['cd']]));
    }

    #[Test]
    public function e_bump_zu_einem_abo_mit_testmonat(): void
    {
        $this->bump('cd', 1900);
        $this->stimmfest(['bumps' => ['cd']]);
        $this->bisZurKasse();

        $mit = $this->angezeigt(['cd']);

        $this->assertSame(6400, $mit);
        $this->assertSame($mit, $this->gebucht(['bumps' => ['cd']]));
    }

    #[Test]
    public function e_bump_und_prozentgutschein_auf_den_ganzen_korb(): void
    {
        $this->bump('cd', 1900);
        $this->kasse(['bumps' => ['cd']]);
        $this->gutschein(['percent' => 20]);
        $this->bisZurKasse();

        $mit = $this->angezeigt(['cd'], '/f/kurs/kasse?coupon=CHOR');

        $this->assertSame(9440, $mit);
        $this->assertSame($mit, $this->gebucht(['bumps' => ['cd'], 'coupon' => 'CHOR']));
    }

    #[Test]
    public function die_zahlweise_bringt_ihre_eigene_zahl_mit(): void
    {
        $this->kasse([
            'pricing_options' => [
                ['key' => 'voll', 'label' => 'Einmalig', 'amount_cent' => 9900],
                ['key' => 'abo', 'label' => 'Monatlich', 'amount_cent' => 6500, 'interval' => '1 month', 'trial_days' => 30, 'trial_amount_cent' => 4500],
            ],
        ]);
        $this->bisZurKasse();

        $this->assertSame(9900, $this->angezeigt([], '/f/kurs/kasse', 'voll'));
        $abo = $this->angezeigt([], '/f/kurs/kasse', 'abo');

        $this->assertSame(4500, $abo);
        $this->assertSame($abo, $this->gebucht(['pricing_option' => 'abo']));
    }
}
