<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

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
        yield new MapEdge('lead_magnet', 'minikurs', 'automation', 'welcome', 'triggers', 'triggers', 'trigger of welcome');
        yield new MapEdge('automation', 'welcome', 'automation', 'tippfehler', 'starts', 'starts', 'call_automation step', false, 'tippfehler');
    }
}

/**
 * A registry that already speaks the connection contract, so the Landkarte is
 * tested against the contract and not against the published brand-context.
 */
class ZiehenRegistry extends MapObjectRegistry
{
    /** @var list<array{from_type: string, to_type: string, kind: string, label: string}> */
    public array $rules = [['from_type' => 'lead_magnet', 'to_type' => 'automation', 'kind' => 'triggers', 'label' => 'startet']];

    /** @var null|class-string<\Throwable> */
    public ?string $throws = null;

    /** @var list<array{0: array<string, string>, 1: string}> */
    public array $applied = [];

    /** @var list<array{0: array<string, string>, 1: string}> */
    public array $previewed = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function connectionRules(): array
    {
        return $this->rules;
    }

    public function ruleFor(string $fromType, string $toType): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule['from_type'] === $fromType && $rule['to_type'] === $toType) {
                return $rule;
            }
        }

        return null;
    }

    public function previewConnection(array $c, string $action): array
    {
        $this->previewed[] = [$c, $action];

        if (! $this->ruleFor($c['from_type'], $c['to_type'])) {
            throw new InvalidArgumentException('Unknown pair');
        }

        if ($this->throws === \DomainException::class) {
            throw new \DomainException('Schon verbunden.');
        }

        if ($this->throws === AuthorizationException::class) {
            throw new AuthorizationException('Nicht erlaubt.');
        }

        return ['consequence' => 'Ab jetzt startet welcome.', 'before' => '-', 'after' => 'welcome', 'kind' => 'triggers'];
    }

    public function applyConnection(array $c, string $action, $user): array
    {
        $preview = $this->previewConnection($c, $action);
        $this->applied[] = [$c, $action];

        return [
            'kind' => 'triggers',
            'consequence' => $preview['consequence'],
            'before' => $preview['before'],
            'after' => $preview['after'],
            'undo' => ['action' => $action === 'connect' ? 'disconnect' : 'connect', ...$c, 'kind' => 'triggers'],
        ];
    }
}

/** The connection endpoints of a Landkarte and the fields the page is fed for drawing. */
class LandkarteZiehenTest extends TestCase
{
    protected ZiehenRegistry $fake;

    protected $superuser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new ZiehenRegistry($this->app);
        $this->fake->register(ZiehenLeadMagnetObjects::class);
        $this->fake->register(ZiehenAutomationObjects::class);
        $this->app->instance(MapObjectRegistry::class, $this->fake);
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

