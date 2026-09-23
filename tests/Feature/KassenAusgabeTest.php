<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Support\BillingFields;
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
    public function der_knopftext_des_angebots_ist_maskiert(): void
    {
        $this->kasse(['button_label' => 'Kaufen <img src=x onerror=alert(3)>']);
        $this->bisZurKasse();

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertDontSee('<img src=x onerror', false)
            ->assertSee('Kaufen &lt;img', false);
    }

    /** Markdown mit einem `javascript:`-Link, einem Zitat und einem Autolink. */
    protected const MARKDOWN = "[klick](javascript:alert(4))\n\n> Ein Zitat\n\n<https://beispiel.de/kurs>";

    protected function assertSicheresMarkdown(string $html): void
    {
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<a href="https://beispiel.de/kurs">', $html);
    }

    #[Test]
    public function markdown_im_schritt_hat_keine_javascript_links_und_bleibt_markdown(): void
    {
        $this->kasse();
        FunnelStep::query()->where('node_key', 'entry_1')->firstOrFail()->forceFill(['config' => ['body' => self::MARKDOWN]])->save();

        $this->assertSicheresMarkdown($this->asVisitor()->get('/f/kurs')->assertOk()->getContent());
    }

    #[Test]
    public function markdown_im_angebot_hat_keine_javascript_links(): void
    {
        $this->kasse(['body' => self::MARKDOWN]);
        $this->bisZurKasse();

        $this->assertSicheresMarkdown($this->asVisitor()->get('/f/kurs/kasse')->assertOk()->getContent());
    }

    #[Test]
    public function markdown_im_bump_hat_keine_javascript_links(): void
    {
        Offer::create(['handle' => 'cd', 'name' => 'CD', 'body' => self::MARKDOWN, 'product' => 'begleit-cd', 'amount_cent' => 1900, 'slot' => 'bump', 'active' => true]);
        $this->kasse(['bumps' => ['cd']]);
        $this->bisZurKasse();

        $this->assertSicheresMarkdown($this->asVisitor()->get('/f/kurs/kasse')->assertOk()->getContent());
    }

    #[Test]
    public function eingaben_der_besucherin_brechen_kein_attribut_auf(): void
    {
        $this->kasse();
        FunnelStep::query()->where('node_key', 'capture_1')->firstOrFail()->forceFill(['config' => ['billing' => 'full']])->save();

        $boese = 'x" autofocus onfocus="alert(5)';
        $this->asVisitor()->get('/f/kurs');
        $this->asVisitor()->post('/f/kurs/entry_1/advance');
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', [
            'email' => 'k@example.com', 'name' => $boese, 'street' => $boese, 'postal_code' => '1', 'city' => $boese, 'country' => 'DE',
        ]);

        $seite = $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();

        $seite->assertDontSee('onfocus="alert(5)', false)
            ->assertSee('value="x&quot; autofocus onfocus=&quot;alert(5)"', false);
    }

    #[Test]
    public function labels_der_feld_bibliothek_sind_maskiert(): void
    {
        BillingFields::resolveLibraryUsing(fn () => [
            'name' => ['label' => 'Name <script>y()</script>', 'type' => 'text', 'required' => true],
            'company' => ['label' => 'Firma <img src=x onerror=z()>', 'type' => 'text', 'required' => false],
        ]);
        BillingFields::resolveFieldsUsing(fn () => ['name', 'company']);

        $this->kasse();
        FunnelStep::query()->where('node_key', 'capture_1')->firstOrFail()->forceFill(['config' => ['billing' => 'offer']])->save();

        $this->asVisitor()->get('/f/kurs');
        $this->asVisitor()->post('/f/kurs/entry_1/advance');
        $seite = $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();

        BillingFields::resolveLibraryUsing(null);
        BillingFields::resolveFieldsUsing(null);

        $seite->assertDontSee('<script>y()</script>', false)
            ->assertDontSee('<img src=x onerror', false);
    }

    #[Test]
    public function nach_einer_ablehnung_folgen_die_bumps_der_gewaehlten_zahlweise(): void
    {
        $this->brauchtOffers112();

        Offer::create(['handle' => 'cd', 'name' => 'CD', 'product' => 'begleit-cd', 'amount_cent' => 1900, 'slot' => 'bump', 'active' => true]);
        $this->kasse([
            'bumps' => ['cd'],
            'pricing_options' => [
                ['key' => 'einzeln', 'label' => 'Einzeln', 'amount_cent' => 9900],
                ['key' => 'team', 'label' => 'Team', 'amount_cent' => 19900],
            ],
        ], ['bump_rules' => ['cd' => ['options' => ['team']]]]);
        $this->bisZurKasse();

        $this->asVisitor()->from('/f/kurs/kasse')->post('/f/kurs/kasse/advance', [
            'accept' => '1', 'confirmed' => '1', 'pricing_option' => 'team', 'bumps' => ['cd'], 'coupon' => 'VERTIPPT',
        ]);

        // Zur Zahlweise „team" gehoert der Bump: sichtbar und angekreuzt.
        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('data-funnel-bump-options="team">', false)
            ->assertSee('value="cd" checked', false);
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
