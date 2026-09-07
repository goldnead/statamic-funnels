<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Facades\BrandSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicFunnels\Support\Settings;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Einstellungen dieses Addons an der geteilten Schicht.
 *
 * Geprüft wird nicht, dass die Schicht funktioniert — das gehört in deren
 * eigene Suite — sondern dass dieses Addon richtig daran hängt, und dass ein
 * gespeicherter Wert bis zum Leser durchkommt. `Thumbnails` ist so ein Leser:
 * die Klasse, die entscheidet, ob und wie groß ein Bild erzeugt wird.
 */
class SettingsTest extends TestCase
{
    #[Test]
    public function it_registers_itself_with_the_shared_settings_layer(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertTrue($registry->has('funnels'), 'boot() hat die Einstellungen nicht angemeldet.');
        $this->assertSame(Settings::class, $registry->provider('funnels'));
        // Nicht `funnels`: die Config-Datei heißt anders als der Namensraum,
        // und ein falscher Pfad schriebe Werte in fremde Config.
        $this->assertSame('statamic-funnels', $registry->configPath('funnels'));
        $this->assertSame('manage funnels settings', $registry->permission('funnels'));
    }

    #[Test]
    public function a_saved_thumbnail_size_reaches_the_renderer(): void
    {
        $this->assertSame(640, Thumbnails::width());

        BrandSettings::for('funnels')->save(['thumbnails.width' => 800, 'thumbnails.height' => 500]);

        $this->assertSame(800, Thumbnails::width());
        $this->assertSame(500, Thumbnails::height());
    }

    #[Test]
    public function switching_thumbnails_off_reaches_the_renderer(): void
    {
        $this->assertTrue(Thumbnails::enabled());

        BrandSettings::for('funnels')->save(['thumbnails.enabled' => false]);

        $this->assertFalse(Thumbnails::enabled());
    }

    #[Test]
    public function a_saved_coupon_switch_reaches_the_config_the_offer_page_reads(): void
    {
        BrandSettings::for('funnels')->save(['coupons' => false]);

        $this->assertFalse(config('statamic-funnels.coupons'));
    }

    #[Test]
    public function nothing_read_while_booting_or_dead_is_offered(): void
    {
        $offered = array_keys(app(SettingsRegistry::class)->fields('funnels'));

        // `route_prefix` wird beim Registrieren der Routen gelesen, also bevor
        // die Schicht ihre Werte auf die Config legt.
        $this->assertNotContains('route_prefix', $offered);

        // `integrations.entitlements` steht in der Config, wird aber nirgends
        // gelesen. Ein Schalter, der nichts schaltet, gehört nicht auf einen
        // Bildschirm.
        $this->assertNotContains('integrations.entitlements', $offered);

        // Abbildung beziehungsweise Maschinenpfade.
        foreach (['thumbnails.cookies', 'thumbnails.hide_selectors', 'thumbnails.disk', 'thumbnails.chrome_path'] as $key) {
            $this->assertNotContains($key, $offered);
        }
    }
}
