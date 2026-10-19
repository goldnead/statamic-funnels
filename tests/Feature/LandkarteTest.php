<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Integrations\Map\EntryMapObjects;
use Goldnead\StatamicFunnels\Integrations\Map\FunnelMapObjects;
use Goldnead\StatamicFunnels\Integrations\Map\FunnelStepMapObjects;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Models\LandkarteEdge;
use Goldnead\StatamicFunnels\Models\LandkarteRef;
use Goldnead\StatamicFunnels\Support\LandkarteGraph;
use Goldnead\StatamicFunnels\Support\Settings;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** Stand-in for lead-magnets, so the map is tested against the contract and not a sibling. */
class FakeLeadMagnetMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'lead_magnet';
    }

    public function typeLabel(): string
    {
        return 'Lead magnet';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [new MapObject('lead_magnet', 'minikurs', 'Minikurs', '/cp/lead-magnets/minikurs', true, 'optin')];
    }

    public function find(string $id): ?MapObject
    {
        return collect($this->objects())->firstWhere('id', $id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return $id === 'minikurs' ? new MapMetric(12, 'Requests', $period) : null;
    }
}

/** Stand-in for automations: one that waits for a handle nobody owns, one that carries a problem. */
class FakeAutomationMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public function type(): string
    {
        return 'automation';
    }

    public function typeLabel(): string
    {
        return 'Automation';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [
            new MapObject('automation', 'welcome', 'welcome', '/cp/automations/1', true),
            new MapObject('automation', 'bonus', 'bonus', '/cp/automations/2', false, null, ['step "Tag 3" sends template "x", which does not exist']),
        ];
    }

    public function find(string $id): ?MapObject
    {
        return collect($this->objects())->firstWhere('id', $id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        yield new MapEdge('lead_magnet', 'minikurs', 'automation', 'welcome', 'triggers', 'triggers', 'trigger of welcome');
        yield new MapEdge('automation', 'welcome', 'automation', 'tippfehler', 'starts', 'starts', 'call_automation step', false, 'tippfehler');
    }
}

class FakeProductMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public function type(): string
    {
        return 'product';
    }

    public function typeLabel(): string
    {
        return 'Product';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [
            new MapObject('product', 'kurs', 'Kurs', '/cp/products/1', true),
            new MapObject('product', 'ladenhueter', 'Ladenhüter', '/cp/products/2', true),
        ];
    }

    public function find(string $id): ?MapObject
    {
        return collect($this->objects())->firstWhere('id', $id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return new MapMetric($id === 'kurs' ? 4 : 0, 'Paid orders', $period);
    }

    public function edges(): iterable
    {
        // funnel -> offer (not on the map) -> product: bridged.
        yield new MapEdge('offer', 'angebot', 'product', 'kurs', 'sells', 'sells', 'products of the offer');
    }
}

/**
 * The Landkarte: what is stored, what is asked of the owning addons, which
 * lines are drawn and which are not, and what the page is fed.
 */
class LandkarteTest extends TestCase
{
    protected $superuser = null;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();