        return $landkarte->fresh();
    }

    /** @return array<string, string> */
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
        $k = $this->landkarte();
        $props = $this->props($k);

        $this->assertSame([['from_type' => 'lead_magnet', 'to_type' => 'automation', 'kind' => 'triggers', 'label' => 'startet']], $props['connectionRules']);
        $this->assertSame($this->previewUrl($k), parse_url($props['previewUrl'], PHP_URL_PATH));
        $this->assertSame($this->connectUrl($k), parse_url($props['connectUrl'], PHP_URL_PATH));

        $triggers = collect($props['edges'])->firstWhere('kind', 'triggers');
        $this->assertSame(['lead_magnet', 'minikurs', 'automation', 'welcome'], [$triggers['from_type'], $triggers['from_id'], $triggers['to_type'], $triggers['to_id']]);
        $this->assertTrue($triggers['disconnectable']);
    }

    #[Test]
    public function only_a_real_edge_with_a_rule_between_two_placed_cards_can_be_loosened(): void
    {
        $k = $this->landkarte();
        $k->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'lead_magnet', 'to_ref' => 'minikurs']);
        $edges = collect($this->props($k->fresh())['edges']);

        $hand = $edges->firstWhere('hand', true);
        $this->assertFalse($hand['disconnectable'], 'a note arrow is not a connection');
        $this->assertSame('automation', $hand['from_type']);

        $ghost = $edges->firstWhere('kind', 'starts');
        $this->assertFalse($ghost['disconnectable'], 'no rule, and the end is a ghost');

        $this->fake->rules = [];
        $this->assertFalse(collect($this->props($k->fresh())['edges'])->firstWhere('kind', 'triggers')['disconnectable'], 'no rule for the pair');
    }

    #[Test]
    public function an_older_brand_context_without_the_contract_gives_no_rules_and_no_crash(): void
    {
        // Which brand-context is installed decides `method_exists`; the old path is
        // forced through the one place that asks it.
        $this->app->instance(LandkarteGraph::class, new class($this->fake) extends LandkarteGraph
        {
            public function supportsConnections(): bool
            {
                return false;
            }
        });

        $k = $this->landkarte();
        $props = $this->props($k);

        $this->assertSame([], $props['connectionRules']);
        $this->assertFalse(collect($props['edges'])->firstWhere('kind', 'triggers')['disconnectable']);

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))->assertStatus(422);
    }

    #[Test]
    public function without_the_gate_both_endpoints_are_forbidden(): void
    {
        $k = $this->landkarte();
        $role = tap(Role::make('cp-only')->title('CP only')->addPermission('access cp'))->save();
        $nobody = tap(User::make()->email('nobody@example.com')->assignRole($role))->save();

        $this->actingAs($nobody)->postJson($this->previewUrl($k), $this->body())->assertForbidden();
        $this->actingAs($nobody)->postJson($this->connectUrl($k), $this->body('connect', ['version' => $this->version($k)]))->assertForbidden();
        $this->assertSame([], $this->fake->applied);
    }

    #[Test]
    public function a_preview_describes_the_consequence_and_writes_nothing(): void
    {
        $k = $this->landkarte();

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())
            ->assertOk()
            ->assertExactJson(['kind' => 'triggers', 'consequence' => 'Ab jetzt startet welcome.']);

        $this->assertSame([], $this->fake->applied);
        $this->assertSame($this->version($k), $this->version($k->fresh()));
    }

    #[Test]
    public function a_preview_refusal_is_a_422_with_the_reason_and_a_registry_no_is_a_403(): void
    {
        $k = $this->landkarte();

        $this->fake->throws = \DomainException::class;
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertStatus(422)->assertExactJson(['message' => 'Schon verbunden.']);

        $this->fake->throws = AuthorizationException::class;
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body())->assertForbidden()->assertJsonStructure(['message']);

        $this->fake->throws = null;
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('connect', ['to_type' => 'lead_magnet', 'to_id' => 'minikurs']))->assertStatus(422)->assertJsonStructure(['message']);
        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('wiggle'))->assertStatus(422);
    }

    #[Test]
    public function connecting_returns_before_after_and_the_undo_and_leaves_the_version_alone(): void
    {
        $k = $this->landkarte();
        $version = $this->version($k);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $version]))
            ->assertOk()
            ->assertExactJson([
                'kind' => 'triggers',
                'consequence' => 'Ab jetzt startet welcome.',
                'before' => '-',
                'after' => 'welcome',
                'undo' => ['action' => 'disconnect', 'from_type' => 'lead_magnet', 'from_id' => 'minikurs', 'to_type' => 'automation', 'to_id' => 'welcome'],
            ]);

        $this->assertCount(1, $this->fake->applied);
        $this->assertSame('connect', $this->fake->applied[0][1]);
        $this->assertSame($version, $this->version($k->fresh()), 'it changes the addon, not the map');
    }

    #[Test]
    public function disconnecting_and_the_undo_body_go_through_the_same_endpoint(): void
    {
        $k = $this->landkarte();

        $response = $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('disconnect', ['version' => $this->version($k)]))
            ->assertOk()
            ->assertJsonPath('undo.action', 'connect');

        $this->actingAs($this->user())->postJson($this->connectUrl($k), [...$response->json('undo'), 'version' => $this->version($k)])
            ->assertOk()
            ->assertJsonPath('undo.action', 'disconnect');

        $this->assertSame(['disconnect', 'connect'], array_column($this->fake->applied, 1));
    }

    #[Test]
    public function a_stale_version_is_a_409_and_nothing_is_written(): void
    {
        $k = $this->landkarte();
        $stale = $this->version($k);
        $k->edges()->create(['from_type' => 'automation', 'from_ref' => 'welcome', 'to_type' => 'lead_magnet', 'to_ref' => 'minikurs']);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $stale]))
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertSame([], $this->fake->applied);
    }

    #[Test]
    public function an_end_that_is_not_on_this_map_is_a_422(): void
    {
        $k = $this->landkarte();
        $other = Landkarte::create(['name' => 'Andere']);
        $other->stages()->create(['name' => 'P', 'position' => 0])->refs()->create(['type' => 'automation', 'ref_id' => 'bonus', 'position' => 0]);

        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['to_id' => 'bonus', 'version' => $this->version($k)]))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->actingAs($this->user())->postJson($this->previewUrl($k), $this->body('connect', ['to_id' => 'bonus']))->assertStatus(422);
        $this->assertSame([], $this->fake->applied);
    }

    #[Test]
    public function a_refusal_or_a_no_while_writing_is_a_422_or_a_403(): void
    {
        $k = $this->landkarte();
        $v = $this->version($k);

        $this->fake->throws = \DomainException::class;
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $v]))->assertStatus(422)->assertExactJson(['message' => 'Schon verbunden.']);

        $this->fake->throws = AuthorizationException::class;
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['version' => $v]))->assertForbidden()->assertJsonStructure(['message']);

        $this->fake->throws = null;
        $this->actingAs($this->user())->postJson($this->connectUrl($k), $this->body('connect', ['to_type' => 'lead_magnet', 'to_id' => 'minikurs', 'version' => $v]))->assertStatus(422);
        $this->assertSame([], $this->fake->applied);
        $this->assertSame(0, LandkarteEdge::count());
    }
}
