<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Models\LandkarteEdge;
use Goldnead\StatamicFunnels\Models\LandkarteRef;
use Goldnead\StatamicFunnels\Models\LandkarteStage;
use Illuminate\Support\Facades\DB;

/**
 * The Landkarte as a graph: nodes on phase lanes, edges between them.
 *
 * A node is a `type` and an `id` stored here; label, link, switch, number and
 * broken references are asked of the addon that owns the object, every time.
 * Edges are asked of the owners too (`ProvidesMapEdges`) and only put together
 * here. What a person draws by hand is the only thing this addon stores about
 * a connection, and a derived edge is never stored: it is true because a field
 * somewhere says so, and it disappears when the field does.
 *
 * Nothing on the graph is counted that the owners cannot count. Nodes carry
 * the number their addon gives. Edges carry none: how many people walked a
 * connection needs tracking that does not exist yet, and a figure made up to
 * fill the slot would be worse than the empty slot.
 */
class LandkarteGraph
{
    public const MAIN = 'main';

    /** @var array<string, string>|null */
    protected ?array $types = null;

    /** @var array<string, MapLookup> One answer per object per build: a card, its edges and a bridge all ask. */
    protected array $lookups = [];

    public function __construct(protected MapObjectRegistry $registry) {}

    /**
     * Types a person may pin by hand. Steps of a funnel's graph are not objects
     * of a Landkarte, the funnel is.
     *
     * @return array<string, string> type => label
     */
    public function pickableTypes(): array
    {
        return array_diff_key($this->types(), ['funnel_step' => true]);
    }

    /**
     * The lanes a new Landkarte starts with.
     *
     * @return list<array{name: string, purpose: string}>
     */
    public function defaultLanes(): array
    {
        return collect(['entry', 'freebie', 'course', 'offer', 'after'])
            ->map(fn (string $key) => [
                'name' => __("statamic-funnels::messages.lane_{$key}"),
                'purpose' => __("statamic-funnels::messages.lane_{$key}_purpose"),
            ])
            ->all();
    }

    /**
     * @param  array{back: string, name?: string}|null  $drill  where "back to the Landkarte" leads
     * @return array<string, mixed>
     */
    public function build(Landkarte $landkarte, MapPeriod $period, ?array $drill = null): array
    {
        $landkarte->loadMissing(['stages.refs', 'edges']);
        $this->lookups = [];

        $lanes = [];
        $nodes = [];

        foreach ($landkarte->stages->values() as $laneIndex => $stage) {
            $lanes[] = [
                'id' => $stage->id,
                'name' => $stage->name,
                'purpose' => $stage->purpose,
                'tone' => $laneIndex % 5,
            ];

            foreach ($stage->refs->values() as $row => $ref) {
                $key = $ref->type.':'.$ref->ref_id;

                // One object once per Landkarte: the first lane that holds it keeps it.
                if (isset($nodes[$key])) {
                    continue;
                }

                $nodes[$key] = [
                    ...$this->card($ref->type, $ref->ref_id, $period),
                    'key' => $key,
                    'lane' => $laneIndex,
                    'row' => $row,
                    'ghost' => false,
                ];
            }
        }

        $edges = $this->edges($landkarte, $nodes, $period);
        $mainPath = $this->markMainPath($nodes, $edges);

        foreach ($nodes as $key => $node) {
            $nodes[$key]['drill_url'] = $this->drillUrl($node, $landkarte, $drill);
        }

        $problems = collect($nodes)->sum(fn ($n) => ($n['status'] === MapLookup::MISSING ? 1 : 0) + count($n['problems'] ?? []));

        return [
            'lanes' => $lanes,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'main_path' => $mainPath,
            'problem_count' => $problems,
        ];
    }

    /**
     * A fingerprint of what is stored. A save made on an older picture is
     * refused: the save replaces the whole layout, so two open tabs would
     * otherwise quietly erase each other.
     */
    public function version(Landkarte $landkarte): string
    {
        return md5(json_encode([
            $landkarte->name,
            $landkarte->stages()->with('refs')->get()->map(fn (LandkarteStage $stage) => [
                $stage->id,
                $stage->name,
                $stage->purpose,
                $stage->refs->map(fn ($ref) => [$ref->type, $ref->ref_id])->all(),
            ])->all(),
            $landkarte->edges()->orderBy('id')->get()->map(fn ($e) => [$e->from_type, $e->from_ref, $e->to_type, $e->to_ref, $e->label])->all(),
        ]));
    }

