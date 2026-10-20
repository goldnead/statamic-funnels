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

/** What the panel of a card shows beyond the number: asked of the owning addon, once, when it opens. */
class LandkarteDetailsTest extends TestCase
{
    protected $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(MapObjectRegistry::class, 'details')) {
            $this->markTestSkipped('needs statamic-brand-context with MapObjectRegistry::details()');
        }

        Collection::make('pages')->routes('/{slug}')->save();
        app(MapObjectRegistry::class)->register(OldMapObjects::class);
    }

    protected function url(string $query): string
    {
        $landkarte = Landkarte::query()->create(['name' => 'CVT']);

        return "/cp/funnels/landkarten/{$landkarte->id}/details?{$query}";
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

        $response = $this->asAdmin()->getJson($this->url("type=entry&ref_id={$entry->id()}&days=30"))->assertOk();

        $this->assertSame('facts', $response->json('blocks.0.kind'));
    }

    #[Test]
    public function an_old_provider_and_an_unknown_type_do_not_break_the_panel(): void
    {
        $this->asAdmin()->getJson($this->url('type=old&ref_id=x'))->assertOk()->assertExactJson(['blocks' => []]);
        $this->asAdmin()->getJson($this->url('type=nope&ref_id=x'))->assertNotFound();
        $this->asAdmin()->getJson($this->url('type=entry'))->assertStatus(422);
    }
}
