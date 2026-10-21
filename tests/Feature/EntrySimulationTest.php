<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicFunnels\Integrations\Map\EntryMapObjects;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/** A page in the dry run: a person who reaches it sees it. Described, never requested. */
class EntrySimulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Effect::class)) {
            $this->markTestSkipped('needs statamic-brand-context 1.21 (the dry run planner)');
        }

        Collection::make('pages')->routes('/{slug}')->save();
    }

    protected function react(string $id): array
    {
        return (new EntryMapObjects)->react($id, new SimEvent(SimEvent::ENTERED), SimPerson::known(), CarbonImmutable::now());
    }

    #[Test]
    public function a_live_page_is_a_page_line_with_its_address(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();

        $effects = $this->react($entry->id());

        // The page, and the hand-over to whatever the map connects it to.
        $this->assertSame([Effect::PAGE, Effect::EMIT], array_map(fn (Effect $e) => $e->kind, $effects));
        $this->assertSame(SimEvent::ENTERED, $effects[1]->emits->type);
        $this->assertSame([], $effects[1]->emits->via);
        $this->assertSame('Minikurs', $effects[0]->label);
        $this->assertSame('/minikurs', $effects[0]->detail);
        $this->assertSame(['url' => '/minikurs', 'entry' => $entry->id()], $effects[0]->meta);
    }

    #[Test]
    public function a_draft_is_no_page_for_a_visitor(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('entwurf')->published(false)->data(['title' => 'Entwurf']))->save();

        $this->assertSame([Effect::STOP], array_map(fn (Effect $e) => $e->kind, $this->react($entry->id())));
    }

    #[Test]
    public function a_page_that_is_gone_is_unknown_and_a_page_begins_nothing_by_itself(): void
    {
        $this->assertSame([Effect::UNKNOWN], array_map(fn (Effect $e) => $e->kind, $this->react('nope')));
        $this->assertSame([], (new EntryMapObjects)->paths('anything'));
    }

    #[Test]
    public function it_is_as_guarded_as_the_panel_details(): void
    {
        $live = tap(Entry::make()->collection('pages')->slug('live')->published(true)->data(['title' => 'Live']))->save();
        $draft = tap(Entry::make()->collection('pages')->slug('draft')->published(false)->data(['title' => 'Draft']))->save();
        $map = new EntryMapObjects;

        // A live page is public; a draft is not, and nobody is no user.
        $this->assertTrue($map->simulationAuthorized($live->id(), null));
        $this->assertFalse($map->simulationAuthorized($draft->id(), null));
        $this->assertFalse($map->simulationAuthorized('nope', null));
    }

    #[Test]
    public function describing_a_page_writes_nothing_and_requests_nothing(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();
        Mail::fake();
        Queue::fake();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->react($entry->id());

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], $writes);
    }
}
