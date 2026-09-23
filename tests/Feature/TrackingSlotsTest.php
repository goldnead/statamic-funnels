<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Goldnead\StatamicFunnels\Tests\Support\ConsentTagProbe;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * F6: Tracking-Code je Funnel und je Kassenschritt, nur mit Einwilligung.
 *
 * Kopf-Code auf jeder Seite, Danke-Code einmal je bezahltem Kauf, mit den
 * Platzhaltern {amount}, {currency} und {order_id}. Mit statamic-consent wird
 * jedes Skript geparkt und startet erst mit der Einwilligung; ohne das Addon
 * entscheidet die Config, und die Vorgabe gibt nichts aus.
 */
class TrackingSlotsTest extends TestCase
{
    use WalksAFunnel;

    protected const KOPF = '<script>window.kopf = 1;</script><noscript><img src="https://tracker.example/p.gif"></noscript>';

    protected const DANKE = '<script>window.kauf = {v: "{amount}", c: "{currency}", id: "{order_id}"};</script>';

    protected function tearDown(): void
    {
        TrackingConsent::resolveUsing(null);

        parent::tearDown();
    }

    protected function ohneConsentAddon(string $modus): void
    {
        TrackingConsent::resolveUsing(fn () => ['addon' => false, 'granted' => false]);
        config(['statamic-funnels.tracking.without_consent_addon' => $modus]);
    }

    protected function mitConsentAddon(bool $granted): void
    {
        TrackingConsent::resolveUsing(fn () => ['addon' => true, 'granted' => $granted]);
    }

    protected function funnelMitCode(array $step = []): void
    {
        $this->kasse([], $step, ['settings' => ['tracking_head' => self::KOPF, 'tracking_thanks' => self::DANKE]]);
    }

    protected function kaufen(): Payment
    {
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        return $this->bezahlen(Payment::query()->firstOrFail());
    }

    #[Test]
    public function ohne_consent_addon_und_ohne_freigabe_steht_nichts_auf_der_seite(): void
    {
        $this->ohneConsentAddon('block');
        $this->funnelMitCode();

        $this->asVisitor()->get('/f/kurs')->assertOk()->assertDontSee('window.kopf', false);
    }

    #[Test]
    public function mit_freigabe_in_der_config_steht_der_kopf_code_so_da(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode();

        $this->asVisitor()->get('/f/kurs')->assertOk()
            ->assertSee('<script>window.kopf = 1;</script>', false)
            ->assertSee('tracker.example/p.gif', false);
    }

    #[Test]
    public function mit_consent_addon_wird_jedes_skript_geparkt(): void
    {
        $this->mitConsentAddon(false);
        $this->funnelMitCode();

        $seite = $this->asVisitor()->get('/f/kurs')->assertOk();

        $seite->assertSee('<script type="text/plain" data-consent-service="meta_pixel">window.kopf = 1;</script>', false)
            // Ein noscript-Bild laedt ohne Skript und ohne Einwilligung. Es
            // faellt heraus, sobald geparkt wird.
            ->assertDontSee('tracker.example/p.gif', false);
    }

    #[Test]
    public function der_danke_code_kommt_einmal_nach_dem_bezahlten_kauf_mit_platzhaltern(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode();

        $zahlung = $this->kaufen();

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee('window.kauf = {v: "99.00", c: "EUR", id: "'.$zahlung->id.'"};', false);

        // Neu geladen: kein zweiter Kauf fuer die Auswertung.
        $this->asVisitor()->get('/f/kurs/danke')->assertOk()->assertDontSee('window.kauf', false);
    }

    #[Test]
    public function solange_die_zahlung_offen_ist_kommt_kein_danke_code(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode();
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()->assertDontSee('window.kauf', false);
    }

    #[Test]
    public function der_code_des_kassenschritts_kommt_mit_seinem_kauf(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode(['tracking_purchase' => '<script>window.kurs = "{order_id}";</script>']);

        $zahlung = $this->kaufen();

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()
            ->assertSee('window.kurs = "'.$zahlung->id.'";', false);
    }