    /**
     * Replace the Landkarte's lanes, their cards and the hand-drawn edges with
     * the submitted layout, all or nothing. False, and nothing written, when the
     * stored map is no longer the one `$version` describes.
     *
     * @param  list<array{id?: int|null, name: string, purpose?: string|null, refs?: list<array{type: string, ref_id: string}>}>  $stages
     * @param  list<array{from_type: string, from_ref: string, to_type: string, to_ref: string, label?: string|null}>  $edges
     */
    public function save(Landkarte $landkarte, string $name, array $stages, array $edges, ?string $version = null): bool
    {
        return DB::transaction(function () use ($landkarte, $name, $stages, $edges, $version) {
            // Checked inside the transaction, on the row locked for it: two
            // saves that both passed an outside check would overwrite each other.
            Landkarte::query()->whereKey($landkarte->id)->lockForUpdate()->first();

            if ($version !== null && ! hash_equals($this->version($landkarte->fresh()), $version)) {
                return false;
            }

            $landkarte->update(['name' => $name]);

            $existing = $landkarte->stages()->get()->keyBy('id');
            $kept = [];
            $placed = [];

            foreach (array_values($stages) as $position => $row) {
                $attributes = [
                    'name' => $row['name'],
                    'purpose' => ($row['purpose'] ?? '') !== '' ? $row['purpose'] : null,
                    'position' => $position,
                ];

                $stage = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if ($stage) {
                    $stage->update($attributes);
                } else {
                    $stage = $landkarte->stages()->create($attributes);
                }

                $kept[] = $stage->id;
                $stage->refs()->delete();

                $order = 0;

                foreach ($row['refs'] ?? [] as $ref) {
                    $key = $ref['type']."\0".$ref['ref_id'];

                    // One object once per Landkarte, not once per lane.
                    if (isset($placed[$key])) {
                        continue;
                    }

                    $placed[$key] = true;
                    $stage->refs()->create(['type' => $ref['type'], 'ref_id' => $ref['ref_id'], 'position' => $order++]);
                }
            }

            $gone = $landkarte->stages()->whereNotIn('id', $kept)->pluck('id');
            LandkarteRef::query()->whereIn('stage_id', $gone)->delete();
            LandkarteStage::query()->whereIn('id', $gone)->delete();

            LandkarteEdge::query()->where('landkarte_id', $landkarte->id)->delete();

            $seen = [];

            foreach ($edges as $edge) {
                $key = implode("\0", [$edge['from_type'], $edge['from_ref'], $edge['to_type'], $edge['to_ref']]);

                if (isset($seen[$key]) || ($edge['from_type'] === $edge['to_type'] && $edge['from_ref'] === $edge['to_ref'])) {
                    continue;
                }

                $seen[$key] = true;
                $landkarte->edges()->create([
                    'from_type' => $edge['from_type'],
                    'from_ref' => $edge['from_ref'],
                    'to_type' => $edge['to_type'],
                    'to_ref' => $edge['to_ref'],
                    'label' => ($edge['label'] ?? '') !== '' ? $edge['label'] : null,
                ]);
            }

            return true;
        });
    }

