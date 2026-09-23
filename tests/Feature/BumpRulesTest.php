<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * F1: Bump-Regeln.
 *
 * Ein Bump kann an der gewaehlten Zahlweise haengen, an einem anderen Bump,
 * vorausgewaehlt sein und fuer wiederkehrende Kaeufer:innen erscheinen oder
 * nicht. Die Seite zeigt es, **der Server setzt es durch**: ein Haekchen, das
 * die Regel verbietet, kauft nichts, egal was das Formular schickt.
 */
class BumpRulesTest extends TestCase
{
    use WalksAFunnel;

    protected function mitBumps(array $regeln): void
    {
        Offer::create(['handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'amount_cent' => 1900, 'slot' => 'bump', 'active' => true]);
        Offer::create(['handle' => 'noten', 'name' => 'Notenpaket', 'product' => 'begleit-cd', 'amount_cent' => 900, 'slot' => 'bump', 'active' => true]);

        $this->kasse(
            [
                'bumps' => ['cd', 'noten'],
                'pricing_options' => [
                    ['key' => 'einzeln', 'label' => 'Einzeln', 'amount_cent' => 9900],
                    ['key' => 'team', 'label' => 'Team', 'amount_cent' => 19900],
                ],
            ],
            ['bump_rules' => $regeln],
        );
    }

    /**
     * Was die Zahlung wirklich traegt, sortiert: die Zeilenfolge ist nicht Gegenstand.
     *
     * @return list<string>
     */
    protected function gekauft(): array
    {
        $produkte = Payment::query()->latest('id')->firstOrFail()->items()->pluck('product')->all();
        sort($produkte);

        return $produkte;
    }

    /** @param  list<string>  $erwartet */
    protected function assertGekauft(array $erwartet): void
    {
        sort($erwartet);

        $this->assertSame($erwartet, $this->gekauft());
    }

    protected function bestellen(string $option, array $bumps): void
    {
        $this->asVisitor()->post('/f/kurs/kasse/advance', [
            'accept' => '1', 'confirmed' => '1', 'pricing_option' => $option, 'bumps' => $bumps,
        ])->assertRedirect();
    }

    #[Test]
    public function ohne_regeln_bleibt_alles_wie_es_war(): void
    {
        $this->mitBumps([]);
        $this->bisZurKasse();

        $this->bestellen('einzeln', ['cd', 'noten']);

        $this->assertGekauft(['offer:kurs:einzeln', 'offer:cd', 'offer:noten']);
    }

    #[Test]
    public function ein_bump_nur_zur_zahlweise_team_kauft_bei_einzeln_nichts(): void
    {
        $this->mitBumps(['cd' => ['options' => ['team']]]);
        $this->bisZurKasse();

        $this->bestellen('einzeln', ['cd']);

        $this->assertGekauft(['offer:kurs:einzeln']);
    }

    #[Test]
    public function ein_bump_nur_zur_zahlweise_team_kauft_bei_team_mit(): void
    {
        $this->mitBumps(['cd' => ['options' => ['team']]]);
        $this->bisZurKasse();

        $this->bestellen('team', ['cd']);

        $this->assertGekauft(['offer:kurs:team', 'offer:cd']);
    }

    #[Test]
    public function ein_bump_der_einen_anderen_braucht_faellt_ohne_ihn_heraus(): void
    {
        $this->mitBumps(['noten' => ['requires' => 'cd']]);
        $this->bisZurKasse();

        $this->bestellen('einzeln', ['noten']);

        $this->assertGekauft(['offer:kurs:einzeln']);
    }

    #[Test]
    public function mit_dem_anderen_bump_kauft_er_mit(): void
    {
        $this->mitBumps(['noten' => ['requires' => 'cd']]);
        $this->bisZurKasse();

        $this->bestellen('einzeln', ['cd', 'noten']);

        $this->assertGekauft(['offer:kurs:einzeln', 'offer:cd', 'offer:noten']);
    }

    #[Test]
    public function die_seite_traegt_die_regeln_und_die_vorauswahl(): void
    {
        $this->mitBumps([
            'cd' => ['preselected' => true],
            'noten' => ['requires' => 'cd', 'options' => ['team']],
        ]);
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();

        $seite->assertSee('data-funnel-bump-options="team"', false)
            ->assertSee('data-funnel-bump-requires="cd"', false)
            ->assertSee('value="cd" checked', false)
            // Zur ersten Zahlweise (einzeln) gehoert das Notenpaket nicht: es
            // geht versteckt auf und nicht angekreuzt.
            ->assertSee('data-funnel-bump-requires="cd" hidden', false);
    }

    #[Test]
    public function fuer_wiederkehrende_kaeufer_versteckt(): void
    {
        $this->mitBumps(['cd' => ['returning' => 'hide']]);
        $this->frueherGekauft('k@example.com');
        $this->bisZurKasse('k@example.com');

        $this->asVisitor()->get('/f/kurs/kasse')->assertDontSee('value="cd"', false);

        $this->bestellen('einzeln', ['cd']);

        $this->assertGekauft(['offer:kurs:einzeln']);
    }

    #[Test]
    public function fuer_neue_kaeufer_bleibt_er_sichtbar(): void
    {
        $this->mitBumps(['cd' => ['returning' => 'hide']]);
        $this->frueherGekauft('jemand.anderes@example.com');
        $this->bisZurKasse('k@example.com');

        $this->asVisitor()->get('/f/kurs/kasse')->assertSee('value="cd"', false);
    }

    #[Test]
    public function nur_fuer_wiederkehrende_kaeufer(): void
    {
        $this->mitBumps(['cd' => ['returning' => 'only']]);
        $this->bisZurKasse('neu@example.com');

        $this->asVisitor()->get('/f/kurs/kasse')->assertDontSee('value="cd"', false);

        $this->bestellen('einzeln', ['cd']);

        $this->assertGekauft(['offer:kurs:einzeln']);
    }

    protected function frueherGekauft(string $email): void
    {
        Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_alt', 'product' => 'kurs',
            'amount_cent' => 9900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => $email,
        ]);
    }
}
