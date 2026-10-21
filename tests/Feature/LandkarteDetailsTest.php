<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Integrations\Map\EntryMapObjects;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** A provider from before `details()` existed: it must keep running on the map. */
class OldMapObjects implements ProvidesMapObjects
{
    public function type(): string
    {
        return 'old';
    }

    public function typeLabel(): string
    {
        return 'Old';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return new MapObject('old', $id, 'Old');
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

/** Has details() but never says who may read them: the registry must refuse. */
class RichUnguardedMapObjects extends OldMapObjects
{
    public function type(): string
    {
        return 'rich';
    }

    public function details(string $id, MapPeriod $period): ?array
    {
        return [['kind' => 'facts', 'title' => null, 'rows' => [['label' => 'Secret', 'value' => '1']]]];
    }
}

/** What the panel of a card shows beyond the number: asked of the owning addon, once, when it opens. */
class LandkarteDetailsTest extends TestCase
{
    protected $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(MapObjectRegistry::class, 'detailsAllowed')) {
            $this->markTestSkipped('needs statamic-brand-context 1.20 (MapObjectRegistry::detailsAllowed())');
        }

        Collection::make('pages')->routes('/{slug}')->save();
        app(MapObjectRegistry::class)->register(OldMapObjects::class);
    }

    /** A map with the given objects placed on it, and the url of its details endpoint. */
    protected function url(string $query, array $placed = []): string
    {
        $landkarte = Landkarte::query()->create(['name' => 'CVT']);
        $stage = $landkarte->stages()->create(['name' => 'Phase 1', 'position' => 0]);

        foreach ($placed as $i => [$type, $id]) {
            $stage->refs()->create(['type' => $type, 'ref_id' => $id, 'position' => $i]);
        }

        return "/cp/funnels/landkarten/{$landkarte->id}/details?{$query}";
    }

    protected $editor = null;

    protected function asEditor(string ...$permissions)
    {
        Role::make('editor')->title('Editor')->permissions(['access cp', 'access funnels utility', ...$permissions])->save();

        return $this->actingAs($this->editor ??= tap(User::make()->email('editor@example.com')->assignRole('editor'))->save());
    }

    protected function asAdmin()
    {
        return $this->actingAs($this->admin ??= tap(User::make()->email('studio@example.com')->makeSuper())->save());
    }

    #[Test]
    public function a_page_shows_title_address_and_whether_it_is_published(): void
    {
        $live = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();
        $draft = tap(Entry::make()->collection('pages')->slug('entwurf')->published(false)->data(['title' => 'Entwurf']))->save();

        $rows = fn ($entry) => collect((new EntryMapObjects)->details($entry->id(), MapPeriod::lastDays(30))[0]['rows'])->pluck('value', 'label');

        $this->assertSame('Minikurs', $rows($live)[__('statamic-funnels::messages.map_details_title')]);
        $this->assertSame('/minikurs', $rows($live)[__('statamic-funnels::messages.map_details_url')]);
        $this->assertSame(__('statamic-funnels::messages.map_details_published'), $rows($live)[__('statamic-funnels::messages.map_details_status')]);
        $this->assertSame(__('statamic-funnels::messages.map_details_draft'), $rows($draft)[__('statamic-funnels::messages.map_details_status')]);
        $this->assertNull((new EntryMapObjects)->details('nope', MapPeriod::lastDays(30)));
    }

    #[Test]
    public function the_endpoint_hands_back_the_blocks_of_the_owner(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();

        $response = $this->asAdmin()->getJson($this->url("type=entry&ref_id={$entry->id()}&days=30", [['entry', $entry->id()]]))->assertOk();

        $this->assertSame('facts', $response->json('blocks.0.kind'));
    }

    #[Test]
    public function an_old_provider_and_an_unknown_type_do_not_break_the_panel(): void
    {
        $this->asAdmin()->getJson($this->url('type=old&ref_id=x', [['old', 'x']]))->assertOk()->assertExactJson(['blocks' => []]);
        $this->asAdmin()->getJson($this->url('type=nope&ref_id=x'))->assertNotFound();
        $this->asAdmin()->getJson($this->url('type=entry'))->assertStatus(422);
    }

    #[Test]
    public function an_object_that_is_not_on_this_map_is_not_answered(): void
    {
        $entry = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();

        $this->asAdmin()->getJson($this->url("type=entry&ref_id={$entry->id()}"))->assertNotFound();
        $this->asAdmin()->getJson($this->url("type=entry&ref_id={$entry->id()}", [['entry', 'someone-else']]))->assertNotFound();
    }

    #[Test]
    public function a_draft_page_is_only_shown_to_someone_who_may_see_the_collection(): void
    {
        $draft = tap(Entry::make()->collection('pages')->slug('entwurf')->published(false)->data(['title' => 'Entwurf']))->save();
        $url = $this->url("type=entry&ref_id={$draft->id()}", [['entry', $draft->id()]]);

        $this->asEditor()->getJson($url)->assertForbidden()->assertJson(['restricted' => true, 'blocks' => []]);

        $this->asEditor('view pages entries')->getJson($url)->assertOk()->assertJsonPath('blocks.0.kind', 'facts');
    }

    #[Test]
    public function a_published_page_is_shown_with_the_funnels_right_alone(): void
    {
        $live = tap(Entry::make()->collection('pages')->slug('minikurs')->published(true)->data(['title' => 'Minikurs']))->save();

        $this->asEditor()->getJson($this->url("type=entry&ref_id={$live->id()}", [['entry', $live->id()]]))->assertOk()->assertJsonPath('blocks.0.kind', 'facts');
    }

    #[Test]
    public function a_provider_without_a_say_on_who_may_read_is_refused(): void
    {
        app(MapObjectRegistry::class)->register(RichUnguardedMapObjects::class);

        $this->asAdmin()->getJson($this->url('type=rich&ref_id=x', [['rich', 'x']]))->assertForbidden()->assertJson(['restricted' => true]);
    }
}