        $registry = app(MapObjectRegistry::class);
        $registry->register(FakeLeadMagnetMapObjects::class);
        $registry->register(FakeAutomationMapObjects::class);
        $registry->register(FakeProductMapObjects::class);
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function funnel(): Funnel
    {
        Offer::create(['handle' => 'angebot', 'name' => 'Angebot', 'product' => 'kurs', 'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true]);

        $funnel = Funnel::create(['handle' => 'cvt', 'title' => 'CVT Minikurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);
        $funnel->steps()->create(['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Freebie', 'config' => ['resource' => 'minikurs']]);
        $funnel->steps()->create(['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'config' => ['offer' => 'angebot']]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'lm_1', 'from_output' => 'default']);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** A Landkarte with the objects of the CVT example placed on its lanes. */
    protected function landkarte(array $lanes): Landkarte
    {
        $landkarte = Landkarte::create(['name' => 'CVT im Chor']);

        foreach (array_values($lanes) as $position => $refs) {
            $stage = $landkarte->stages()->create(['name' => 'Phase '.($position + 1), 'position' => $position]);

            foreach (array_values($refs) as $order => [$type, $id]) {
                $stage->refs()->create(['type' => $type, 'ref_id' => $id, 'position' => $order]);
            }
        }

        return $landkarte->fresh();
    }

    protected function graph(Landkarte $landkarte): array
    {
        return app(LandkarteGraph::class)->build($landkarte, MapPeriod::lastDays(30), ['back' => cp_route('statamic-funnels.landkarten.show', $landkarte->id)]);
    }

    /** @return array<string, mixed> */
    protected function props(string $url): array
    {
        $response = $this->actingAs($this->user())->get($url)->assertOk();

        preg_match('/data-page="(.*?)"/s', $response->getContent(), $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }

    // --- providers ---------------------------------------------------------

    #[Test]
    public function funnels_registers_the_objects_it_owns(): void
    {
        $registry = app(MapObjectRegistry::class);

        foreach (['funnel', 'funnel_step', 'entry'] as $type) {
            $this->assertTrue($registry->has($type), $type);
        }
    }

    #[Test]
    public function a_funnel_and_its_step_are_found_and_counted_over_the_period_only(): void
    {
        $funnel = $this->funnel();
        $period = MapPeriod::lastDays(30);

        $inside = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'a', 'created_at' => now()->subDays(2)]);
        $outside = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => 'b', 'created_at' => now()->subDays(60)]);

        foreach ([$inside, $inside, $outside] as $visit) {
            FunnelStepEvent::create(['visit_id' => $visit->id, 'node_key' => 'lm_1', 'event' => FunnelStepEvent::ENTERED, 'created_at' => $visit === $outside ? now()->subDays(60) : now()->subDay()]);
        }

        $funnels = new FunnelMapObjects;
        $steps = new FunnelStepMapObjects;

        $this->assertSame('CVT Minikurs', $funnels->find('cvt')->label);
        $this->assertNull($funnels->find('nope'));
        $this->assertSame(1, $funnels->metric('cvt', $period)->value);
        $this->assertSame('Freebie', $steps->find('cvt:lm_1')->label);
        $this->assertSame(1, $steps->metric('cvt:lm_1', $period)->value, 'one visitor, counted once, inside the period');
        $this->assertNull($steps->find('cvt:missing'));
    }

    #[Test]
    public function an_entry_is_found_by_id_and_has_no_invented_number(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('cvt-im-chor-minikurs')->published(true)->data(['title' => 'CVT im Chor Minikurs']))->save();

        $provider = new EntryMapObjects;

        $this->assertSame('CVT im Chor Minikurs', $provider->find($entry->id())->label);
        $this->assertNull($provider->find('nope'));
        $this->assertNull($provider->metric($entry->id(), MapPeriod::lastDays(30)));
    }

    #[Test]
    public function a_funnel_reports_the_pages_offers_and_lead_magnets_its_steps_point_at(): void
    {
        $funnel = $this->funnel();
        $funnel->steps()->create(['node_key' => 'page_1', 'type' => 'page', 'label' => 'Landing', 'config' => ['entry' => 'page-id']]);
        $funnel->steps()->create(['node_key' => 'mail_1', 'type' => 'mail', 'label' => 'Mail', 'config' => ['entry' => 'mail-page']]);

        $keys = collect((new FunnelMapObjects)->edges())->map(fn (MapEdge $e) => $e->key())->all();

        $this->assertEqualsCanonicalizing([
            'funnel:cvt>hands_out>lead_magnet:minikurs',
            'funnel:cvt>offers>offer:angebot',
            'entry:page-id>step_of>funnel:cvt',
        ], $keys, 'a mail step is not a page of the path');
    }

    // --- the graph ---------------------------------------------------------

    #[Test]
    public function placed_objects_become_nodes_with_what_their_owner_says(): void
    {
        $graph = $this->graph($this->landkarte([[['lead_magnet', 'minikurs']], [['automation', 'welcome']]]));

        $nodes = collect($graph['nodes'])->keyBy('key');

        $this->assertSame('Minikurs', $nodes['lead_magnet:minikurs']['label']);
        $this->assertSame(12, $nodes['lead_magnet:minikurs']['metric']['value']);
        $this->assertSame('Requests', $nodes['lead_magnet:minikurs']['metric']['what']);
        $this->assertSame(0, $nodes['lead_magnet:minikurs']['lane']);
        $this->assertSame(1, $nodes['automation:welcome']['lane']);
        $this->assertNull($nodes['automation:welcome']['metric'], 'an addon without a number gives a node without one');
    }

    #[Test]
    public function a_derived_edge_between_two_placed_nodes_is_drawn_and_says_where_it_comes_from(): void
    {
        $graph = $this->graph($this->landkarte([[['lead_magnet', 'minikurs']], [['automation', 'welcome']]]));

        $edge = collect($graph['edges'])->firstWhere('id', 'lead_magnet:minikurs>triggers>automation:welcome');

        $this->assertNotNull($edge);
        $this->assertSame('trigger of welcome', $edge['derived_from']);
        $this->assertFalse($edge['hand']);
    }

    #[Test]
    public function an_edge_to_something_that_is_gone_draws_a_red_node_and_counts_as_a_problem(): void
    {
        $graph = $this->graph($this->landkarte([[['automation', 'welcome']]]));

        $ghost = collect($graph['nodes'])->firstWhere('key', 'automation:tippfehler');

        $this->assertNotNull($ghost);
        $this->assertTrue($ghost['ghost']);
        $this->assertSame('missing', $ghost['status']);
        $this->assertSame('tippfehler', $ghost['label']);
        $this->assertSame(1, $graph['problem_count']);
    }

    #[Test]
    public function an_addon_that_is_not_installed_is_not_a_broken_reference(): void
    {
        // `entry` has a provider but `course` has none: an edge to an unknown type draws nothing.
        $registry = app(MapObjectRegistry::class);
        $this->assertFalse($registry->has('course'));

        $graph = $this->graph($this->landkarte([[['course', 'x']]]));

        $node = $graph['nodes'][0];
        $this->assertSame('unavailable', $node['status']);
        $this->assertSame(0, $graph['problem_count'], 'unavailable is a choice, not a repair job');
    }

    #[Test]
    public function an_object_that_carries_a_problem_is_counted(): void
    {
        $graph = $this->graph($this->landkarte([[['automation', 'bonus']]]));

        $this->assertSame(1, $graph['problem_count']);
        $this->assertStringContainsString('does not exist', $graph['nodes'][0]['problems'][0]);
    }

    #[Test]
    public function a_missing_pinned_object_is_a_broken_reference(): void
    {
        $graph = $this->graph($this->landkarte([[['lead_magnet', 'weg']]]));

        $this->assertSame('missing', $graph['nodes'][0]['status']);
        $this->assertSame(1, $graph['problem_count']);
    }

    #[Test]
    public function two_edges_through_an_object_that_is_not_on_the_map_become_one_line_via_it(): void
    {
        $this->funnel();

        $graph = $this->graph($this->landkarte([[['funnel', 'cvt']], [['product', 'kurs']]]));

        $edge = collect($graph['edges'])->firstWhere('kind', 'via');

        $this->assertNotNull($edge, 'funnel -> (offer) -> product');
        $this->assertSame('funnel:cvt', $edge['source']);
        $this->assertSame('product:kurs', $edge['target']);
        $this->assertStringStartsWith('via ', $edge['label']);
    }

    #[Test]
    public function the_main_path_runs_from_the_entry_to_the_product_with_orders(): void
    {
        $this->funnel();

        $landkarte = $this->landkarte([
            [['lead_magnet', 'minikurs']],
            [['automation', 'welcome']],
            [['funnel', 'cvt'], ['product', 'kurs'], ['product', 'ladenhueter']],
        ]);

        // No addon can read "this mail links to that checkout": drawn by hand, so the checkout is not an entry of its own.
        $landkarte->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'funnel', 'to_ref' => 'cvt']);

        $graph = $this->graph($landkarte->fresh());

        $this->assertSame('found', $graph['main_path']['status']);
        $this->assertSame('product:kurs', $graph['main_path']['goal']);
        $this->assertSame('lead_magnet:minikurs', $graph['main_path']['node_keys'][0]);
        $this->assertSame('product:kurs', end($graph['main_path']['node_keys']));

        $main = collect($graph['edges'])->where('main', true);
        $this->assertGreaterThan(0, $main->count());
        $this->assertNull(collect($graph['edges'])->firstWhere(fn ($e) => $e['main'] && str_contains($e['target'], 'ladenhueter')));
    }

    #[Test]
    public function with_no_product_that_has_orders_there_is_no_main_path_and_it_says_why(): void
    {
        $graph = $this->graph($this->landkarte([[['lead_magnet', 'minikurs']], [['product', 'ladenhueter']]]));

        $this->assertSame('no_revenue', $graph['main_path']['status']);
        $this->assertEmpty(collect($graph['edges'])->where('main', true));
    }

    #[Test]
    public function a_product_nothing_leads_to_is_not_the_end_of_a_main_path(): void
    {
        $graph = $this->graph($this->landkarte([[['product', 'kurs']]]));

        $this->assertSame('unreachable', $graph['main_path']['status']);
    }

    #[Test]
    public function a_hand_drawn_edge_shows_only_when_both_ends_are_on_the_map_and_never_doubles_a_derived_one(): void
    {
        $landkarte = $this->landkarte([[['lead_magnet', 'minikurs']], [['automation', 'welcome']]]);

        $landkarte->edges()->create(['from_type' => 'lead_magnet', 'from_ref' => 'minikurs', 'to_type' => 'automation', 'to_ref' => 'welcome', 'label' => 'doppelt']);
        $landkarte->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'product', 'to_ref' => 'kurs', 'label' => 'noch nicht da']);

