<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Integrations\Map\FunnelStepMapObjects;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStage;
use Goldnead\StatamicFunnels\Models\FunnelStageRef;
use Illuminate\Support\Facades\DB;

/**
 * The map of one funnel: phases as columns, references as cards.
 *
 * A card is a `type` and an `id` stored here, and everything else on it is
 * asked of the addon that owns the object, every time the map is drawn. That
 * is the whole design: the map holds no copy of a label, a link or a number
 * that could go stale, and no logic that belongs to another addon.
 */
class FunnelMap
{
    /** @var array<string, string>|null */
    protected ?array $types = null;

    public function __construct(protected MapObjectRegistry $registry) {}

    /**
     * Types a person may pin by hand. Steps of the graph are pinned by the
     * graph itself, so they are not on offer.
     *
     * @return array<string, string> type => label
     */
    public function pickableTypes(): array
    {
        return array_diff_key($this->types(), ['funnel_step' => true]);
    }

    /**
     * @return array{path: list<array<string, mixed>>, stages: list<array<string, mixed>>}
     */
    public function build(Funnel $funnel, MapPeriod $period): array
    {
        $funnel->loadMissing(['steps', 'edges', 'stages.refs']);

        return [
            'path' => $this->path($funnel, $period),
            'stages' => $funnel->stages->map(fn (FunnelStage $stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'refs' => $stage->refs
                    ->map(fn ($ref) => $this->cardOf($funnel, $ref->type, $ref->ref_id, $period))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    /**
     * A fingerprint of what is stored. The page hands it back with a save, and a
     * save made on an older picture is refused: the save replaces the whole map,
     * so two open tabs would otherwise quietly erase each other.
     */
    public function version(Funnel $funnel): string
    {
        return md5(json_encode(
            $funnel->stages()->with('refs')->get()->map(fn (FunnelStage $stage) => [
                $stage->id,
                $stage->name,
                $stage->refs->map(fn ($ref) => [$ref->type, $ref->ref_id])->all(),
            ])->all()
        ));
    }

    /**
     * Replace the funnel's phases and their references with the submitted
     * layout, all or nothing.
     *
     * @param  list<array{id?: int|null, name: string, refs?: list<array{type: string, ref_id: string}>}>  $stages
     */
    public function save(Funnel $funnel, array $stages): void
    {
        DB::transaction(function () use ($funnel, $stages) {
            $existing = $funnel->stages()->get()->keyBy('id');
            $kept = [];

            foreach (array_values($stages) as $position => $row) {
                $stage = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if ($stage) {
                    $stage->update(['name' => $row['name'], 'position' => $position]);
                } else {
                    $stage = $funnel->stages()->create(['name' => $row['name'], 'position' => $position]);
                }

                $kept[] = $stage->id;

                $stage->refs()->delete();

                $seen = [];
                $order = 0;

                foreach ($row['refs'] ?? [] as $ref) {
                    $key = $ref['type']."\0".$ref['ref_id'];

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $stage->refs()->create(['type' => $ref['type'], 'ref_id' => $ref['ref_id'], 'position' => $order++]);
                }
            }

            // Removed phases take their cards with them, here and not only through
            // the foreign key: SQLite does not enforce it unless asked to.
            $gone = $funnel->stages()->whereNotIn('id', $kept)->pluck('id');
            FunnelStageRef::query()->whereIn('funnel_stage_id', $gone)->delete();
            FunnelStage::query()->whereIn('id', $gone)->delete();
        });
    }

    /**
     * A card on the board. A page has no number of its own, but when this
     * funnel has a step that shows it, the visits of that step are the honest
     * number for the page here, and the card says whose.
     *
     * @return array<string, mixed>
     */
    protected function cardOf(Funnel $funnel, string $type, string $id, MapPeriod $period): array
    {
        $card = $this->card($type, $id, $period);

        if ($type !== 'entry' || $card['status'] !== MapLookup::FOUND || $card['metric'] !== null) {
            return $card;
        }

        $step = $funnel->steps->first(fn ($s) => ($s->config['entry'] ?? null) === $id && $s->type !== 'mail');
        $metric = $step ? $this->registry->metric('funnel_step', $funnel->handle.':'.$step->node_key, $period) : null;

        if ($metric) {
            $card['metric'] = [
                'value' => $metric->value,
                'what' => __('statamic-funnels::messages.map_metric_visits_of', ['step' => $step->label ?: $step->node_key]),
                'period' => __('statamic-funnels::messages.map_period_label', ['days' => $period->days]),
            ];
        }

        return $card;
    }

    /**
     * One card.
     *
     * `status` is `found`, `missing` (the owner answered and there is no such
     * object: a broken reference to repair) or `unavailable` (no addon for the
     * type, or it failed: nothing is known). A missing object keeps its id on
     * the card so the person can see what it used to point at.
     *
     * @return array<string, mixed>
     */
    public function card(string $type, string $id, MapPeriod $period, bool $withMetric = true): array
    {
        $lookup = $this->registry->lookup($type, $id);

        $card = [
            'type' => $type,
            'type_label' => $this->types()[$type] ?? $type,
            'ref_id' => $id,
            'status' => $lookup->status,
            'label' => $id,
            'cp_url' => null,
            'enabled' => null,
            'detail' => null,
            'metric' => null,
        ];

        if ($lookup->status !== MapLookup::FOUND || ! $lookup->object) {
            return $card;
        }

        $object = $lookup->object->toArray();

        $card['label'] = $object['label'];
        $card['cp_url'] = $object['cp_url'];
        $card['enabled'] = $object['enabled'];
        $card['detail'] = $object['detail'];

        if ($withMetric) {
            $metric = $this->registry->metric($type, $id, $period)?->toArray();

            // The period is worded here, in the language of this screen, so
            // every card carries the same words whichever addon counted.
            $card['metric'] = $metric ? [...$metric, 'period' => __('statamic-funnels::messages.map_period_label', ['days' => $period->days])] : null;
        }

        return $card;
    }

    /**
     * The steps of the graph, in walking order, each with the object it points
     * at when it points at one.
     *
     * @return list<array<string, mixed>>
     */
    protected function path(Funnel $funnel, MapPeriod $period): array
    {
        $nodes = $funnel->steps->map(fn ($s) => ['node_key' => $s->node_key, 'type' => $s->type])->all();
        $edges = $funnel->edges->map(fn ($e) => [
            'from_node_key' => $e->from_node_key,
            'to_node_key' => $e->to_node_key,
            'from_output' => $e->from_output,
        ])->all();

        $cards = [];

        foreach (StepOrder::keys($nodes, $edges) as $key) {
            $step = $funnel->stepByKey($key);

            if (! $step) {
                continue;
            }

            $card = $this->card('funnel_step', $funnel->handle.':'.$key, $period);
            // On the path the funnel is the page's own, so the line says only what kind of step it is.
            $card['detail'] = FunnelStepMapObjects::kind($step->type);
            $card['target'] = $this->target($step->type, $step->config ?? []);

            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * What a step points at, as a card without a number: the number on the
     * step's own card is its visits, and a second figure beside it would be a
     * second thing nobody may compare.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    protected function target(string $stepType, array $config): ?array
    {
        $text = fn (string $key): ?string => is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : null;

        $pick = match (true) {
            $stepType === 'lead_magnet' && $text('resource') !== null => ['lead_magnet', $text('resource')],
            $stepType === 'offer' && $text('offer') !== null => ['offer', $text('offer')],
            $text('entry') !== null => ['entry', $text('entry')],
            default => null,
        };

        if (! $pick) {
            return null;
        }

        return $this->card($pick[0], $pick[1], MapPeriod::lastDays(1), false);
    }

    /** @return array<string, string> */
    protected function types(): array
    {
        return $this->types ??= $this->registry->types();
    }
}
