<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Http\Controllers\Web\FunnelController;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;

/**
 * The price on the offer page is read by a person, so it follows their language.
 *
 * It did not. The page printed `1249.50` on a German site, where the dot is the
 * thousands separator — so the number did not merely look foreign, it named a
 * different amount. Meanwhile anything that parses still needs the dot, which
 * is why the step now carries both and the template picks the readable one.
 */
class PriceReadsAsGermanTest extends TestCase
{
    private function funnelMitPreis(int $cent): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Start']]);
        $funnel->steps()->create([
            'node_key' => 'offer_1', 'type' => 'offer', 'slug' => 'angebot',
            'config' => ['offer' => 'cd'],
        ]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default']);

        Offer::create([
            'handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd',
            'active' => true, 'amount_cent' => $cent,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function the_offer_page_writes_the_price_the_way_the_language_does(): void
    {
        if (! class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('ext-intl is what knows how a language writes a number.');
        }

        $this->funnelMitPreis(124950);
        $this->app->setLocale('de');

        $antwort = $this->get('/f/kurs/angebot');

        $antwort->assertOk();
        $antwort->assertSee('1.249,50', escape: false);
        // The machine-readable form must not leak into the page as a price.
        $antwort->assertDontSee('>1249.50<', escape: false);
    }

    #[Test]
    public function the_same_page_in_english_writes_it_the_english_way(): void
    {
        if (! class_exists(\NumberFormatter::class)) {
            $this->markTestSkipped('ext-intl is what knows how a language writes a number.');
        }

        $this->funnelMitPreis(124950);
        $this->app->setLocale('en');

        $this->get('/f/kurs/angebot')->assertOk()->assertSee('1,249.50', escape: false);
    }

    #[Test]
    public function both_shapes_reach_the_template(): void
    {
        // The parseable one has to stay available: a theme that posts the price
        // somewhere, or reads it back, must not be forced through a comma.
        $this->funnelMitPreis(24900);
        $this->app->setLocale('de');

        $steuerung = app(FunnelController::class);
        $methode = new \ReflectionMethod($steuerung, 'offerFor');
        $methode->setAccessible(true);

        $schritt = FunnelStep::where('node_key', 'offer_1')->firstOrFail();
        $kontext = $methode->invoke($steuerung, $schritt);

        $this->assertSame('249.00', $kontext['amount']);
        $this->assertSame('249,00', $kontext['amount_local']);
    }
}
