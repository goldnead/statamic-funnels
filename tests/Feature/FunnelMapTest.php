<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Integrations\Map\EntryMapObjects;
use Goldnead\StatamicFunnels\Integrations\Map\FunnelMapObjects;
use Goldnead\StatamicFunnels\Integrations\Map\FunnelStepMapObjects;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStage;
use Goldnead\StatamicFunnels\Models\FunnelStageRef;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Stand-in for a lead-magnets provider, so the map is tested against the
 * contract and not against whichever sibling addon happens to be installed.
 */
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
        $all = [
            new MapObject('lead_magnet', 'minikurs', 'Minikurs', '/cp/lead-magnets/minikurs', true, 'optin'),
            new MapObject('lead_magnet', 'practice', 'Practice Pack', '/cp/lead-magnets/practice', false),
        ];

        return array_values(array_filter($all, fn ($o) => ! $query || stripos($o->label, $query) !== false));
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

/**
 * The map: what is stored, what is asked of the owning addon, and what the
 * page is fed.
 */
class FunnelMapTest extends TestCase
{
    protected $superuser = null;

    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();

        app(MapObjectRegistry::class)->register(FakeLeadMagnetMapObjects::class);
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'cvt', 'title' => 'CVT Minikurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);
        $funnel->steps()->create(['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Freebie', 'config' => ['resource' => 'minikurs']]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'lm_1', 'from_output' => 'default']);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** @return array<string, mixed> */
    protected function props(Funnel $funnel, string $query = ''): array
    {
        $response = $this->actingAs($this->user())
            ->get("/cp/utilities/funnels/{$funnel->id}/map{$query}")
            ->assertOk();

        preg_match('/data-page="(.*?)"/s', $response->getContent(), $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }

    protected function save(Funnel $funnel, array $stages)
    {
        return $this->actingAs($this->user())->put("/cp/utilities/funnels/{$funnel->id}/map", ['stages' => $stages]);
    }

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
        $other = Funnel::create(['handle' => 'other', 'title' => 'Other', 'published' => false]);
        FunnelVisit::create(['funnel_id' => $other->id, 'token' => 'c', 'created_at' => now()->subDay()]);

        foreach ([$inside, $inside, $outside] as $visit) {
            FunnelStepEvent::create(['visit_id' => $visit->id, 'node_key' => 'lm_1', 'event' => FunnelStepEvent::ENTERED, 'created_at' => $visit === $outside ? now()->subDays(60) : now()->subDay()]);
        }

        $funnels = new FunnelMapObjects;
        $steps = new FunnelStepMapObjects;

        $this->assertSame('CVT Minikurs', $funnels->find('cvt')->label);
        $this->assertTrue($funnels->find('cvt')->enabled);
        $this->assertNull($funnels->find('nope'));
        $this->assertSame(1, $funnels->metric('cvt', $period)->value);

        $this->assertSame('Freebie', $steps->find('cvt:lm_1')->label);
        $this->assertTrue($steps->find('cvt:lm_1')->enabled);
        $this->assertNull($steps->find('cvt:missing'));
        $this->assertNull($steps->find('missing:lm_1'));
        $this->assertSame(1, $steps->metric('cvt:lm_1', $period)->value, 'one visitor, counted once, inside the period');
        $this->assertNull($steps->metric('cvt:missing', $period));
    }

    #[Test]
    public function a_disabled_step_reads_as_off(): void
    {
        $funnel = $this->funnel();
        $funnel->steps()->where('node_key', 'lm_1')->update(['disabled' => true]);

        $this->assertFalse((new FunnelStepMapObjects)->find('cvt:lm_1')->enabled);
    }

    #[Test]
    public function an_entry_is_found_by_id_and_listed_by_title(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('cvt-im-chor-minikurs')->published(true)->data(['title' => 'CVT im Chor Minikurs']))->save();

        $provider = new EntryMapObjects;

        $this->assertSame('CVT im Chor Minikurs', $provider->find($entry->id())->label);
        $this->assertTrue($provider->find($entry->id())->enabled);
        $this->assertNull($provider->find('nope'));
        $this->assertNull($provider->metric($entry->id(), MapPeriod::lastDays(30)), 'no invented number for a page');
        $this->assertCount(1, $provider->objects('minikurs'));
        $this->assertCount(0, $provider->objects('gibtsnicht'));
    }

    #[Test]
    public function the_page_lists_the_graph_steps_with_what_they_point_at(): void
    {
        $funnel = $this->funnel();

        $props = $this->props($funnel);

        $this->assertSame(['entry_1', 'lm_1'], array_map(fn ($c) => explode(':', $c['ref_id'])[1], $props['path']));

        $freebie = $props['path'][1];
        $this->assertSame('found', $freebie['status']);
        $this->assertSame('Freebie', $freebie['label']);
        $this->assertSame('lead_magnet', $freebie['target']['type']);
        $this->assertSame('Minikurs', $freebie['target']['label']);
        $this->assertNull($freebie['target']['metric'], 'a target carries no second number');
        $this->assertNull($props['path'][0]['target']);
    }

    #[Test]
    public function a_saved_layout_comes_back_with_label_link_switch_and_a_labelled_number(): void
    {
        $funnel = $this->funnel();

        $this->save($funnel, [
            ['name' => 'Freebie', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs'], ['type' => 'lead_magnet', 'ref_id' => 'practice']]],
            ['name' => 'Kurs', 'refs' => []],
        ])->assertRedirect();

        $props = $this->props($funnel);

        $this->assertSame(['Freebie', 'Kurs'], array_column($props['stages'], 'name'));

        [$minikurs, $practice] = $props['stages'][0]['refs'];

        $this->assertSame('Minikurs', $minikurs['label']);
        $this->assertSame('/cp/lead-magnets/minikurs', $minikurs['cp_url']);
        $this->assertTrue($minikurs['enabled']);
        $this->assertSame('Lead magnet', $minikurs['type_label']);
        $this->assertSame(['value' => 12, 'what' => 'Requests', 'period' => 'last 30 days'], $minikurs['metric']);

        $this->assertFalse($practice['enabled']);
        $this->assertNull($practice['metric'], 'an addon without a number gives a card without one');
    }

    #[Test]
    public function the_period_is_chosen_for_the_whole_map(): void
    {
        $funnel = $this->funnel();
        $this->save($funnel, [['name' => 'A', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs']]]]);

        $this->assertSame('last 7 days', $this->props($funnel, '?days=7')['stages'][0]['refs'][0]['metric']['period']);
        $this->assertSame('last 30 days', $this->props($funnel, '?days=3')['stages'][0]['refs'][0]['metric']['period'], 'an unlisted span falls back');
    }

    #[Test]
    public function a_reference_to_a_deleted_object_is_broken_not_gone(): void
    {
        $funnel = $this->funnel();
        $this->save($funnel, [['name' => 'A', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'deleted']]]]);

        $card = $this->props($funnel)['stages'][0]['refs'][0];

        $this->assertSame('missing', $card['status']);
        $this->assertSame('deleted', $card['label'], 'the card keeps what it used to point at');
        $this->assertNull($card['metric']);
    }

    #[Test]
    public function a_reference_to_an_addon_that_is_not_installed_is_unavailable_and_survives_saving(): void
    {
        $funnel = $this->funnel();
        $this->save($funnel, [['name' => 'A', 'refs' => [['type' => 'course', 'ref_id' => 'cvt']]]])->assertRedirect();

        $this->assertSame('unavailable', $this->props($funnel)['stages'][0]['refs'][0]['status']);
        $this->assertSame(1, FunnelStageRef::count());
    }

    #[Test]
    public function saving_reorders_renames_and_drops_what_was_removed(): void
    {
        $funnel = $this->funnel();
        $this->save($funnel, [
            ['name' => 'A', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs']]],
            ['name' => 'B', 'refs' => []],
            ['name' => 'C', 'refs' => []],
        ]);

        [$a, $b, $c] = $funnel->stages()->get()->all();

        $this->save($funnel, [
            ['id' => $c->id, 'name' => 'C neu', 'refs' => []],
            ['id' => $a->id, 'name' => 'A', 'refs' => [
                ['type' => 'lead_magnet', 'ref_id' => 'practice'],
                ['type' => 'lead_magnet', 'ref_id' => 'minikurs'],
                ['type' => 'lead_magnet', 'ref_id' => 'minikurs'],
            ]],
        ])->assertRedirect();

        $stages = $funnel->stages()->with('refs')->get();

        $this->assertSame(['C neu', 'A'], $stages->pluck('name')->all());
        $this->assertSame($c->id, $stages[0]->id, 'an existing phase keeps its row');
        $this->assertSame(['practice', 'minikurs'], $stages[1]->refs->pluck('ref_id')->all(), 'order kept, duplicate dropped');
        $this->assertNull(FunnelStage::find($b->id));
    }

    #[Test]
    public function a_phase_of_another_funnel_is_refused_and_nothing_changes(): void
    {
        $funnel = $this->funnel();
        $other = Funnel::create(['handle' => 'other', 'title' => 'Other', 'published' => false]);
        $foreign = $other->stages()->create(['name' => 'Fremd', 'position' => 0]);

        $this->save($funnel, [['id' => $foreign->id, 'name' => 'Gekapert', 'refs' => []]])->assertSessionHasErrors('stages.0.id');

        $this->assertSame('Fremd', $foreign->fresh()->name);
        $this->assertSame(0, $funnel->stages()->count());
    }

    #[Test]
    public function the_layout_is_validated(): void
    {
        $funnel = $this->funnel();

        $this->save($funnel, [['name' => '', 'refs' => []]])->assertSessionHasErrors('stages.0.name');
        $this->save($funnel, [['name' => 'A', 'refs' => [['type' => 'lead_magnet']]]])->assertSessionHasErrors('stages.0.refs.0.ref_id');
    }

    #[Test]
    public function the_picker_lists_objects_of_one_type_and_filters_them(): void
    {
        $funnel = $this->funnel();

        $all = $this->actingAs($this->user())->getJson("/cp/utilities/funnels/{$funnel->id}/map/objects?type=lead_magnet")->assertOk()->json('objects');
        $this->assertSame(['minikurs', 'practice'], array_column($all, 'id'));

        $some = $this->actingAs($this->user())->getJson("/cp/utilities/funnels/{$funnel->id}/map/objects?type=lead_magnet&q=pack")->json('objects');
        $this->assertSame(['practice'], array_column($some, 'id'));

        $this->actingAs($this->user())->getJson("/cp/utilities/funnels/{$funnel->id}/map/objects?type=unknown")->assertNotFound();
    }

    #[Test]
    public function steps_of_the_graph_are_not_offered_for_pinning_by_hand(): void
    {
        $types = array_column($this->props($this->funnel())['types'], 'value');

        $this->assertContains('lead_magnet', $types);
        $this->assertContains('entry', $types);
        $this->assertNotContains('funnel_step', $types);
    }

    #[Test]
    public function without_the_permission_the_map_is_closed(): void
    {
        $funnel = $this->funnel();
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $plain = tap(User::make()->email('plain@example.com')->assignRole($role))->save();

        $this->actingAs($plain)->get("/cp/utilities/funnels/{$funnel->id}/map")->assertRedirect(cp_route('index'));
        $this->assertContains($this->actingAs($plain)->put("/cp/utilities/funnels/{$funnel->id}/map", ['stages' => [['name' => 'X']]])->status(), [302, 403]);
        $this->assertContains($this->actingAs($plain)->getJson("/cp/utilities/funnels/{$funnel->id}/map/objects?type=entry")->status(), [302, 403]);
        $this->assertSame(0, $funnel->stages()->count());
    }

    #[Test]
    public function deleting_a_funnel_takes_its_map_with_it(): void
    {
        $funnel = $this->funnel();
        $this->save($funnel, [['name' => 'A', 'refs' => [['type' => 'lead_magnet', 'ref_id' => 'minikurs']]]]);

        $funnel->delete();

        $this->assertSame(0, FunnelStage::count());
        $this->assertSame(0, FunnelStageRef::count());
    }

    #[Test]
    public function the_listing_and_the_editor_link_to_the_map(): void
    {
        $funnel = $this->funnel();

        $this->assertSame(cp_route('utilities.funnels.map', $funnel->id), $this->listingRow()['map_url']);
    }

    /** @return array<string, mixed> */
    protected function listingRow(): array
    {
        $response = $this->actingAs($this->user())->get('/cp/utilities/funnels')->assertOk();
        preg_match('/data-page="(.*?)"/s', $response->getContent(), $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props']['funnels'][0];
    }
}
