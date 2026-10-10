<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\LeadMagnets\Facades\LeadMagnets;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\StatamicFunnels\Integrations\LeadMagnetsBridge;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Tests\Support\LeadMagnetsSchema;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Der Lead-Magnet-Schritt, gegen einen Stand-in von lead-magnets.
 *
 * Jeder Test in einem eigenen PHP-Prozess: der Stand-in definiert Klassen unter
 * den echten Namen des Geschwisters, und die uebrige Suite muss eine Site ohne
 * lead-magnets bleiben (`LeadMagnetsAbsentTest`). Ein Stand-in, der in den
 * gemeinsamen Prozess leckte, machte genau den Fall unsichtbar, fuer den die
 * Kopplung optional ist.
 *
 * Was hier gilt:
 *
 *  - mit Double-Opt-in wartet der Schritt, und erst der Klick aus der Mail
 *    bringt den Besucher zum naechsten Schritt;
 *  - ohne Double-Opt-in geht es sofort weiter;
 *  - „angefordert" und „bestaetigt" sind zwei Zahlen;
 *  - der Rueckweg ist signiert und fuehrt nur in den eigenen Funnel.
 */
#[RunTestsInSeparateProcesses]
class LeadMagnetStepTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        // On a machine that has the real sibling the stand-in would not load
        // (its classes are guarded) and these tests would run against
        // something they were not written for. `LeadMagnetsRealPackageTest`
        // covers that machine.
        if (class_exists('Goldnead\LeadMagnets\Support\ReturnUrl')
            && ! str_contains((string) (new \ReflectionClass('Goldnead\LeadMagnets\Support\ReturnUrl'))->getFileName(), '/tests/Fakes/')) {
            $this->markTestSkipped('The real lead-magnets is installed; see LeadMagnetsRealPackageTest.');
        }

        require_once __DIR__.'/../Fakes/lead-magnets.php';

        parent::setUp();

        LeadMagnetsSchema::create();
        LeadMagnets::reset();

        // Nicht die Statamic-Site: `app.url` bestimmt den Host der signierten Links.
        config()->set('app.url', 'http://localhost');
    }

    protected function tearDown(): void
    {
        // Skipped before the application was built (see setUp).
        if ($this->app === null) {
            return;
        }

        parent::tearDown();
    }

    protected function resource(array $attributes = []): Resource
    {
        return Resource::query()->create(array_merge([
            'handle' => 'einsing',
            'title' => 'Einsing-Freebie',
            'published' => true,
            'requires_confirmation' => true,
        ], $attributes));
    }

    /** Einstieg → Anmeldung → Geschenk → Danke → Ende. */
    protected function funnelWithGift(array $step = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null, 'config' => ['headline' => 'Start']],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Geschenk', 'slug' => 'geschenk', 'config' => array_merge(['resource' => 'einsing', 'headline' => 'Dein Geschenk'], $step)],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'slug' => 'danke', 'config' => ['headline' => 'Danke und Angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Ende', 'slug' => 'ende', 'config' => ['headline' => 'Ende']],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'lm_1', 'from_output' => 'default'],
            ['from_node_key' => 'lm_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
            ['from_node_key' => 'page_1', 'to_node_key' => 'finish_1', 'from_output' => 'default'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** Bis zur Anmeldung, abgeschickt; landet auf dem Geschenk-Schritt. */
    protected function submitForm(string $email = 'maria@example.com', array $extra = [], ?string $token = null)
    {
        $this->asVisitor($token)->get('/f/kurs');
        $this->asVisitor($token)->post('/f/kurs/entry_1/advance');
        $this->asVisitor($token)->get('/f/kurs/anmeldung');

        return $this->asVisitor($token)->post('/f/kurs/capture_1/advance', array_merge(['email' => $email], $extra));
    }

    /** Ein anderer Browser: kein Cookie. */
    protected function otherBrowser(): static
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    protected function visit(?string $token = null): FunnelVisit
    {
        return FunnelVisit::query()->where('token', $token ?? $this->token)->sole();
    }

    protected function events(FunnelVisit $visit, string $nodeKey = 'lm_1'): array
    {
        return $visit->events()->where('node_key', $nodeKey)->pluck('event')->all();
    }

    // ------------------------------------------------------------ Registrierung

    #[Test]
    public function the_step_type_exists_when_lead_magnets_is_installed(): void
    {
        $this->resource();
        $this->resource(['handle' => 'unveroeffentlicht', 'title' => 'Entwurf', 'published' => false]);

        $registry = new StepRegistry;

        $this->assertTrue($registry->has('lead_magnet'));

        $entry = collect($registry->library()['pages'])->firstWhere('handle', 'lead_magnet');
        $this->assertNotNull($entry);

        // Im CP wählbar, und nur, was veröffentlicht ist.
        $resourceField = collect($entry['schema'])->firstWhere('handle', 'resource');
        $this->assertSame([['value' => 'einsing', 'label' => 'Einsing-Freebie']], $resourceField['options']);

        // Der Schritt hat einen Ausgang: es geht nur weiter oder gar nicht.
        $this->assertSame([['handle' => 'default']], $entry['outputs']['clauses'][0]['outputs']);
    }

    #[Test]
    public function the_step_type_can_be_switched_off(): void
    {
        config()->set('statamic-funnels.integrations.lead_magnets', false);

        $this->assertFalse((new StepRegistry)->has('lead_magnet'));
    }

    // ----------------------------------------------------------- Mit Double-Opt-in

    #[Test]
    public function with_double_opt_in_the_step_waits_and_the_click_continues_the_funnel(): void
    {
        $this->resource();
        $this->funnelWithGift();

        // Das Formular schickt zum Geschenk-Schritt; die Seite fragt lead-magnets an.
        $this->submitForm()->assertRedirect('/f/kurs/geschenk');
        $this->asVisitor()->get('/f/kurs/geschenk')
            ->assertOk()
            ->assertSee('Dein Geschenk')
            ->assertSee('maria@example.com')
            ->assertSee('data-status="waiting"', false);

        $grant = Grant::query()->sole();
        $this->assertSame('pending', $grant->status);
        $this->assertSame('maria@example.com', $grant->email);

        // Der Besucher steht noch auf dem Geschenk, nicht dahinter.
        $this->assertSame('lm_1', $this->visit()->current_node_key);
        $this->assertSame([FunnelStepEvent::ENTERED, FunnelStepEvent::LEAD_MAGNET_REQUESTED], $this->events($this->visit()));

        // Der Rueckweg, den lead-magnets bekommen hat: signiert, auf dieser Site,
        // und er nennt den Besuch, nicht eine Adresse aus der Anfrage.
        $returnUrl = $grant->meta['return_url'];
        $this->assertStringStartsWith('http://localhost/f/kurs/_resume/lm_1/', $returnUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($returnUrl)));

        // Der Klick in der Mail, in einem Browser ohne Cookie.
        $this->assertSame($returnUrl, LeadMagnets::confirm($grant));

        $this->otherBrowser()->get($returnUrl)
            ->assertRedirect('/f/kurs/danke')
            // Der Browser uebernimmt den Besuch.
            ->assertCookie(FunnelWalk::COOKIE, $this->token, false);

        $this->assertSame(
            [FunnelStepEvent::ENTERED, FunnelStepEvent::LEAD_MAGNET_REQUESTED, FunnelStepEvent::LEAD_MAGNET_CONFIRMED],
            $this->events($this->visit()),
        );

        // Und dort geht es weiter: die Danke-Seite mit dem Angebot.
        $this->asVisitor()->get('/f/kurs/danke')->assertOk()->assertSee('Danke und Angebot');
        $this->assertSame('page_1', $this->visit()->current_node_key);
    }

    #[Test]
    public function the_click_in_the_browser_that_filled_the_form_needs_no_new_cookie(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();

        $grant = Grant::query()->sole();
        LeadMagnets::confirm($grant);

        $this->asVisitor()->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');
    }

    #[Test]
    public function a_forwarded_link_does_not_replace_the_walk_of_the_browser_that_opens_it(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk');
        $grant = Grant::query()->sole();
        LeadMagnets::confirm($grant);

        // Jemand mit einem anderen, eigenen Besuch oeffnet den weitergeleiteten Link.
        $fremder = str_repeat('z', 32);
        $this->otherBrowser()->asVisitor($fremder)->get('/f/kurs')->assertOk();

        $response = $this->asVisitor($fremder)->get($grant->meta['return_url']);
        $response->assertRedirect('/f/kurs/danke');

        $this->assertNull($response->getCookie(FunnelWalk::COOKIE, false), 'das Cookie des Fremden bleibt stehen');
    }

    #[Test]
    public function a_reload_while_waiting_does_not_ask_again(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();

        $this->assertCount(1, LeadMagnets::$requests);
        $this->assertSame(1, $this->visit()->events()->where('event', FunnelStepEvent::LEAD_MAGNET_REQUESTED)->count());
    }

    #[Test]
    public function a_visitor_who_confirmed_elsewhere_and_comes_back_by_hand_is_not_stranded(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();

        LeadMagnets::confirm(Grant::query()->sole());

        // Der Rueckweg wurde nie geoeffnet; die Seite fragt lead-magnets selbst.
        $this->asVisitor()->get('/f/kurs/geschenk')->assertRedirect('/f/kurs/danke');
        $this->assertContains(FunnelStepEvent::LEAD_MAGNET_CONFIRMED, $this->events($this->visit()));
    }

    #[Test]
    public function the_click_is_counted_once_however_often_the_link_is_opened(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk');
        $grant = Grant::query()->sole();
        LeadMagnets::confirm($grant);

        $this->asVisitor()->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');
        $this->asVisitor()->get($grant->meta['return_url'])->assertRedirect('/f/kurs/danke');
        $this->asVisitor()->get('/f/kurs/geschenk')->assertRedirect('/f/kurs/danke');

        $this->assertSame(1, $this->visit()->events()->where('event', FunnelStepEvent::LEAD_MAGNET_CONFIRMED)->count());
    }

    #[Test]
    public function a_different_address_after_going_back_asks_again_for_the_new_one(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm('erste@example.com');
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk();

        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'zweite@example.com']);
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk()->assertSee('zweite@example.com');

        $this->assertSame(['erste@example.com', 'zweite@example.com'], array_column(LeadMagnets::$requests, 'email'));
    }

    // ----------------------------------------------------------- Ohne Double-Opt-in

    #[Test]
    public function without_double_opt_in_the_visitor_goes_straight_on(): void
    {
        $this->resource(['requires_confirmation' => false]);
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk')->assertRedirect('/f/kurs/danke');

        $this->assertSame('active', Grant::query()->sole()->status);

        // Nichts zu bestaetigen, also auch kein Rueckweg.
        $this->assertArrayNotHasKey('return_url', LeadMagnets::$requests[0]['meta']);

        $this->assertSame(
            [FunnelStepEvent::ENTERED, FunnelStepEvent::LEAD_MAGNET_REQUESTED, FunnelStepEvent::LEAD_MAGNET_CONFIRMED],
            $this->events($this->visit()),
        );

        $this->asVisitor()->get('/f/kurs/danke')->assertOk()->assertSee('Danke und Angebot');
    }

    // ------------------------------------------------------------------ Newsletter

    #[Test]
    public function newsletter_consent_is_passed_on_only_when_the_box_was_ticked(): void
    {
        $this->resource();
        $this->funnelWithGift();

        $this->submitForm('mit@example.com', ['newsletter' => '1'], str_repeat('a', 32));
        $this->asVisitor(str_repeat('a', 32))->get('/f/kurs/geschenk');

        $this->submitForm('ohne@example.com', [], str_repeat('b', 32));
        $this->asVisitor(str_repeat('b', 32))->get('/f/kurs/geschenk');

        [$mit, $ohne] = LeadMagnets::$requests;

        $this->assertTrue($mit['meta']['newsletter']['opted_in']);
        $this->assertNotEmpty($mit['meta']['newsletter']['text'], 'der Wortlaut neben dem Haken reist mit');

        // Eine Adresse im Formular ist keine Einwilligung.
        $this->assertArrayNotHasKey('newsletter', $ohne['meta']);
    }

    // ------------------------------------------------------------ Fehlende Adresse

    #[Test]
    public function without_an_address_nothing_is_asked_and_the_walk_does_not_move(): void
    {
        $this->resource();
        $this->funnelWithGift();

        // Direkt auf den Schritt, ohne Formular davor.
        $this->asVisitor()->get('/f/kurs/geschenk')
            ->assertOk()
            ->assertSee('data-status="no_email"', false);

        $this->assertSame([], LeadMagnets::$requests);
        $this->assertSame([FunnelStepEvent::ENTERED], $this->events($this->visit()));
    }

    #[Test]
    public function a_missing_or_unpublished_resource_stops_the_walk_visibly(): void
    {
        $this->resource(['published' => false]);
        $this->funnelWithGift();

        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk')
            ->assertOk()
            ->assertSee('data-status="unavailable"', false);

        $this->assertSame([], LeadMagnets::$requests);
    }

    #[Test]
    public function a_failing_sibling_shows_a_failure_and_the_next_load_tries_again(): void
    {
        $this->resource();
        $this->funnelWithGift();
        $this->submitForm();

        LeadMagnets::$failWith = new RuntimeException('mailer down');
        $this->withoutExceptionHandling();

        $this->asVisitor()->get('/f/kurs/geschenk')
            ->assertOk()
            ->assertSee('data-status="failed"', false);

        // Nichts als angefordert gezaehlt, was nie rausging.
        $this->assertNotContains(FunnelStepEvent::LEAD_MAGNET_REQUESTED, $this->events($this->visit()));

        LeadMagnets::$failWith = null;
        $this->asVisitor()->get('/f/kurs/geschenk')->assertOk()->assertSee('data-status="waiting"', false);
    }

    // --------------------------------------------------------------------- Messung

    #[Test]
    public function requested_and_confirmed_are_counted_apart(): void
    {
        $this->resource();
        $funnel = $this->funnelWithGift();

        // Drei fordern an, einer klickt.
        foreach (['a', 'b', 'c'] as $zeichen) {
            $token = str_repeat($zeichen, 32);
            $this->submitForm($zeichen.'@example.com', [], $token);
            $this->asVisitor($token)->get('/f/kurs/geschenk');
        }

        $klick = Grant::query()->where('email', 'b@example.com')->sole();
        LeadMagnets::confirm($klick);
        $this->otherBrowser()->get($klick->meta['return_url'])->assertRedirect('/f/kurs/danke');
        $this->asVisitor(str_repeat('b', 32))->get('/f/kurs/danke')->assertOk();

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(3, $stats['lm_1']['visits']);
        $this->assertSame(3, $stats['lm_1']['requested']);
        $this->assertSame(1, $stats['lm_1']['confirmed']);
        $this->assertSame(1, $stats['lm_1']['continued']);

        // Andere Karten behalten ihre Form.
        $this->assertArrayNotHasKey('requested', $stats['page_1']);
        $this->assertArrayNotHasKey('confirmed', $stats['capture_1']);
    }

    #[Test]
    public function the_insights_breakdown_has_words_for_both_events(): void
    {
        foreach (['metric_event_lead_magnet_requested', 'metric_event_lead_magnet_confirmed'] as $key) {
            $this->assertNotSame('statamic-funnels::messages.'.$key, __('statamic-funnels::messages.'.$key));
        }
    }

    // --------------------------------------------------------------- Redirect-Sicherheit

    #[Test]
    public function the_resume_link_needs_its_signature(): void
    {
        $this->resource();
        $this->funnelWithGift();
        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk');
        $grant = Grant::query()->sole();
        LeadMagnets::confirm($grant);

        $visit = $this->visit();
        $signed = $grant->meta['return_url'];

        // Ohne Signatur.
        $this->otherBrowser()->get('/f/kurs/_resume/lm_1/'.$visit->id)->assertForbidden();

        // Mit veraenderter Signatur.
        $this->get(preg_replace('/signature=\w+/', 'signature=abc', $signed))->assertForbidden();

        // Mit einem anderen Besuch unter derselben Signatur.
        $this->get(str_replace('/'.$visit->id.'?', '/'.($visit->id + 1).'?', $signed))->assertForbidden();

        // Keine der drei Anfragen hat den Besuch bewegt, obwohl der Zugang steht.
        $this->assertNotContains(FunnelStepEvent::LEAD_MAGNET_CONFIRMED, $this->events($visit));
    }

    #[Test]
    public function the_resume_link_cannot_be_pointed_anywhere_else(): void
    {
        $this->resource();
        $this->funnelWithGift();
        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk');
        $grant = Grant::query()->sole();
        LeadMagnets::confirm($grant);

        $signed = $grant->meta['return_url'];

        // Ein Ziel in der Adresse aendert die Signatur: abgelehnt.
        $this->otherBrowser()->get($signed.'&redirect='.urlencode('https://evil.example/'))->assertForbidden();
        $this->get($signed.'&next=/f/kurs/ende')->assertForbidden();

        // Und die echte Adresse fuehrt, was auch in der Anfrage steht, nur in
        // diesen Funnel.
        $response = $this->get($signed);
        $response->assertRedirect('/f/kurs/danke');
        $this->assertStringStartsWith('http://localhost/f/kurs/', $response->headers->get('Location'));
    }

    #[Test]
    public function the_click_alone_does_not_move_the_walk_if_the_grant_is_not_confirmed(): void
    {
        $this->resource();
        $this->funnelWithGift();
        $this->submitForm();
        $this->asVisitor()->get('/f/kurs/geschenk');
        $grant = Grant::query()->sole();

        // Das Rueckweg-Link ist signiert und echt, aber lead-magnets hat nicht
        // bestaetigt (ein Link, der vorher irgendwoher kam).
        $this->otherBrowser()->get($grant->meta['return_url'])->assertRedirect('/f/kurs/geschenk');

        $this->assertNotContains(FunnelStepEvent::LEAD_MAGNET_CONFIRMED, $this->events($this->visit()));
        $this->assertSame('lm_1', $this->visit()->current_node_key);
    }

    #[Test]
    public function the_resume_route_only_serves_lead_magnet_steps_of_published_funnels(): void
    {
        $this->resource();
        $funnel = $this->funnelWithGift();
        $this->submitForm();
        $visit = $this->visit();

        // Ein anderer Schritt-Typ unter derselben Route.
        $other = URL::signedRoute('statamic-funnels.lead-magnet.resume', ['funnel' => 'kurs', 'nodeKey' => 'page_1', 'visit' => $visit->id]);
        $this->get($other)->assertNotFound();

        // Ein Besuch eines anderen Funnels.
        $zweiter = Funnel::create(['handle' => 'zweiter', 'title' => 'Zwei', 'published' => true]);
        $zweiter->steps()->create(['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'G', 'slug' => 'g', 'config' => ['resource' => 'einsing']]);
        $fremd = URL::signedRoute('statamic-funnels.lead-magnet.resume', ['funnel' => 'zweiter', 'nodeKey' => 'lm_1', 'visit' => $visit->id]);
        $this->get($fremd)->assertNotFound();

        // Ein Funnel im Entwurf.
        $funnel->update(['published' => false]);
        $draft = URL::signedRoute('statamic-funnels.lead-magnet.resume', ['funnel' => 'kurs', 'nodeKey' => 'lm_1', 'visit' => $visit->id]);
        $this->get($draft)->assertNotFound();
    }

    #[Test]
    public function the_bridge_probes_by_name_and_reports_available(): void
    {
        $this->assertTrue(LeadMagnetsBridge::available());
    }
}
