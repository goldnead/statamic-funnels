<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Events\ResourceRequested;
use Goldnead\LeadMagnets\Facades\LeadMagnets;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\StatamicAutomations\Events\AutomationRunFinished;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Tests\Support\LeadMagnetsSchema;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The journey that starts on a page of the site and ends in a mail course.
 *
 * opt-in page (`external`, `path`) -> confirmation (`lead_magnet`) -> course
 * (`external`, `automation`) -> end (`finish`). Nobody opens the last three in
 * a browser; lead-magnets and automations say what happened.
 *
 * In their own processes: the stand-ins define classes under the siblings' real
 * names, and the rest of the suite must stay a site without them.
 */
#[RunTestsInSeparateProcesses]
class ExternalStepsTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        if (class_exists('Goldnead\LeadMagnets\Support\ReturnUrl')
            && ! str_contains((string) (new \ReflectionClass('Goldnead\LeadMagnets\Support\ReturnUrl'))->getFileName(), '/tests/Fakes/')) {
            $this->markTestSkipped('The real lead-magnets is installed; this suite runs against the stand-in.');
        }

        require_once __DIR__.'/../Fakes/lead-magnets.php';
        require_once __DIR__.'/../Fakes/automations.php';

        parent::setUp();

        LeadMagnetsSchema::create();
        LeadMagnets::reset();

        config()->set('app.url', 'http://localhost');
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            return;
        }

        parent::tearDown();
    }

    protected function journey(bool $published = true): Funnel
    {
        $funnel = Funnel::create(['handle' => 'reise', 'title' => 'Die Reise', 'published' => $published]);

        $funnel->steps()->createMany([
            ['node_key' => 'optin', 'type' => 'external', 'label' => 'Anmeldeseite', 'config' => ['path' => '/anmeldung']],
            ['node_key' => 'bestaetigung', 'type' => 'lead_magnet', 'label' => 'Bestätigung', 'config' => ['resource' => 'geschenk']],
            ['node_key' => 'kurs', 'type' => 'external', 'label' => 'Kurs', 'config' => ['automation' => 'mail-kurs']],
            ['node_key' => 'uebergang', 'type' => 'finish', 'label' => 'Übergang', 'slug' => 'uebergang'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'optin', 'to_node_key' => 'bestaetigung', 'from_output' => 'default'],
            ['from_node_key' => 'bestaetigung', 'to_node_key' => 'kurs', 'from_output' => 'default'],
            ['from_node_key' => 'kurs', 'to_node_key' => 'uebergang', 'from_output' => 'default'],
        ]);

        return $funnel;
    }

    protected function resource(array $attributes = []): Resource
    {
        return Resource::query()->create(array_merge(['handle' => 'geschenk', 'title' => 'Geschenk', 'published' => true, 'requires_confirmation' => true], $attributes));
    }

    protected function grant(Resource $resource, string $email = 'Maria@Example.com', array $meta = []): Grant
    {
        $grant = Grant::query()->create(['resource_id' => $resource->id, 'email' => mb_strtolower($email), 'status' => 'pending', 'meta' => $meta ?: null]);
        $grant->setRelation('resource', $resource);

        return $grant;
    }

    /** Somebody opened the page of the site: the walk exists and stands on the first step. */
    protected function onThePage(string $token): FunnelVisit
    {
        $visit = FunnelVisit::create(['funnel_id' => Funnel::where('handle', 'reise')->value('id'), 'token' => $token]);
        $visit->record('optin', FunnelStepEvent::ENTERED);

        return $visit;
    }

    protected function eventsOf(string $node, string $event): int
    {
        return FunnelStepEvent::query()->where('node_key', $node)->where('event', $event)->count();
    }

    #[Test]
    public function the_request_of_the_form_is_recorded_once_on_the_walk_of_the_browser_that_sent_it(): void
    {
        $this->journey();
        $visit = $this->onThePage($this->token);
        $grant = $this->grant($this->resource());

        $this->asVisitor();
        // The request of the form carries the cookie; the events fire inside it.
        $request = Request::create('/api/resources/geschenk/optin', 'POST', [], [FunnelWalk::COOKIE => $this->token]);
        $this->app->instance('request', $request);

        Event::dispatch(new ResourceRequested($grant));
        // "Asking again" is a real signal for lead-magnets and not a second row here.
        Event::dispatch(new ResourceRequested($grant));

        $this->assertSame(1, $this->eventsOf('bestaetigung', FunnelStepEvent::ENTERED));
        $this->assertSame(1, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_REQUESTED));
        $this->assertSame(0, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_CONFIRMED));
        $this->assertSame('maria@example.com', strtolower($visit->fresh()->email));
        $this->assertSame($grant->id, $visit->fresh()->meta['lead_magnet']['bestaetigung']['grant_id']);
    }

    #[Test]
    public function the_click_on_the_link_in_the_mail_is_recorded_once_and_the_walk_carries_on_to_the_course(): void
    {
        $this->journey();
        $visit = $this->onThePage($this->token);
        $grant = $this->grant($this->resource());

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($grant));

        // Days later, in another browser: no cookie, the address finds the walk.
        $this->app->instance('request', Request::create('/bestaetigen/abc', 'GET'));
        Event::dispatch(new ResourceConfirmed($grant));
        Event::dispatch(new ResourceConfirmed($grant));

        $this->assertSame(1, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_CONFIRMED));
        // The course is under way: its step is entered by the hand-over, once.
        $this->assertSame(1, $this->eventsOf('kurs', FunnelStepEvent::ENTERED));
        $this->assertSame(0, $this->eventsOf('uebergang', FunnelStepEvent::ENTERED));
        $this->assertNull($visit->fresh()->completed_at);
    }

    #[Test]
    public function a_resource_without_double_opt_in_requests_and_confirms_in_the_same_breath(): void
    {
        $this->journey();
        $this->onThePage($this->token);
        $grant = $this->grant($this->resource(['requires_confirmation' => false]));

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($grant));
        Event::dispatch(new ResourceConfirmed($grant));

        $this->assertSame(1, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_REQUESTED));
        $this->assertSame(1, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_CONFIRMED));
        $this->assertSame(1, $this->eventsOf('kurs', FunnelStepEvent::ENTERED));
    }

    #[Test]
    public function the_end_of_the_course_is_recorded_and_ends_the_walk(): void
    {
        $this->journey();
        $visit = $this->onThePage($this->token);
        $grant = $this->grant($this->resource());

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($grant));
        Event::dispatch(new ResourceConfirmed($grant));

        // Five days later, from the queue: no request of a browser at all.
        $this->app->instance('request', Request::create('/', 'GET'));
        Event::dispatch(new AutomationRunFinished('mail-kurs', 'MARIA@example.com'));
        Event::dispatch(new AutomationRunFinished('mail-kurs', 'MARIA@example.com'));

        $this->assertSame(1, $this->eventsOf('kurs', FunnelStepEvent::AUTOMATION_FINISHED));
        $this->assertSame(1, $this->eventsOf('uebergang', FunnelStepEvent::ENTERED));
        $this->assertSame(1, $this->eventsOf('uebergang', FunnelStepEvent::COMPLETED));
        $this->assertNotNull($visit->fresh()->completed_at);
    }

    #[Test]
    public function the_numbers_of_the_map_follow_the_walk(): void
    {
        $funnel = $this->journey();

        // Three people on the page, two asked, one confirmed, none finished the course yet.
        $tokens = ['a', 'b', 'c'];

        foreach ($tokens as $letter) {
            $this->onThePage(str_repeat($letter, 32));
        }

        $this->resource();

        foreach (['a' => 'a@example.com', 'b' => 'b@example.com'] as $letter => $email) {
            $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => str_repeat($letter, 32)]));
            Event::dispatch(new ResourceRequested($this->grant(Resource::first(), $email)));
        }

        Event::dispatch(new ResourceConfirmed(Grant::where('email', 'a@example.com')->first()->setRelation('resource', Resource::first())));

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges']));

        $this->assertSame(3, $stats['optin']['visits']);
        $this->assertSame(2, $stats['optin']['continued']);
        $this->assertSame(2, $stats['bestaetigung']['requested']);
        $this->assertSame(1, $stats['bestaetigung']['confirmed']);
        $this->assertSame(1, $stats['kurs']['visits']);
        $this->assertSame(0, $stats['uebergang']['visits']);
    }

    #[Test]
    public function a_step_that_only_the_outside_reaches_is_not_a_page_anybody_can_open(): void
    {
        $this->journey();

        $this->get('/f/reise/uebergang')->assertNotFound();

        $this->assertSame(0, FunnelVisit::count());
        $this->assertSame(0, FunnelStepEvent::count());
    }

    #[Test]
    public function nobody_who_was_never_counted_is_made_up(): void
    {
        $this->journey();
        $grant = $this->grant($this->resource());

        $this->app->instance('request', Request::create('/x', 'POST'));
        Event::dispatch(new ResourceRequested($grant));
        Event::dispatch(new ResourceConfirmed($grant));
        Event::dispatch(new AutomationRunFinished('mail-kurs', 'maria@example.com'));

        $this->assertSame(0, FunnelVisit::count());
        $this->assertSame(0, FunnelStepEvent::count());
    }

    #[Test]
    public function a_lead_magnet_step_behind_a_form_of_the_funnel_is_left_to_the_form(): void
    {
        $funnel = Funnel::create(['handle' => 'reise', 'title' => 'Reise', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'form', 'type' => 'capture', 'label' => 'Form', 'slug' => 'form'],
            ['node_key' => 'bestaetigung', 'type' => 'lead_magnet', 'label' => 'B', 'slug' => 'b', 'config' => ['resource' => 'geschenk']],
        ]);
        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'form', 'from_output' => 'default'],
            ['from_node_key' => 'form', 'to_node_key' => 'bestaetigung', 'from_output' => 'default'],
        ]);
        FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => $this->token, 'email' => 'maria@example.com']);

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($this->grant($this->resource())));

        $this->assertSame(0, FunnelStepEvent::count());
    }

    #[Test]
    public function a_request_that_came_from_the_funnels_own_step_is_not_counted_twice(): void
    {
        $this->journey();
        $this->onThePage($this->token);
        $grant = $this->grant($this->resource(), 'maria@example.com', ['source' => 'funnel:reise']);

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($grant));

        $this->assertSame(0, $this->eventsOf('bestaetigung', FunnelStepEvent::LEAD_MAGNET_REQUESTED));
    }

    #[Test]
    public function an_unpublished_funnel_hears_nothing_and_a_failing_listener_never_breaks_the_sibling(): void
    {
        $this->journey(published: false);
        $this->onThePage($this->token);
        $grant = $this->grant($this->resource());

        $this->app->instance('request', Request::create('/x', 'POST', [], [FunnelWalk::COOKIE => $this->token]));
        Event::dispatch(new ResourceRequested($grant));
        Event::dispatch(new AutomationRunFinished('mail-kurs', 'maria@example.com'));

        $this->assertSame(1, FunnelStepEvent::count());

        // A grant without a resource: the listener trips over it, the sibling does not notice.
        Funnel::query()->update(['published' => true]);
        $broken = new Grant(['email' => 'x@example.com']);
        Event::dispatch(new ResourceRequested($broken));

        $this->assertTrue(true);
    }
}
