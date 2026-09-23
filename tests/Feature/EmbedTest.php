<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Embed;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * F4: Checkout und Popup auf fremden Seiten.
 *
 * Drei Dinge muessen stimmen, damit das eine Kasse ist und kein Loch:
 *
 * 1. **Wer rahmen darf, sagt der Funnel.** Jede Seite schickt
 *    `frame-ancestors` mit genau den erlaubten Domains, sonst nur `'self'`.
 * 2. **Der Weg reist ohne Cookie.** Im Rahmen einer fremden Seite haelt der
 *    Browser die Cookies dieser Site zurueck; der Besuch steht deshalb
 *    signiert in Links und Formularen, und ein Formular von woanders kommt
 *    nicht durch (Origin-Pruefung statt CSRF-Token).
 * 3. **Bezahlt wird oben.** Stripe und Mollie lassen sich nicht rahmen; der
 *    Bestellknopf verlaesst den Rahmen, und der Rueckweg vom Anbieter findet
 *    den Besuch ueber den signierten Link wieder.
 */
class EmbedTest extends TestCase
{
    use WalksAFunnel;

    #[Test]
    public function jede_seite_verbietet_fremde_rahmen_ohne_erlaubte_domains(): void
    {
        $this->kasse();

        $this->asVisitor()->get('/f/kurs')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    #[Test]
    public function erlaubte_domains_stehen_im_header(): void
    {
        $this->kasse([], [], ['settings' => ['embed_domains' => ['chor-beispiel.de', 'https://www.chor-beispiel.de/seite']]]);

        $this->asVisitor()->get('/f/kurs?embed=1')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://chor-beispiel.de https://www.chor-beispiel.de");
    }

    #[Test]
    public function eingebettet_tragen_die_formulare_den_signierten_weg(): void
    {
        $this->kasse();

        $seite = $this->get('/f/kurs?embed=1')->assertOk();
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();

        $seite->assertSee('data-funnel-embedded', false)
            ->assertSee('/f/kurs/entry_1/advance-embed?_walk=', false)
            ->assertSee('funnels.js', false)
            ->assertHeader('Referrer-Policy', 'same-origin');

        preg_match('/advance-embed\?_walk=([^"&]+)/', $seite->getContent(), $m);
        $this->assertSame($visit->token, Embed::tokenFrom(urldecode(html_entity_decode($m[1]))));
    }

    #[Test]
    public function ohne_cookie_geht_der_weg_ueber_den_signierten_link_weiter(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1');
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();
        $w = Embed::walkLink($visit->token);

        $antwort = $this->withHeaders(['Origin' => 'http://localhost'])
            ->post('/f/kurs/entry_1/advance-embed?_walk='.urlencode($w));

        $antwort->assertRedirect();
        $ziel = $antwort->headers->get('Location');

        $this->assertStringContainsString('/f/kurs/anmeldung', $ziel);
        $this->assertStringContainsString('embed=1', $ziel);
        $this->assertStringContainsString('w=', $ziel);

        // Derselbe Besuch, kein zweiter.
        $this->assertSame(1, FunnelVisit::count());
        $this->assertTrue($visit->fresh()->hasReached('entry_1'));

        $this->get($ziel)->assertOk()->assertSee('/f/kurs/capture_1/advance-embed?_walk=', false);
        $this->assertTrue($visit->fresh()->hasReached('capture_1'));
    }

    #[Test]
    public function ein_formular_von_einer_fremden_seite_kommt_nicht_durch(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1');
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();

        $this->withHeaders(['Origin' => 'https://boese.example'])
            ->post('/f/kurs/entry_1/advance-embed?_walk='.urlencode(Embed::walkLink($visit->token)))
            ->assertForbidden();

        $this->assertFalse($visit->fresh()->events()->where('event', 'submitted')->exists());
    }

    #[Test]
    public function ein_gefaelschter_weg_kommt_nicht_durch(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1');
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();

        $this->withHeaders(['Origin' => 'http://localhost'])
            ->post('/f/kurs/entry_1/advance-embed?_walk='.$visit->token.'.9999999999.falsch')
            ->assertForbidden();
    }

    #[Test]
    public function ein_abgelaufener_weg_gilt_nicht(): void
    {
        $this->assertNull(Embed::tokenFrom(Embed::walkLink(str_repeat('a', 32), now()->subMinute()->getTimestamp())));
        $this->assertSame(str_repeat('a', 32), Embed::tokenFrom(Embed::walkLink(str_repeat('a', 32))));
    }

    #[Test]
    public function die_eingebettete_route_braucht_kein_csrf_token_die_normale_schon(): void
    {
        $eingebettet = Route::getRoutes()->getByName('statamic-funnels.advance-embed');
        $normal = Route::getRoutes()->getByName('statamic-funnels.advance');

        $this->assertContains(ValidateCsrfToken::class, $eingebettet->excludedMiddleware());
        $this->assertNotContains(ValidateCsrfToken::class, $normal->excludedMiddleware());
    }

    #[Test]
    public function der_bestellknopf_verlaesst_den_rahmen_und_der_rueckweg_traegt_den_weg(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1');
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();
        $w = urlencode(Embed::walkLink($visit->token));

        $this->withHeaders(['Origin' => 'http://localhost'])->post('/f/kurs/entry_1/advance-embed?_walk='.$w);
        $this->get('/f/kurs/anmeldung?embed=1&w='.$w);
        $this->withHeaders(['Origin' => 'http://localhost'])->post('/f/kurs/capture_1/advance-embed?_walk='.$w, ['email' => 'k@example.com']);

        $this->get('/f/kurs/kasse?embed=1&w='.$w)
            ->assertOk()
            ->assertSee('class="funnel-offer__accept" target="_top"', false);

        $this->withHeaders(['Origin' => 'http://localhost'])
            ->post('/f/kurs/kasse/advance-embed?_walk='.$w, ['accept' => '1', 'confirmed' => '1'])
            ->assertRedirect();

        $rueckweg = $this->gateway->lastPayload['redirectUrl'] ?? $this->gateway->lastPayload['redirect_url'] ?? null;
        $this->assertIsString($rueckweg, 'kein Rueckweg an den Anbieter uebergeben: '.json_encode(array_keys($this->gateway->lastPayload)));
        $this->assertStringContainsString('w=', $rueckweg);

        // Zurueck vom Anbieter, oben, ohne Cookie: der Weg ist wieder da.
        $zahlung = $this->bezahlen(Payment::query()->firstOrFail());
        $pfad = parse_url($rueckweg, PHP_URL_PATH).'?'.parse_url($rueckweg, PHP_URL_QUERY);

        $this->get($pfad)
            ->assertOk()
            ->assertSee(__('statamic-funnels::messages.order_summary'))
            ->assertCookie(FunnelWalk::COOKIE, $visit->token, false);
    }

    #[Test]
    public function ein_fehler_im_rahmen_steht_auf_der_naechsten_seite(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1');
        $visit = FunnelVisit::query()->latest('id')->firstOrFail();
        $w = urlencode(Embed::walkLink($visit->token));

        $this->withHeaders(['Origin' => 'http://localhost'])->post('/f/kurs/entry_1/advance-embed?_walk='.$w);
        $this->get('/f/kurs/anmeldung?embed=1&w='.$w);

        $antwort = $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/f/kurs/anmeldung?embed=1&w='.$w])
            ->post('/f/kurs/capture_1/advance-embed?_walk='.$w, ['email' => 'keine-adresse']);

        $ziel = $antwort->headers->get('Location');
        $this->assertStringContainsString('e=', $ziel);

        // Ohne Sitzung: der Fehler kommt aus dem signierten Parameter.
        $this->flushSession();
        $this->get($ziel)->assertOk()->assertSee('class="funnel-errors"', false);
    }

    #[Test]
    public function ein_gefaelschter_fehlertext_wird_nicht_gezeigt(): void
    {
        $this->kasse();

        $this->get('/f/kurs?embed=1&e='.urlencode(base64_encode(json_encode(['Ruf uns an unter 0900'])).'.falsch'))
            ->assertOk()
            ->assertDontSee('Ruf uns an');
    }

    #[Test]
    public function der_editor_bekommt_die_schnipsel(): void
    {
        $funnel = $this->kasse();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $response = $this->actingAs($user)->get('/cp/utilities/funnels/'.$funnel->id.'/edit')->assertOk();
        preg_match('/data-page="(.*?)"/s', $response->getContent(), $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $this->assertStringEndsWith('/vendor/statamic-funnels/embed.js', $props['embed']['script']);
        $this->assertStringEndsWith('/f/kurs', $props['embed']['url']);
    }

    #[Test]
    public function das_einbettskript_liegt_bei_den_oeffentlichen_dateien(): void
    {
        $datei = __DIR__.'/../../resources/dist-public/embed.js';

        $this->assertFileExists($datei);
        $this->assertStringContainsString('data-funnel-popup', file_get_contents($datei));
        $this->assertStringContainsString('data-funnel-embed', file_get_contents($datei));
    }
}
