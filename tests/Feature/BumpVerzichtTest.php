<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Catalogue;
use PHPUnit\Framework\Attributes\Test;

/**
 * Der Verzicht auf das Widerrufsrecht fuer einen digitalen Bump.
 *
 * § 356 Abs. 6 Nr. 2 BGB: ausdrueckliche Zustimmung zum Beginn vor Fristablauf
 * und bestaetigte Kenntnis vom Erloeschen. Der Haken `confirmed` gilt der
 * Bestellung; fuer ein digitales Bump-Produkt kommt ein zweiter, leerer Haken
 * dazu, nur sichtbar und Pflicht, wenn dieser Bump gewaehlt ist.
 */
class BumpVerzichtTest extends TestCase
{
    use WalksAFunnel;

    protected function mitBump(?bool $digital = null): void
    {
        // `null`: der Katalog kennt das Merkmal nicht, ob digital ist nicht erkennbar.
        Catalogue::extend(fn (string $handle) => $handle === 'toolkit'
            ? ['amount_cent' => 1700] + ($digital === null ? [] : ['digital' => $digital])
            : null);

        Offer::create(['handle' => 'toolkit-bump', 'name' => 'Selbstanalyse-Toolkit', 'product' => 'toolkit', 'amount_cent' => 1700, 'slot' => 'bump', 'active' => true]);

        $this->kasse(['bumps' => ['toolkit-bump']]);
        $this->bisZurKasse();
    }

    protected function bestellen(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->asVisitor()->post('/f/kurs/kasse/advance', array_merge(['accept' => '1', 'confirmed' => '1'], $extra));
    }

    #[Test]
    public function ein_digitaler_bump_ohne_verzichts_haken_wird_abgelehnt(): void
    {
        $this->mitBump(true);

        $this->bestellen(['bumps' => ['toolkit-bump']])->assertSessionHasErrors('bump_waiver');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function ohne_bump_ist_der_verzichts_haken_nicht_noetig(): void
    {
        $this->mitBump(true);

        $this->bestellen([])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Payment::count());
        $this->assertArrayNotHasKey('bump_waivers', (array) Payment::query()->sole()->meta);
    }

    #[Test]
    public function ein_physischer_bump_braucht_keinen_verzicht(): void
    {
        $this->mitBump(false);

        $this->bestellen(['bumps' => ['toolkit-bump']])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function ist_nicht_erkennbar_ob_digital_gilt_der_haken_bei_jedem_bump(): void
    {
        $this->mitBump(null);

        $this->bestellen(['bumps' => ['toolkit-bump']])->assertSessionHasErrors('bump_waiver');
    }

    #[Test]
    public function mit_bump_und_haken_wird_der_nachweis_gespeichert(): void
    {
        $this->mitBump(true);

        $this->bestellen(['bumps' => ['toolkit-bump'], 'bump_waiver' => ['toolkit-bump']])
            ->assertSessionHasNoErrors()->assertRedirect();

        $nachweis = Payment::query()->sole()->meta['bump_waivers'][0] ?? null;

        $this->assertNotNull($nachweis);
        $this->assertSame('toolkit-bump', $nachweis['offer']);
        $this->assertSame(['toolkit'], $nachweis['products']);
        $this->assertNotEmpty($nachweis['at']);
        $this->assertStringContainsString('Selbstanalyse-Toolkit', $nachweis['text']);
        $this->assertStringContainsString('356 (6) BGB', $nachweis['text']);
    }

    #[Test]
    public function ein_haken_ohne_gewaehlten_bump_wird_ignoriert(): void
    {
        $this->mitBump(true);

        $this->bestellen(['bump_waiver' => ['toolkit-bump']])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertArrayNotHasKey('bump_waivers', (array) Payment::query()->sole()->meta);
    }

    #[Test]
    public function die_seite_zeigt_den_zweiten_haken_leer(): void
    {
        $this->mitBump(true);

        $html = $this->asVisitor()->get('/f/kurs/kasse')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="checkbox" name="bump_waiver\[\]" value="toolkit-bump"(?![^>]*checked)[^>]*>/', $html);
        $this->assertStringContainsString('data-funnel-bump-waiver="toolkit-bump"', $html);
        $this->assertStringContainsString('356 (6) BGB', $html);
    }

    #[Test]
    public function die_seite_zeigt_bei_physischem_bump_keinen_zweiten_haken(): void
    {
        $this->mitBump(false);

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()->assertDontSee('bump_waiver');
    }

    #[Test]
    public function ein_bump_im_korb_der_nicht_mehr_slot_bump_ist_braucht_trotzdem_den_haken(): void
    {
        $this->mitBump(true);
        // Der Slot wechselt nach dem Laden der Seite; im Korb landet der Bump trotzdem.
        Offer::query()->where('handle', 'toolkit-bump')->update(['slot' => Offer::SLOT_STANDALONE]);

        $this->bestellen(['bumps' => ['toolkit-bump']])->assertSessionHasErrors('bump_waiver');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function ein_veralteter_wortlaut_des_verzichts_bestellt_nicht(): void
    {
        $this->mitBump(true);

        $this->bestellen([
            'bumps' => ['toolkit-bump'], 'bump_waiver' => ['toolkit-bump'],
            'bump_waiver_text' => ['toolkit-bump' => 'Ein Wortlaut von gestern'],
        ])->assertSessionHasErrors('bump_waiver');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function der_gezeigte_wortlaut_geht_versteckt_zurueck_und_wird_akzeptiert(): void
    {
        $this->mitBump(true);

        $html = $this->asVisitor()->get('/f/kurs/kasse')->getContent();
        $this->assertStringContainsString('name="bump_waiver_text[toolkit-bump]"', $html);

        $text = html_entity_decode(\Illuminate\Support\Str::before(\Illuminate\Support\Str::after($html, 'name="bump_waiver_text[toolkit-bump]" value="'), '"'));

        $this->bestellen([
            'bumps' => ['toolkit-bump'], 'bump_waiver' => ['toolkit-bump'],
            'bump_waiver_text' => ['toolkit-bump' => $text],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function nach_einer_ablehnung_bleibt_der_verzichts_haken_angekreuzt(): void
    {
        $this->mitBump(true);

        $this->asVisitor()->from('/f/kurs/kasse')->post('/f/kurs/kasse/advance', [
            'accept' => '1', 'confirmed' => '1', 'bumps' => ['toolkit-bump'],
            'bump_waiver' => ['toolkit-bump'], 'coupon' => 'VERTIPPT',
        ])->assertSessionHasErrors('coupon');

        $this->asVisitor()->get('/f/kurs/kasse')->assertOk()
            ->assertSee('name="bump_waiver[]" value="toolkit-bump" checked', false);
    }
}