    #[Test]
    public function die_vorschau_gibt_keinen_code_aus(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode();

        // Die Vorschau hat keinen Besucher und zaehlt nichts, auch nicht fuer Meta.
        $funnel = Funnel::query()->firstOrFail();
        $token = PreviewToken::mint($funnel, [
            'nodes' => [['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Start']]],
            'edges' => [],
        ]);

        $this->get('/f/kurs/_preview/entry_1?token='.$token)->assertOk()
            ->assertSee('Start')
            ->assertDontSee('window.kopf', false);
    }

    // ------------------------------------------------------------ Editor

    protected function speichern(array $settings)
    {
        $funnel = Funnel::query()->firstOrFail();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        return $this->actingAs($user)->patch('/cp/utilities/funnels/'.$funnel->id, [
            'title' => 'Kurs', 'handle' => 'kurs', 'published' => true,
            'nodes' => [['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []]],
            'edges' => [],
            'settings' => $settings,
        ]);
    }

    // ------------------------------------------------ Vorlage, Dienst je Code

    #[Test]
    public function die_mitgelieferte_vorlage_ist_ein_ganzes_dokument_mit_viewport(): void
    {
        $this->kasse();

        $seite = $this->asVisitor()->get('/f/kurs')->assertOk()->getContent();

        $this->assertStringStartsWith('<!doctype html>', ltrim($seite));
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $seite);
        $this->assertMatchesRegularExpression('#<head>.*</head>\s*<body>#s', $seite);
    }

    #[Test]
    public function mit_consent_addon_bindet_die_vorlage_dessen_skript_und_banner_ein(): void
    {
        // Sonst startet ein geparktes Skript nie: niemand ist da, der es
        // nach der Einwilligung freischaltet.
        ConsentTagProbe::register();
        $this->mitConsentAddon(false);
        $this->funnelMitCode();

        $seite = $this->asVisitor()->get('/f/kurs')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<head>.*<!--consent-head-->.*</head>#s', $seite);
        $this->assertStringContainsString('<!--consent-banner-->', $seite);
    }

    #[Test]
    public function ohne_consent_addon_steht_kein_consent_tag_in_der_seite(): void
    {
        $this->ohneConsentAddon('render');
        $this->funnelMitCode();

        $this->asVisitor()->get('/f/kurs')->assertOk()->assertDontSee('consent-head', false);
    }

    #[Test]
    public function jeder_code_parkt_unter_seinem_eigenen_dienst(): void
    {
        $this->mitConsentAddon(false);
        $this->kasse([], [], ['settings' => [
            'tracking_head' => '<script>window.statistik = 1;</script>',
            'tracking_head_service' => 'statistik',
            'meta_pixel_id' => '123456789012345',
        ]]);

        $this->asVisitor()->get('/f/kurs')->assertOk()
            ->assertSee('<script type="text/plain" data-consent-service="statistik">window.statistik = 1;</script>', false)
            // Der Pixel ohne eigenen Dienst unter dem Vorgabedienst.
            ->assertSee('data-consent-service="meta_pixel">!function(f,b,e,v,n,t,s)', false);
    }

    // ---------------------------------------------------------- Berechtigung

    protected function redakteur(bool $darfTracking)
    {
        $role = Role::make('funnels-redaktion')->addPermission('access cp')->addPermission('access funnels utility');

        if ($darfTracking) {
            $role->addPermission('edit funnels tracking code');
        }

        $role->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function speichernAls($user, array $settings, array $knoten = [])
    {
        $funnel = Funnel::query()->firstOrFail();

        return $this->actingAs($user)->patch('/cp/utilities/funnels/'.$funnel->id, [
            'title' => 'Kurs', 'handle' => 'kurs', 'published' => true,
            'nodes' => $knoten ?: [['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []]],
            'edges' => [],
            'settings' => $settings,
        ]);
    }

    #[Test]
    public function ohne_das_recht_kein_tracking_code(): void
    {
        $this->kasse();

        $this->speichernAls($this->redakteur(false), ['tracking_head' => self::KOPF])
            ->assertSessionHasErrors(['settings.tracking_head' => __('statamic-funnels::messages.tracking_forbidden')]);

        $this->assertNull(Funnel::query()->firstOrFail()->meta['settings']['tracking_head'] ?? null);
    }

    #[Test]
    public function ohne_das_recht_bleibt_vorhandener_code_unangetastet_und_der_rest_speichert(): void
    {
        $this->kasse([], [], ['settings' => ['tracking_head' => self::KOPF]]);

        $this->speichernAls($this->redakteur(false), ['tracking_head' => self::KOPF, 'in_app_text' => 'Neu.'])
            ->assertSessionHasNoErrors();

        $settings = Funnel::query()->firstOrFail()->meta['settings'];
        $this->assertSame(self::KOPF, $settings['tracking_head']);
        $this->assertSame('Neu.', $settings['in_app_text']);
    }

    #[Test]
    public function ohne_das_recht_kein_kauf_code_am_schritt(): void
    {
        $this->kasse();

        $this->speichernAls($this->redakteur(false), [], [
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []],
            ['node_key' => 'kasse', 'type' => 'offer', 'label' => 'Kasse', 'config' => ['offer' => 'kurs', 'tracking_purchase' => '<script>x()</script>']],
        ])->assertSessionHasErrors('nodes');
    }

    #[Test]
    public function mit_dem_recht_geht_es(): void
    {
        $this->kasse();

        $this->speichernAls($this->redakteur(true), ['tracking_head' => self::KOPF])->assertSessionHasNoErrors();

        $this->assertSame(self::KOPF, Funnel::query()->firstOrFail()->meta['settings']['tracking_head']);
    }

    #[Test]
    public function der_editor_weiss_ob_tracking_bearbeitet_werden_darf(): void
    {
        $funnel = $this->kasse();

        $response = $this->actingAs($this->redakteur(false))->get('/cp/utilities/funnels/'.$funnel->id.'/edit')->assertOk();
        preg_match('/data-page="(.*?)"/s', $response->getContent(), $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $this->assertFalse($props['tracking']['can_edit']);
    }

    #[Test]
    public function der_editor_speichert_die_einstellungen(): void
    {
        $this->kasse();

        $this->speichern([
            'in_app_enabled' => false,
            'in_app_text' => 'Bitte im Browser öffnen.',
            'embed_domains' => "chor-beispiel.de\nhttps://www.chor-beispiel.de",
            'tracking_head' => self::KOPF,
            'tracking_thanks' => self::DANKE,
            'meta_pixel_id' => '123456789012345',
        ])->assertSessionHasNoErrors();

        $settings = Funnel::query()->firstOrFail()->meta['settings'];

        $this->assertFalse($settings['in_app_enabled']);
        $this->assertSame(['https://chor-beispiel.de', 'https://www.chor-beispiel.de'], $settings['embed_domains']);
        $this->assertSame(self::KOPF, $settings['tracking_head']);
        $this->assertSame('123456789012345', $settings['meta_pixel_id']);
    }

    #[Test]
    public function eine_domain_die_keine_ist_wird_abgelehnt(): void
    {
        $this->kasse();

        $this->speichern(['embed_domains' => 'chor-beispiel.de, java script:alert(1)'])
            ->assertSessionHasErrors('settings.embed_domains');
    }

    #[Test]
    public function eine_pixel_id_mit_buchstaben_wird_abgelehnt(): void
    {
        $this->kasse();

        $this->speichern(['meta_pixel_id' => 'abc123'])->assertSessionHasErrors('settings.meta_pixel_id');
    }

    #[Test]
    public function speichern_ohne_einstellungen_laesst_sie_stehen(): void
    {
        $this->kasse([], [], ['settings' => ['tracking_head' => self::KOPF], 'split_winners' => ['x' => ['variant' => 'a']]]);

        $funnel = Funnel::query()->firstOrFail();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $this->actingAs($user)->patch('/cp/utilities/funnels/'.$funnel->id, [
            'title' => 'Kurs', 'handle' => 'kurs', 'published' => true,
            'nodes' => [['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []]],
            'edges' => [],
        ])->assertSessionHasNoErrors();

        $meta = $funnel->fresh()->meta;
        $this->assertSame(self::KOPF, $meta['settings']['tracking_head']);
        $this->assertSame('a', $meta['split_winners']['x']['variant']);
    }
}
