<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicFunnels\Support\Consent;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Kasse zeigt den Einwilligungstext der Marke, die verkauft.
 *
 * Gefunden am 23.09.2026 im Playground beim Einbetten (F4): die Seite
 * zeichnete den Wortlaut unter der Marke der Anfrage (bei `/f/...` die
 * Standardmarke), die Kasse verglich ihn unter der Marke des Angebots. Mit
 * zwei Marken und je eigenem Text passte der zurueckgeschickte Wortlaut nie,
 * und **jede** Bestellung eines Angebots der zweiten Marke endete in „Die
 * Bedingungen haben sich geaendert". Unabhaengig vom Einbetten, auch ganz oben.
 */
class EinwilligungUnterDerMarkeTest extends TestCase
{
    use WalksAFunnel;

    protected Brand $fremde;

    protected Brand $eigene;

    protected function setUp(): void
    {
        parent::setUp();

        config(['brand-context.multi_brand' => true]);

        $this->fremde = Brand::create(['handle' => 'fremde', 'name' => 'Fremde Marke']);
        $this->eigene = Brand::create(['handle' => 'eigene', 'name' => 'Marke des Angebots']);

        // Der Wortlaut haengt an der Marke, wie bei Widerrufstexten aus der
        // Einstellungs-Schicht.
        Consent::resolveTermsUsing(fn () => [
            'waiver_text' => 'Zustimmung unter '.BrandContext::current()->handle,
            'version' => 'v-'.BrandContext::current()->handle,
            'checkbox_required' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Consent::resolveTermsUsing(null);

        parent::tearDown();
    }

    /** Jede Anfrage beginnt unter der fremden Marke, wie ohne Marken-Pfad im Betrieb. */
    protected function fremd(): static
    {
        BrandContext::setCurrent($this->fremde);

        return $this->asVisitor();
    }

    #[Test]
    public function die_kasse_zeigt_den_text_der_marke_des_angebots_und_nimmt_ihn_an(): void
    {
        $this->kasse();
        Offer::query()->where('handle', 'kurs')->update(['brand_id' => $this->eigene->id]);

        $this->fremd()->get('/f/kurs');
        $this->fremd()->post('/f/kurs/entry_1/advance');
        $this->fremd()->get('/f/kurs/anmeldung');
        $this->fremd()->post('/f/kurs/capture_1/advance', ['email' => 'k@example.com']);

        $seite = $this->fremd()->get('/f/kurs/kasse')->assertOk();
        $seite->assertSee('value="Zustimmung unter eigene [v-eigene]"', false);

        $this->fremd()->post('/f/kurs/kasse/advance', [
            'accept' => '1',
            'confirmed' => '1',
            'consent_text' => 'Zustimmung unter eigene [v-eigene]',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Payment::count());
    }
}