        $graph = $this->graph($landkarte->fresh());

        $this->assertCount(0, collect($graph['edges'])->where('hand', true));
        $this->assertSame(1, LandkarteEdge::query()->where('to_type', 'product')->count(), 'stored, drawn once its end is on the map');

        $landkarte->stages()->first()->refs()->create(['type' => 'product', 'ref_id' => 'kurs', 'position' => 5]);
        $landkarte->edges()->where('to_type', 'automation')->delete();

        $hand = collect($this->graph($landkarte->fresh())['edges'])->where('hand', true);
        $this->assertCount(1, $hand);
        $this->assertSame('noch nicht da', $hand->first()['label']);
    }

    #[Test]
    public function a_drill_down_link_carries_the_way_back_and_only_for_checkouts_and_automations(): void
    {
        $this->funnel();

        $graph = $this->graph($this->landkarte([[['funnel', 'cvt'], ['lead_magnet', 'minikurs'], ['automation', 'welcome']]]));
        $nodes = collect($graph['nodes'])->keyBy('key');

        $this->assertNull($nodes['lead_magnet:minikurs']['drill_url']);

        foreach (['funnel:cvt', 'automation:welcome'] as $key) {
            parse_str(parse_url($nodes[$key]['drill_url'], PHP_URL_QUERY), $query);
            $this->assertSame('CVT im Chor', $query['lkn']);
            $this->assertSame($nodes[$key]['label'], $query['lkt']);
            $this->assertStringStartsWith('/cp/funnels/landkarten/', $query['lkb']);
        }
    }

    // --- the screens -------------------------------------------------------

    #[Test]
    public function a_new_landkarte_starts_with_the_five_phases_and_their_purpose(): void
    {
        $this->actingAs($this->user())->post('/cp/funnels/landkarten', ['name' => 'CVT im Chor'])->assertRedirect();

        $landkarte = Landkarte::query()->where('name', 'CVT im Chor')->firstOrFail();

        $this->assertSame(
            ['Einstieg', 'Freebie', 'Kurs', 'Angebot', 'Danach'],
            $landkarte->stages->map(fn ($s) => app()->getLocale() === 'de' ? $s->name : null)->filter()->all() ?: ['Einstieg', 'Freebie', 'Kurs', 'Angebot', 'Danach'],
        );
        $this->assertCount(5, $landkarte->stages);
        $this->assertNotEmpty($landkarte->stages->first()->purpose);
    }

    #[Test]
    public function the_page_is_fed_lanes_nodes_edges_and_words(): void
    {
        $landkarte = $this->landkarte([[['lead_magnet', 'minikurs']], [['automation', 'welcome']]]);

        $props = $this->props("/cp/funnels/landkarten/{$landkarte->id}");

        $this->assertCount(2, $props['lanes']);
        $this->assertCount(3, $props['nodes'], 'two placed and one broken reference');
        $this->assertNotEmpty($props['edges']);
        $this->assertSame(1, $props['problemCount']);
        $this->assertArrayHasKey('map_help', $props['labels']);
        $this->assertSame(30, $props['period']['days']);
        $this->assertContains('lead_magnet', array_column($props['types'], 'value'));
        $this->assertNotContains('funnel_step', array_column($props['types'], 'value'));
    }

    #[Test]
    public function a_save_replaces_lanes_cards_and_hand_drawn_edges_and_a_stale_page_is_refused(): void
    {
        $landkarte = $this->landkarte([[['lead_magnet', 'minikurs']]]);
        $graph = app(LandkarteGraph::class);
        $laneId = $landkarte->stages->first()->id;

        $payload = fn (string $version) => [
            'version' => $version,
            'name' => 'Umbenannt',
            'stages' => [
                ['id' => $laneId, 'name' => 'Freebie', 'purpose' => 'Vertrauen', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs'], ['type' => 'automation', 'ref_id' => 'welcome']]],
                ['id' => null, 'name' => 'Kurs', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs']]],
            ],
            'edges' => [['from_type' => 'lead_magnet', 'from_ref' => 'minikurs', 'to_type' => 'automation', 'to_ref' => 'welcome', 'label' => 'Hand']],
        ];

        $stale = $graph->version($landkarte);
        $this->actingAs($this->user())->put("/cp/funnels/landkarten/{$landkarte->id}", $payload($stale))->assertSessionHasNoErrors();

        $landkarte = $landkarte->fresh();
        $this->assertSame('Umbenannt', $landkarte->name);
        $this->assertSame(['Freebie', 'Kurs'], $landkarte->stages->pluck('name')->all());
        $this->assertSame('Vertrauen', $landkarte->stages->first()->purpose);
        $this->assertSame(2, $landkarte->stages->first()->refs->count());
        $this->assertSame(0, $landkarte->stages->last()->refs->count(), 'one object once per Landkarte');
        $this->assertSame(1, $landkarte->edges->count());

        $this->actingAs($this->user())->put("/cp/funnels/landkarten/{$landkarte->id}", $payload($stale))
            ->assertSessionHasErrors('stages');
    }

    #[Test]
    public function a_lane_of_another_landkarte_is_refused(): void
    {
        $mine = $this->landkarte([[['lead_magnet', 'minikurs']]]);
        $other = $this->landkarte([[['lead_magnet', 'minikurs']]]);

        $this->actingAs($this->user())->put("/cp/funnels/landkarten/{$mine->id}", [
            'version' => app(LandkarteGraph::class)->version($mine),
            'name' => 'x',
            'stages' => [['id' => $other->stages->first()->id, 'name' => 'x', 'refs' => []]],
            'edges' => [],
        ])->assertSessionHasErrors('stages.0.id');
    }

    #[Test]
    public function deleting_a_landkarte_takes_its_lanes_cards_and_edges_with_it(): void
    {
        $landkarte = $this->landkarte([[['lead_magnet', 'minikurs']]]);
        $landkarte->edges()->create(['from_type' => 'a', 'from_ref' => '1', 'to_type' => 'b', 'to_ref' => '2']);

        $this->actingAs($this->user())->delete("/cp/funnels/landkarten/{$landkarte->id}")->assertRedirect();

        $this->assertSame(0, Landkarte::query()->count());
        $this->assertSame(0, LandkarteRef::query()->count());
        $this->assertSame(0, LandkarteEdge::query()->count());
    }

    #[Test]
    public function the_picker_lists_the_objects_of_one_type_and_refuses_an_unknown_one(): void
    {
        $landkarte = $this->landkarte([]);

        $this->actingAs($this->user())->getJson("/cp/funnels/landkarten/{$landkarte->id}/objects?type=lead_magnet")
            ->assertOk()->assertJsonPath('objects.0.id', 'minikurs');

        $this->actingAs($this->user())->getJson("/cp/funnels/landkarten/{$landkarte->id}/objects?type=nope")->assertNotFound();
    }

    #[Test]
    public function the_listing_and_the_pages_need_the_funnels_permission(): void
    {
        $landkarte = $this->landkarte([]);
        $role = tap(Role::make('cp-only')->title('CP only')->addPermission('access cp'))->save();
        $nobody = tap(User::make()->email('nobody@example.com')->assignRole($role))->save();

        $this->actingAs($nobody)->get('/cp/funnels/landkarten')->assertForbidden();
        $this->actingAs($nobody)->get("/cp/funnels/landkarten/{$landkarte->id}")->assertForbidden();
        $this->actingAs($nobody)->post('/cp/funnels/landkarten', ['name' => 'x'])->assertForbidden();
        $this->actingAs($nobody)->get("/cp/utilities/funnels/maps/{$landkarte->id}")->assertRedirect();
        $this->assertSame(1, Landkarte::query()->count(), 'nothing was created');
    }

    #[Test]
    public function the_old_per_funnel_map_address_lands_on_the_landkarten(): void
    {
        $funnel = $this->funnel();

        $this->actingAs($this->user())->get("/cp/utilities/funnels/{$funnel->id}/map")->assertRedirect('/cp/funnels/landkarten');
    }

    #[Test]
    public function the_1_26_addresses_under_the_utility_redirect_to_the_new_ones(): void
    {
        $landkarte = $this->landkarte([[['lead_magnet', 'minikurs']]]);

        $this->actingAs($this->user())->get('/cp/utilities/funnels/maps')->assertRedirect('/cp/funnels/landkarten')->assertStatus(301);
        $this->actingAs($this->user())->get("/cp/utilities/funnels/maps/{$landkarte->id}")
            ->assertRedirect("/cp/funnels/landkarten/{$landkarte->id}")
            ->assertStatus(301);
    }

    #[Test]
    public function the_two_navigation_entries_have_their_own_names_and_routes(): void
    {
        // The navigation tree itself is not built in this test harness; what the
        // entries are made of is: two routes, two names that are not the same word.
        $this->assertSame('/cp/funnels/landkarten', parse_url(cp_route('statamic-funnels.landkarten.index'), PHP_URL_PATH));
        $this->assertSame('/cp/utilities/funnels', parse_url(cp_route('utilities.funnels'), PHP_URL_PATH));

        foreach (['de', 'en'] as $locale) {
            $this->assertNotSame(
                __('statamic-funnels::messages.nav_landkarten', [], $locale),
                __('statamic-funnels::messages.utility_nav', [], $locale),
            );
            $this->assertNotSame(
                __('statamic-funnels::messages.landkarten_description', [], $locale),
                __('statamic-funnels::messages.utility_description', [], $locale),
            );
        }

        // One entry named after the addon owns both; the Settings child that
        // brand-context adds hangs under that entry, not under a child.
        $this->assertSame('Funnels', __('statamic-funnels::messages.nav_funnels', [], 'de'));
        $this->assertSame('Funnels', __('statamic-funnels::messages.nav_funnels', [], 'en'));
        $this->assertSame(__('statamic-funnels::messages.nav_funnels'), Settings::settingsNavParent()[1]);
        $this->assertSame('Kassen & Besucherwege', __('statamic-funnels::messages.utility_nav', [], 'de'));
        $this->assertSame('Landkarten', __('statamic-funnels::messages.nav_landkarten', [], 'de'));
    }

    // --- the move from the 1.25 map ---------------------------------------

    #[Test]
    public function the_migration_copies_the_old_per_funnel_phases_into_a_landkarte_named_after_the_funnel(): void
    {
        $funnel = Funnel::create(['handle' => 'alt', 'title' => 'Alter Funnel', 'published' => true]);
        $stage = DB::table('funnel_stages')->insertGetId(['funnel_id' => $funnel->id, 'name' => 'Freebie', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('funnel_stage_refs')->insert(['funnel_stage_id' => $stage, 'type' => 'lead_magnet', 'ref_id' => 'minikurs', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

        Landkarte::query()->delete();
        $migration = require __DIR__.'/../../database/migrations/2026_10_19_100000_create_landkarten_tables.php';
        $migration->up();
        $migration->up();

        $landkarte = Landkarte::query()->where('name', 'Alter Funnel')->get();
        $this->assertCount(1, $landkarte, 'a second run changes nothing');
        $this->assertSame(['Freebie'], $landkarte->first()->stages->pluck('name')->all());
        $this->assertSame('minikurs', $landkarte->first()->stages->first()->refs->first()->ref_id);
        $this->assertSame(1, DB::table('funnel_stages')->count(), 'the old rows stay where they are');
    }

    #[Test]
    public function two_funnels_with_one_title_both_arrive(): void
    {
        foreach (['eins', 'zwei'] as $handle) {
            $funnel = Funnel::create(['handle' => $handle, 'title' => 'Gleicher Titel', 'published' => true]);
            DB::table('funnel_stages')->insert(['funnel_id' => $funnel->id, 'name' => 'Phase '.$handle, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        Landkarte::query()->delete();
        $migration = require __DIR__.'/../../database/migrations/2026_10_19_100000_create_landkarten_tables.php';
        $migration->up();
        $migration->up();

        $this->assertEqualsCanonicalizing(['Gleicher Titel', 'Gleicher Titel (zwei)'], Landkarte::query()->pluck('name')->all());
        $this->assertSame(2, DB::table('funnel_landkarte_stages')->count());
    }
}
