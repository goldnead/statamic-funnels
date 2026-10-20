<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use DomainException;
use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Models\LandkarteEdge;
use Goldnead\StatamicFunnels\Support\LandkarteGraph;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** What the test providers "store": the trigger field of each automation, and who may write. */
class ZiehenWorld
{
    /** @var array<string, string> automation handle => lead magnet handle that triggers it */
    public static array $trigger = [];

    public static bool $canSource = true;

    public static bool $canTarget = true;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $writes = [];

    /** @var list<array{from_type: string, to_type: string, kind: string, label: string}> */
    public static array $rules = [['from_type' => 'lead_magnet', 'to_type' => 'automation', 'kind' => 'triggers', 'label' => 'startet']];

    public static function reset(): void
    {
        self::$trigger = [];
        self::$canSource = true;
        self::$canTarget = true;
        self::$writes = [];
        self::$rules = [['from_type' => 'lead_magnet', 'to_type' => 'automation', 'kind' => 'triggers', 'label' => 'startet']];
    }
}

class ZiehenLeadMagnetObjects implements ProvidesMapObjects
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
        return [new MapObject('lead_magnet', 'minikurs', 'Minikurs', '/cp/lead-magnets/minikurs', true)];
    }

    public function find(string $id): ?MapObject
    {
        return collect($this->objects())->firstWhere('id', $id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }
}

/** Reads the `triggers` edge from its own field, and owns the rule and the write. */
class ZiehenAutomationObjects implements ProvidesMapEdges, ProvidesMapObjects
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
            new MapObject('automation', 'bonus', 'bonus', '/cp/automations/2', true),
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
        foreach (ZiehenWorld::$trigger as $automation => $leadMagnet) {
            if ($leadMagnet !== '') {
                yield new MapEdge('lead_magnet', $leadMagnet, 'automation', $automation, 'triggers', 'triggers', "trigger of {$automation}");
            }
        }

        yield new MapEdge('automation', 'welcome', 'automation', 'tippfehler', 'starts', 'starts', 'call_automation step', false, 'tippfehler');
    }

    public function connectionRules(): array
    {
        return ZiehenWorld::$rules;
    }

    public function connectionAuthorized(array $c, $user): bool
    {
        return ZiehenWorld::$canSource && ZiehenWorld::$canTarget;
    }

    public function connectionPreview(array $c, string $action): array
    {
        $current = ZiehenWorld::$trigger[$c['to_id']] ?? '';

        if ($action === 'connect') {
            if ($current !== '' && $current !== $c['from_id']) {
                throw new DomainException('Schon verbunden.');
            }

            return ['consequence' => "Ab jetzt startet {$c['to_id']}.", 'before' => $current === '' ? '-' : $current, 'after' => $c['from_id']];
        }

        if ($current !== $c['from_id']) {
            throw new DomainException('Nicht verbunden.');
        }

        return ['consequence' => "{$c['to_id']} startet nicht mehr.", 'before' => $current, 'after' => '-'];
    }

    public function connect(array $c): array
    {
        $preview = $this->connectionPreview($c, 'connect');
        ZiehenWorld::$trigger[$c['to_id']] = $c['from_id'];
        ZiehenWorld::$writes[] = ['connect', $c];

        return ['before' => $preview['before'], 'after' => $preview['after']];
    }

    public function disconnect(array $c): array
    {
        $preview = $this->connectionPreview($c, 'disconnect');
        ZiehenWorld::$trigger[$c['to_id']] = '';
        ZiehenWorld::$writes[] = ['disconnect', $c];

        return ['before' => $preview['before'], 'after' => $preview['after'], 'restore' => ['row' => 7, 'note' => 'kept']];
    }
}

/** Another addon that reads a `triggers` line of its own: not the owner of the rule. */
class ZiehenOfferObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public function type(): string
    {
        return 'offer';
    }

    public function typeLabel(): string
    {
        return 'Offer';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        yield new MapEdge('lead_magnet', 'minikurs', 'automation', 'bonus', 'triggers', 'triggers', 'something else wrote this');
    }
}

/** A brand-context that predates `MapEdge::$ownerType`: edges come back without an owner. */
class ZiehenOwnerlessRegistry extends MapObjectRegistry
{
    public function edges(): array
    {
        return array_map(
            fn (MapEdge $e) => new MapEdge($e->fromType, $e->fromId, $e->toType, $e->toId, $e->kind, $e->label, $e->derivedFrom, $e->dashed, $e->toLabel),
            parent::edges(),
        );
    }
}

/**
 * The connection endpoints of a Landkarte, end to end: the real route, the real
 * registry of brand-context, and a test provider that owns the field.
 */