    /**
     * One node.
     *
     * `status` is `found`, `missing` (the owner answered and there is no such
     * object: a broken reference to repair) or `unavailable` (no addon for the
     * type, or it failed: nothing is known).
     *
     * @return array<string, mixed>
     */
    public function card(string $type, string $id, MapPeriod $period): array
    {
        $lookup = $this->lookup($type, $id);

        $card = [
            'type' => $type,
            'type_label' => $this->types()[$type] ?? $type,
            'ref_id' => $id,
            'status' => $lookup->status,
            'label' => $id,
            'cp_url' => null,
            'enabled' => null,
            'detail' => null,
            'problems' => [],
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
        $card['problems'] = $object['problems'];

        $metric = $this->registry->metric($type, $id, $period)?->toArray();

        // The period is worded here, in the language of this screen, so every
        // node carries the same words whichever addon counted.
        $card['metric'] = $metric ? [...$metric, 'period' => __('statamic-funnels::messages.map_period_label', ['days' => $period->days])] : null;

        return $card;
    }

    /**
     * Derived edges between placed nodes, hand-drawn ones, and the edges that
     * point at something that is gone.
     *
     * @param  array<string, array<string, mixed>>  $nodes  by key, extended with ghost nodes
     * @return array<string, array<string, mixed>>
     */
    protected function edges(Landkarte $landkarte, array &$nodes, MapPeriod $period): array
    {
        $derived = $this->registry->edges();
        $edges = [];

        foreach ($derived as $edge) {
            $from = $edge->fromType.':'.$edge->fromId;
            $to = $edge->toType.':'.$edge->toId;
            $fromPlaced = isset($nodes[$from]);
            $toPlaced = isset($nodes[$to]);

            if ($fromPlaced && ! $toPlaced) {
                $ghost = $this->ghostFor($edge->toType, $edge->toId, $edge->toLabel, $nodes[$from]);

                if ($ghost) {
                    $nodes[$to] = $ghost;
                    $toPlaced = true;
                }
            } elseif ($toPlaced && ! $fromPlaced) {
                $ghost = $this->ghostFor($edge->fromType, $edge->fromId, null, $nodes[$to]);

                if ($ghost) {
                    $nodes[$from] = $ghost;
                    $fromPlaced = true;
                }
            }

            if ($fromPlaced && $toPlaced && $from !== $to) {
                $edges[$edge->key()] = $this->edgeRow($edge->key(), $from, $to, $edge->kind, $edge->label, $edge->derivedFrom, $edge->dashed, false, null);
            }
        }

        foreach ($this->bridged($derived, $nodes, $edges, $period) as $key => $row) {
            $edges[$key] ??= $row;
        }

        $pairs = collect($edges)->mapWithKeys(fn ($e) => [$e['source'].'>'.$e['target'] => true]);

        foreach ($landkarte->edges as $hand) {
            $from = $hand->from_type.':'.$hand->from_ref;
            $to = $hand->to_type.':'.$hand->to_ref;

            // A hand-drawn edge between two nodes that are not both here is not
            // drawn: it is stored, and comes back when the objects do.
            if (! isset($nodes[$from], $nodes[$to]) || isset($pairs[$from.'>'.$to])) {
                continue;
            }

            $edges['hand:'.$hand->id] = $this->edgeRow('hand:'.$hand->id, $from, $to, 'hand', $hand->label ?: __('statamic-funnels::messages.edge_hand_label'), __('statamic-funnels::messages.edge_hand_origin'), true, true, $hand->id);
        }

        return $edges;
    }

    /**
     * A line between two placed nodes that two derived edges make through a
     * third one that is not on the map: funnel, offer, product becomes funnel,
     * product "via offer". Without it, leaving the offer off the map would
     * cut the road to the product.
     *
     * @param  array<string, MapEdge>  $derived
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     * @return array<string, array<string, mixed>>
     */
    protected function bridged(array $derived, array $nodes, array $edges, MapPeriod $period): array
    {
        $incoming = [];

        foreach ($derived as $edge) {
            $from = $edge->fromType.':'.$edge->fromId;
            $to = $edge->toType.':'.$edge->toId;

            if (isset($nodes[$from]) && ! isset($nodes[$to])) {
                $incoming[$to][] = $edge;
            }
        }

        $pairs = collect($edges)->mapWithKeys(fn ($e) => [$e['source'].'>'.$e['target'] => true]);
        $bridged = [];

        foreach ($derived as $edge) {
            $from = $edge->fromType.':'.$edge->fromId;
            $to = $edge->toType.':'.$edge->toId;

            if (isset($nodes[$from]) || ! isset($nodes[$to]) || ! isset($incoming[$from])) {
                continue;
            }

            $via = $this->lookup($edge->fromType, $edge->fromId);
            $name = $via->status === MapLookup::FOUND && $via->object !== null ? $via->object->label : $edge->fromId;

            foreach ($incoming[$from] as $first) {
                $source = $first->fromType.':'.$first->fromId;

                if ($source === $to || isset($pairs[$source.'>'.$to])) {
                    continue;
                }

                $key = "via:{$first->key()}|{$edge->key()}";
                $bridged[$key] = $this->edgeRow(
                    $key,
                    $source,
                    $to,
                    'via',
                    __('statamic-funnels::messages.edge_via', ['name' => $name]),
                    "{$first->derivedFrom}; {$edge->derivedFrom}",
                    $first->dashed || $edge->dashed,
                    false,
                    null,
                );
            }
        }

        return $bridged;
    }

    /**
     * A node for something an edge points at that its owner no longer knows.
     * Only when the owner answered "no such object": an addon that is not
     * installed is a choice, not a broken reference, and gets no red box.
     *
     * @param  array<string, mixed>  $near  the placed node it hangs off
     * @return array<string, mixed>|null
     */
    protected function ghostFor(string $type, string $id, ?string $written, array $near): ?array
    {
        if ($this->lookup($type, $id)->status !== MapLookup::MISSING) {
            return null;
        }

        return [
            'type' => $type,
            'type_label' => $this->types()[$type] ?? $type,
            'ref_id' => $id,
            'status' => MapLookup::MISSING,
            'label' => ($written !== null && $written !== '') ? $written : $id,
            'cp_url' => null,
            'enabled' => null,
            'detail' => null,
            'problems' => [],
            'metric' => null,
            'key' => $type.':'.$id,
            'lane' => $near['lane'],
            // The row is settled by the page: below the last card of the lane.
            'row' => null,
            'ghost' => true,
            'drill_url' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function edgeRow(string $id, string $source, string $target, string $kind, string $label, string $derivedFrom, bool $dashed, bool $hand, ?int $handId): array
    {
        return [
            'id' => $id,
            'source' => $source,
            'target' => $target,
            'kind' => $kind,
            'label' => $label,
            'derived_from' => $derivedFrom,
            'dashed' => $dashed,
            'hand' => $hand,
            'hand_id' => $handId,
            'main' => false,
            'bad' => false,
        ];
    }

    /**
     * The main path: from where people enter to the product that earned money.
     *
     * Entry nodes are those nothing leads to and those in the first lane. The goal is the product with the
     * most paid orders in the period (the number its own addon gives); the path
     * is the shortest walk along the edges from any entry to it. With no
     * product that has orders, or none that can be reached, there is no main
     * path and the answer says which of the two it was: it is not drawn from
     * guesswork.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     * @return array{status: string, node_keys: list<string>, goal: string|null}
     */
    protected function markMainPath(array &$nodes, array &$edges): array
    {
        $goal = null;
        $best = 0;

        foreach ($nodes as $key => $node) {
            $value = $node['status'] === MapLookup::FOUND && $node['type'] === 'product' ? ($node['metric']['value'] ?? 0) : 0;

            if ($value > $best) {
                $best = $value;
                $goal = $key;
            }
        }

        if ($goal === null) {
            return ['status' => 'no_revenue', 'node_keys' => [], 'goal' => null];
        }

        $out = [];
        $hasIncoming = [];

        foreach ($edges as $id => $edge) {
            $out[$edge['source']][] = [$edge['target'], $id];
            $hasIncoming[$edge['target']] = true;
        }

        // Where people enter: what nothing leads to, and whatever stands in the
        // first lane, which is the entry phase. The second matters when the
        // connections run in a circle (a funnel that hands out a lead magnet
        // whose automation mails the funnel), where no node is a root.
        $real = array_filter($nodes, fn ($n) => ! $n['ghost']);
        $firstLane = $real === [] ? null : min(array_column($real, 'lane'));
        $entries = array_values(array_filter(
            array_keys($real),
            fn ($k) => ! isset($hasIncoming[$k]) || $real[$k]['lane'] === $firstLane,
        ));

        // A goal that other entries lead to is reached from them, not from itself.
        if (isset($hasIncoming[$goal]) && count($entries) > 1) {
            $entries = array_values(array_filter($entries, fn ($k) => $k !== $goal));
        }

        // Breadth first from every entry at once: the first to reach the goal is the shortest.
        $queue = [];
        $came = [];

        foreach ($entries as $entry) {
            $queue[] = $entry;
            $came[$entry] = null;
        }

        for ($i = 0; $i < count($queue); $i++) {
            $at = $queue[$i];

            if ($at === $goal) {
                break;
            }

            foreach ($out[$at] ?? [] as [$next, $edgeId]) {
                if (! array_key_exists($next, $came)) {
                    $came[$next] = [$at, $edgeId];
                    $queue[] = $next;
                }
            }
        }

        // Nothing leads to the product at all: it is its own entry, not the end of a walk.
        if (! array_key_exists($goal, $came) || $came[$goal] === null) {
            return ['status' => 'unreachable', 'node_keys' => [], 'goal' => $goal];
        }

        $path = [$goal];

        for ($at = $goal; $came[$at] !== null; $at = $came[$at][0]) {
            $edges[$came[$at][1]]['main'] = true;
            array_unshift($path, $came[$at][0]);
        }

        return ['status' => 'found', 'node_keys' => $path, 'goal' => $goal];
    }

    /**
     * Where a double click on a checkout or an automation goes: its own editor,
     * carrying the way back, so the editor can say "Landkarte X > Kasse Y" and
     * lead back.
     *
     * @param  array<string, mixed>  $node
     * @param  array{back: string}|null  $drill
     */
    protected function drillUrl(array $node, Landkarte $landkarte, ?array $drill): ?string
    {
        if (! $drill || $node['status'] !== MapLookup::FOUND || ! $node['cp_url'] || ! in_array($node['type'], ['funnel', 'automation'], true)) {
            return null;
        }

        return $node['cp_url'].(str_contains($node['cp_url'], '?') ? '&' : '?').http_build_query([
            'lk' => $landkarte->id,
            'lkn' => $landkarte->name,
            'lkt' => $node['label'],
            'lkb' => $drill['back'],
            'lkm' => __('statamic-funnels::messages.map_return_map'),
            'lkz' => __('statamic-funnels::messages.map_return_back'),
        ]);
    }

    protected function lookup(string $type, string $id): MapLookup
    {
        return $this->lookups[$type."\0".$id] ??= $this->registry->lookup($type, $id);
    }

    /** @return array<string, string> */
    protected function types(): array
    {
        return $this->types ??= $this->registry->types();
    }
}
