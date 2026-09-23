<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;

/**
 * Was die mitgelieferte Vorlage ausgibt, und wie.
 *
 * - **Maskiert.** Ueberschrift, Text und die Texte von Angebot und Bumps
 *   kommen aus dem CP. Roh ausgegeben waeren sie ein Weg, JavaScript auf die
 *   Seite zu bringen, ohne das Recht „Tracking-Code bearbeiten" (Kritik
 *   23.09.2026). Markdown bleibt, HTML darin nicht.
 * - **Was getippt war, bleibt.** Nach einer Ablehnung stehen Zahlweise,
 *   Haekchen, Land und Code wieder da, und die Meldung steht am Feld.
 */
class KassenAusgabeTest extends TestCase
{
    use WalksAFunnel;

    #[Test]
    public function ueberschrift_und_text_des_schritts_sind_maskiert(): void
    {
        $this->kasse();
        FunnelStep::query()->where('node_key', 'entry_1')->firstOrFail()->forceFill(['config' => [
            'headline' => 'Hallo <script>alert(1)</script>',
            'body' => '**fett** und <img src=x onerror=alert(2)>',
        ]])->save();

        $seite = $this->asVisitor()->get('/f/kurs')->assertOk();

        $seite->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Hallo &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('<strong>fett</strong>', false);
    }

    #[Test]
    public function angebots_und_bump_texte_sind_maskiert(): void
    {
        Offer::create(['handle' => 'cd', 'name' => 'CD', 'headline' => 'CD <b onmouseover=x()>dazu</b>', 'body' => 'Mit <iframe src=//boese></iframe>', 'product' => 'begleit-cd', 'amount_cent' => 1900, 'slot' => 'bump', 'active' => true]);
        $this->kasse(['headline' => 'Kurs <script>x()</script>', 'body' => 'Text <svg onload=x()>', 'bumps' => ['cd']]);
        $this->bisZurKasse();

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();

        $seite->assertDontSee('<script>x()</script>', false)
            ->assertDontSee('<svg onload', false)
            ->assertDontSee('<b onmouseover', false)
            ->assertDontSee('<iframe src=//boese', false)
            ->assertSee('Kurs &lt;script&gt;', false);
    }

    #[Test]
    public function nach_einer_ablehnung_bleibt_die_auswahl_und_die_meldung_steht_am_feld(): void
    {
        $this->brauchtOffers112();

        Offer::create(['handle' => 'cd', 'name' => 'CD', 'product' => 'begleit-cd', 'amount_cent' => 1900, 'slot' => 'bump', 'active' => true]);
        $this->kasse([
            'bumps' => ['cd'],
            'country_mode' => 'only', 'countries' => ['DE', 'AT'],
            'pricing_options' => [
                ['key' => 'einzeln', 'label' => 'Einzeln', 'amount_cent' => 9900],
                ['key' => 'team', 'label' => 'Team', 'amount_cent' => 19900],
            ],
        ]);
        $this->bisZurKasse();

        $this->asVisitor()->from('/f/kurs/kasse')->post('/f/kurs/kasse/advance', [
            'accept' => '1', 'confirmed' => '1', 'pricing_option' => 'team',
            'bumps' => ['cd'], 'country' => 'AT', 'coupon' => 'VERTIPPT',
        ])->assertSessionHasErrors('coupon');

        $seite = $this->asVisitor()->get('/f/kurs/kasse')->assertOk();

        $seite->assertSee('value="team" checked', false)
            ->assertDontSee('value="einzeln" checked', false)
            ->assertSee('value="cd" checked', false)
            ->assertSee('<option value="AT" selected>', false)
            ->assertSee('name="coupon" value="VERTIPPT"', false)
            // Am Feld, nicht nur oben in der Liste.
            ->assertSee('class="funnel-offer__field-error" role="alert">'.__('statamic-funnels::messages.coupon_unknown'), false);
    }

    #[Test]
    public function der_preis_oben_folgt_der_zahlweise(): void
    {
        $this->kasse(['pricing_options' => [
            ['key' => 'einzeln', 'label' => 'Einzeln', 'amount_cent' => 9900],
            ['key' => 'team', 'label' => 'Team', 'amount_cent' => 19900],
        ]]);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('data-funnel-price="'.Offer::localise(19900).' EUR"', false)
            ->assertSee('data-funnel-price-target', false);
    }
}