class LandkarteZiehenTest extends TestCase
{
    protected $superuser = null;

    protected function setUp(): void
    {
        parent::setUp();

        ZiehenWorld::reset();
        $this->useRegistry(new MapObjectRegistry($this->app));
    }

    protected function useRegistry(MapObjectRegistry $registry): void
    {
        $registry->register(ZiehenLeadMagnetObjects::class);
        $registry->register(ZiehenAutomationObjects::class);
        $registry->register(ZiehenOfferObjects::class);
        $this->app->instance(MapObjectRegistry::class, $registry);
        $this->app->forgetInstance(LandkarteGraph::class);
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function landkarte(): Landkarte
    {
        $landkarte = Landkarte::create(['name' => 'CVT im Chor']);
        $stage = $landkarte->stages()->create(['name' => 'Phase 1', 'position' => 0]);
        $stage->refs()->create(['type' => 'lead_magnet', 'ref_id' => 'minikurs', 'position' => 0]);
        $stage->refs()->create(['type' => 'automation', 'ref_id' => 'welcome', 'position' => 1]);
        $stage->refs()->create(['type' => 'automation', 'ref_id' => 'bonus', 'position' => 2]);

        return $landkarte->fresh();
    }

    /** @return array<string, mixed> */
    protected function body(string $action = 'connect', array $over = []): array
    {
        return [
            'action' => $action,
            'from_type' => 'lead_magnet',
            'from_id' => 'minikurs',
            'to_type' => 'automation',
            'to_id' => 'welcome',
            ...$over,
        ];
    }

    protected function previewUrl(Landkarte $k): string
    {
        return "/cp/funnels/landkarten/{$k->id}/connections/preview";
    }

    protected function connectUrl(Landkarte $k): string
    {
        return "/cp/funnels/landkarten/{$k->id}/connections";
    }

    protected function version(Landkarte $k): string
    {
        return app(LandkarteGraph::class)->version($k);
    }

    /** @return array<string, mixed> */
    protected function props(Landkarte $k): array
    {
        $response = $this->actingAs($this->user())->get("/cp/funnels/landkarten/{$k->id}")->assertOk();
        preg_match('/data-page="(.*?)"/s', $response->getContent(), $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }

    #[Test]
    public function the_page_carries_the_rules_the_urls_and_the_ends_of_every_edge(): void
    {
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];
        $k = $this->landkarte();
        $props = $this->props($k);

        $this->assertSame([['from_type' => 'lead_magnet', 'to_type' => 'automation', 'kind' => 'triggers', 'label' => 'startet']], $props['connectionRules']);
        $this->assertSame($this->previewUrl($k), parse_url($props['previewUrl'], PHP_URL_PATH));
        $this->assertSame($this->connectUrl($k), parse_url($props['connectUrl'], PHP_URL_PATH));

        $triggers = collect($props['edges'])->firstWhere('kind', 'triggers');
        $this->assertSame(['lead_magnet', 'minikurs', 'automation', 'welcome'], [$triggers['from_type'], $triggers['from_id'], $triggers['to_type'], $triggers['to_id']]);
        $this->assertSame('automation', $triggers['owner_type']);
        $this->assertTrue($triggers['disconnectable']);
    }

    #[Test]
    public function only_a_real_edge_with_a_rule_between_two_placed_cards_can_be_loosened(): void
    {
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];
        $k = $this->landkarte();
        $k->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'lead_magnet', 'to_ref' => 'minikurs']);
        $edges = collect($this->props($k->fresh())['edges']);

        $hand = $edges->firstWhere('hand', true);
        $this->assertFalse($hand['disconnectable'], 'a note arrow is not a connection');
        $this->assertSame('automation', $hand['from_type']);

        $ghost = $edges->firstWhere('kind', 'starts');
        $this->assertFalse($ghost['disconnectable'], 'no rule, and the end is a ghost');

        ZiehenWorld::$rules = [];
        $this->assertFalse(collect($this->props($k->fresh())['edges'])->firstWhere('id', 'lead_magnet:minikurs>triggers>automation:welcome')['disconnectable'], 'no rule for the pair');
    }

    #[Test]
    public function a_triggers_line_another_provider_derived_gets_no_disconnect(): void
    {
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];
        $edges = collect($this->props($this->landkarte())['edges']);

        $own = $edges->firstWhere('id', 'lead_magnet:minikurs>triggers>automation:welcome');
        $foreign = $edges->firstWhere('id', 'lead_magnet:minikurs>triggers>automation:bonus');

        $this->assertTrue($own['disconnectable'], 'the rule owner derived it');
        $this->assertSame('offer', $foreign['owner_type']);
        $this->assertFalse($foreign['disconnectable'], 'same pair and kind, but the offer addon derived it, the automation addon owns the rule');
    }

    #[Test]
    public function a_brand_context_that_names_no_edge_owner_offers_no_disconnect(): void
    {
        $this->useRegistry(new ZiehenOwnerlessRegistry($this->app));
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];

        $triggers = collect($this->props($this->landkarte())['edges'])->firstWhere('kind', 'triggers');

        $this->assertNull($triggers['owner_type']);
        $this->assertFalse($triggers['disconnectable']);
    }

    #[Test]
    public function an_older_brand_context_without_the_contract_gives_no_rules_and_no_crash(): void
    {
        // Which brand-context is installed decides `method_exists`; the old path is
        // forced through the one place that asks it.
        $this->app->instance(LandkarteGraph::class, new class(app(MapObjectRegistry::class)) extends LandkarteGraph
        {
            public function supportsConnections(): bool
            {
                return false;
            }
        });
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];

        $k = $this->landkarte();
        $props = $this->props($k);

        $this->assertSame([], $props['connectionRules']);
        $this->assertFalse(collect($props['edges'])->firstWhere('kind', 'triggers')['disconnectable']);

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))->assertStatus(422);
        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function the_graph_asks_the_registry_by_method_not_by_version(): void
    {
        $this->assertTrue(app(LandkarteGraph::class)->supportsConnections());
    }

    #[Test]
    public function without_the_gate_both_endpoints_are_forbidden(): void
    {
        $k = $this->landkarte();
        $role = tap(Role::make('cp-only')->title('CP only')->addPermission('access cp'))->save();
        $nobody = tap(User::make()->email('nobody@example.com')->assignRole($role))->save();

        $this->actingAs($nobody)->postJson($this->previewUrl($k), $this->body())->assertForbidden();
        $this->actingAs($nobody)->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))->assertForbidden();
        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function a_preview_describes_the_consequence_hands_out_the_before_and_writes_nothing(): void
    {
        $k = $this->landkarte();

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())
            ->assertOk()
            ->assertExactJson(['kind' => 'triggers', 'consequence' => 'Ab jetzt startet welcome.', 'before' => '-']);

        $this->assertSame([], ZiehenWorld::$writes);
        $this->assertSame([], ZiehenWorld::$trigger);
        $this->assertSame($this->version($k), $this->version($k->fresh()));
    }

    #[Test]
    public function a_preview_refusal_is_a_422_with_the_reason(): void
    {
        $k = $this->landkarte();
        ZiehenWorld::$trigger = ['welcome' => 'etwas-anderes'];

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertStatus(422)->assertExactJson(['message' => 'Schon verbunden.']);

        ZiehenWorld::$trigger = [];
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('connect', ['to_type' => 'lead_magnet', 'to_id' => 'minikurs']))->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('wiggle'))->assertStatus(422);
    }

    #[Test]
    public function the_permission_for_source_and_target_is_asked_by_the_preview_and_by_the_write(): void
    {
        $k = $this->landkarte();
        $v = $this->version($k);

        foreach (['canSource', 'canTarget'] as $flag) {
            ZiehenWorld::reset();
            ZiehenWorld::$$flag = false;

            $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertForbidden()->assertJsonStructure(['message']);
            $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $v]))->assertForbidden()->assertJsonStructure(['message']);
            $this->assertSame([], ZiehenWorld::$writes, "no write when {$flag} is missing");
            $this->assertSame([], ZiehenWorld::$trigger);
        }
    }

    #[Test]
    public function connecting_writes_through_the_registry_and_returns_before_after_and_the_undo(): void
    {
        $k = $this->landkarte();
        $version = $this->version($k);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $version, 'expected_before' => '-']))
            ->assertOk()
            ->assertExactJson([
                'kind' => 'triggers',
                'consequence' => 'Ab jetzt startet welcome.',
                'before' => '-',
                'after' => 'minikurs',
                'undo' => ['action' => 'disconnect', 'from_type' => 'lead_magnet', 'from_id' => 'minikurs', 'to_type' => 'automation', 'to_id' => 'welcome'],
            ]);

        $this->assertSame(['welcome' => 'minikurs'], ZiehenWorld::$trigger);
        $this->assertSame($version, $this->version($k->fresh()), 'it changes the addon, not the map');
    }

    #[Test]
    public function a_disconnect_carries_what_it_removed_to_the_client_and_the_undo_puts_exactly_that_back(): void
    {
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];
        $k = $this->landkarte();

        $response = $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('disconnect', ['version' => $this->version($k)]))
            ->assertOk()
            ->assertJsonPath('undo.action', 'connect')
            ->assertJsonPath('undo.restore', ['row' => 7, 'note' => 'kept']);

        $this->assertSame(['welcome' => ''], ZiehenWorld::$trigger);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), [...$response->json('undo'), 'version' => $this->version($k)])
            ->assertOk()
            ->assertJsonPath('undo.action', 'disconnect');

        $this->assertSame(['welcome' => 'minikurs'], ZiehenWorld::$trigger);
        $this->assertSame(['disconnect', 'connect'], array_column(ZiehenWorld::$writes, 0));
        $this->assertSame(['row' => 7, 'note' => 'kept'], ZiehenWorld::$writes[1][1]['restore'], 'the registry hands the restore to connect()');
    }

    #[Test]
    public function a_stale_map_version_is_a_409_and_nothing_is_written(): void
    {
        $k = $this->landkarte();
        $stale = $this->version($k);
        $k->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'lead_magnet', 'to_ref' => 'minikurs']);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $stale]))
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function a_field_that_changed_after_the_preview_is_a_409_with_the_clear_message_and_nothing_is_written(): void
    {
        $k = $this->landkarte();
        $shown = $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertOk()->json('before');

        // Another tab, another user: the same field was filled in the meantime, with the same lead magnet.
        ZiehenWorld::$trigger = ['welcome' => 'minikurs'];

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k), 'expected_before' => $shown]))
            ->assertStatus(409)
            ->assertExactJson(['message' => __('statamic-funnels::messages.map_connect_conflict')]);

        $this->assertSame([], ZiehenWorld::$writes);
        $this->assertSame(['welcome' => 'minikurs'], ZiehenWorld::$trigger);
    }

    #[Test]
    public function an_empty_expected_before_is_still_compared(): void
    {
        $k = $this->landkarte();

        // The request strips "" to null; the key being there is what counts. The field says "-", not "".
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k), 'expected_before' => '']))
            ->assertStatus(409);

        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function the_owner_refusing_while_writing_is_a_409_not_a_422(): void
    {
        $k = $this->landkarte();
        ZiehenWorld::$trigger = ['welcome' => 'etwas-anderes'];

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))
            ->assertStatus(409)
            ->assertJsonPath('message', __('statamic-funnels::messages.map_connect_conflict'));

        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function the_conflict_message_exists_in_german_and_english(): void
    {
        $this->assertSame('Das hat sich inzwischen geändert (anderer Tab oder anderer Nutzer). Bitte neu laden.', __('statamic-funnels::messages.map_connect_conflict', [], 'de'));
        $this->assertSame('This has changed in the meantime (another tab or another user). Please reload.', __('statamic-funnels::messages.map_connect_conflict', [], 'en'));
    }

    #[Test]
    public function an_end_that_is_not_on_this_map_is_a_422(): void
    {
        $k = $this->landkarte();
        $other = Landkarte::create(['name' => 'Andere']);
        $other->stages()->create(['name' => 'P', 'position' => 0])->refs()->create(['type' => 'automation', 'ref_id' => 'drittens', 'position' => 0]);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['to_id' => 'drittens', 'version' => $this->version($k)]))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('connect', ['to_id' => 'drittens']))->assertStatus(422);
        $this->assertSame([], ZiehenWorld::$writes);
    }

    #[Test]
    public function a_pair_without_a_rule_is_a_422_and_writes_nothing(): void
    {
        $k = $this->landkarte();

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['to_type' => 'lead_magnet', 'to_id' => 'minikurs', 'version' => $this->version($k)]))->assertStatus(422);

        $this->assertSame([], ZiehenWorld::$writes);
        $this->assertSame(0, LandkarteEdge::count());
    }

    #[Test]
    public function the_controller_keeps_no_audit_of_its_own(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Http/Controllers/Cp/LandkarteController.php');

        $this->assertDoesNotMatchRegularExpression('/Log::|Activity|map_connection_audits|->email\(\)|->name\(\)/', $source, 'the audit is written by brand-context, by user id only');
    }

    #[Test]
    public function a_connect_leaves_exactly_one_audit_row_by_user_id_through_the_real_registry(): void
    {
        $k = $this->landkarte();
        $hasTable = Schema::hasTable('map_connection_audits');

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))->assertOk();

        if (! $hasTable) {
            $this->markTestSkipped('brand-context migration for map_connection_audits is not run in this test database (php artisan migrate).');
        }

        $rows = DB::table('map_connection_audits')->get();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $this->user()->id(), $rows[0]->user_id);
        $this->assertStringNotContainsString('studio@example.com', json_encode($rows[0]));
    }
}
