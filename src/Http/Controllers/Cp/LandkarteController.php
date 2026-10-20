<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use DomainException;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Models\LandkarteRef;
use Goldnead\StatamicFunnels\Support\LandkarteGraph;
use Goldnead\StatamicFunnels\Support\Setup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use InvalidArgumentException;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The Landkarten in the Control Panel: the strategic picture of how pages,
 * lead magnets, automations, courses, checkouts and products work together over
 * weeks.
 *
 * Next to the funnel editor, not inside it. The editor draws what one visitor
 * does in one visit, in minutes; a Landkarte draws what a business is made of,
 * and a double click on a checkout or an automation opens that editor with the
 * way back to here.
 */
class LandkarteController extends CpController
{
    /** Periods on offer. One for the whole map: two nodes over different spans may not be compared. */
    public const PERIODS = [7, 30, 90];

    public function __construct(protected LandkarteGraph $graph, protected MapObjectRegistry $registry) {}

    public function index()
    {
        $this->authorizeAccess();

        if ($setup = Setup::guard(__('statamic-funnels::messages.landkarten_title'), 'funnel_landkarten', 'funnel_landkarte_stages', 'funnel_landkarte_refs', 'funnel_landkarte_edges')) {
            return $setup;
        }

        return Inertia::render('statamic-funnels::Landkarten/Index', [
            'landkarten' => Landkarte::query()->withCount('stages')->orderBy('name')->get()->map(fn (Landkarte $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'stages_count' => $k->stages_count,
                'url' => cp_route('statamic-funnels.landkarten.show', $k->id),
                'destroy_url' => cp_route('statamic-funnels.landkarten.destroy', $k->id),
            ])->all(),
            'storeUrl' => cp_route('statamic-funnels.landkarten.store'),
            'labels' => $this->labels(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        $landkarte = Landkarte::query()->create(['name' => trim($data['name'])]);

        foreach ($this->graph->defaultLanes() as $position => $lane) {
            $landkarte->stages()->create([...$lane, 'position' => $position]);
        }

        return redirect(cp_route('statamic-funnels.landkarten.show', $landkarte->id));
    }

    public function show(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        if ($setup = Setup::guard(__('statamic-funnels::messages.landkarten_title'), 'funnel_landkarten', 'funnel_landkarte_stages', 'funnel_landkarte_refs', 'funnel_landkarte_edges')) {
            return $setup;
        }

        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $period = MapPeriod::lastDays($days);
        $showUrl = cp_route('statamic-funnels.landkarten.show', $landkarte->id);

        $built = $this->graph->build($landkarte, $period, ['back' => $showUrl]);

        return Inertia::render('statamic-funnels::Landkarten/Show', [
            'landkarte' => ['id' => $landkarte->id, 'name' => $landkarte->name],
            'lanes' => $built['lanes'],
            'nodes' => $built['nodes'],
            'edges' => $built['edges'],
            'mainPath' => $built['main_path'],
            'problemCount' => $built['problem_count'],
            'types' => collect($this->graph->pickableTypes())
                ->map(fn (string $label, string $type) => ['value' => $type, 'label' => $label])
                ->values()
                ->all(),
            'period' => [
                'days' => $days,
                'options' => collect(self::PERIODS)->map(fn (int $d) => [
                    'value' => $d,
                    'label' => __('statamic-funnels::messages.map_period', ['days' => $d]),
                ])->all(),
            ],
            'version' => $this->graph->version($landkarte),
            'saveUrl' => cp_route('statamic-funnels.landkarten.update', $landkarte->id),
            'objectsUrl' => cp_route('statamic-funnels.landkarten.objects', $landkarte->id),
            'connectionRules' => $this->graph->connectionRules(),
            'previewUrl' => cp_route('statamic-funnels.landkarten.connections.preview', $landkarte->id),
            'connectUrl' => cp_route('statamic-funnels.landkarten.connections.store', $landkarte->id),
            'showUrl' => $showUrl,
            'indexUrl' => cp_route('statamic-funnels.landkarten.index'),
            'labels' => $this->labels(),
        ]);
    }

    public function update(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'version' => ['required', 'string'],
            'name' => ['required', 'string', 'max:191'],
            'stages' => ['present', 'array', 'max:20'],
            'stages.*.id' => ['nullable', 'integer', 'distinct'],
            'stages.*.name' => ['required', 'string', 'max:191'],
            'stages.*.purpose' => ['nullable', 'string', 'max:191'],
            'stages.*.refs' => ['nullable', 'array', 'max:100'],
            'stages.*.refs.*.type' => ['required', 'string', 'max:64'],
            'stages.*.refs.*.ref_id' => ['required', 'string', 'max:191'],
            'edges' => ['present', 'array', 'max:300'],
            'edges.*.from_type' => ['required', 'string', 'max:64'],
            'edges.*.from_ref' => ['required', 'string', 'max:191'],
            'edges.*.to_type' => ['required', 'string', 'max:64'],
            'edges.*.to_ref' => ['required', 'string', 'max:191'],
            'edges.*.label' => ['nullable', 'string', 'max:191'],
        ]);

        // A lane id that is not this Landkarte's is a stale or forged request.
        // Treating it as a new lane would quietly duplicate; refusing is cheaper.
        $own = $landkarte->stages()->pluck('id')->all();

        foreach ($data['stages'] as $i => $row) {
            if (isset($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                return back()->withErrors(["stages.$i.id" => __('statamic-funnels::messages.map_stage_foreign')]);
            }
        }

        // Saved on an older picture than the stored one (another tab, another
        // person): refused inside the save, on the locked row, with the reason.
        if (! $this->graph->save($landkarte, trim($data['name']), $data['stages'], $data['edges'], $data['version'])) {
            return back()->withErrors(['stages' => __('statamic-funnels::messages.map_conflict')]);
        }

        return back();
    }

    public function destroy(Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $landkarte->delete();

        return redirect(cp_route('statamic-funnels.landkarten.index'));
    }

    /** What the picker lists: objects of one type, optionally narrowed by a search. */
    public function objects(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        abort_unless($this->registry->has($data['type']), 404);

        return response()->json([
            'objects' => array_map(
                fn ($object) => $object->toArray(),
                $this->registry->objects($data['type'], $data['q'] ?? null, 50),
            ),
        ]);
    }

    /**
     * What a connection would do, in plain words. Writes nothing.
     */
    public function previewConnection(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $c = $this->connection($request, $landkarte, false);

        if ($c instanceof JsonResponse) {
            return $c;
        }

        try {
            $preview = $this->registry->previewConnection($c['connection'], $c['action']);
        } catch (AuthorizationException $e) {
            return $this->refuse(403, $e->getMessage(), 'map_connect_forbidden', 'Das darfst du nicht.');
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->refuse(422, $e->getMessage(), 'map_connect_impossible', 'Das lässt sich nicht verbinden.');
        }

        return response()->json(['kind' => $preview['kind'], 'consequence' => $preview['consequence']]);
    }

    /**
     * Connects or disconnects for real, in the addon that owns the field. The
     * undo of a connect is the same call with the opposite action.
     */
    public function connect(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $c = $this->connection($request, $landkarte, true);

        if ($c instanceof JsonResponse) {
            return $c;
        }

        try {
            $result = $this->registry->applyConnection($c['connection'], $c['action'], User::current());
        } catch (AuthorizationException $e) {
            return $this->refuse(403, $e->getMessage(), 'map_connect_forbidden', 'Das darfst du nicht.');
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->refuse(422, $e->getMessage(), 'map_connect_impossible', 'Das lässt sich nicht verbinden.');
        }

        return response()->json([
            'kind' => $result['kind'],
            'consequence' => $result['consequence'],
            'before' => $result['before'],
            'after' => $result['after'],
            'undo' => [
                'action' => $result['undo']['action'],
                'from_type' => $result['undo']['from_type'],
                'from_id' => $result['undo']['from_id'],
                'to_type' => $result['undo']['to_type'],
                'to_id' => $result['undo']['to_id'],
            ],
        ]);
    }

    /**
     * The request of both connection calls, checked: the contract is there, the
     * body is whole, (for a write) the picture is current and both ends are
     * objects of this map. Whatever is wrong comes back as the answer.
     *
     * @return JsonResponse|array{action: string, connection: array<string, string>}
     */
    protected function connection(Request $request, Landkarte $landkarte, bool $write): JsonResponse|array
    {
        if (! $this->graph->supportsConnections()) {
            return $this->refuse(422, '', 'map_connect_unsupported', 'Diese Version von Brand Context kann noch keine Verbindungen ziehen.');
        }

        $validator = Validator::make($request->all(), [
            'action' => ['required', 'in:connect,disconnect'],
            'from_type' => ['required', 'string', 'max:64'],
            'from_id' => ['required', 'string', 'max:191'],
            'to_type' => ['required', 'string', 'max:64'],
            'to_id' => ['required', 'string', 'max:191'],
            'version' => $write ? ['required', 'string'] : ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->refuse(422, $validator->errors()->first(), 'map_connect_impossible', 'Das lässt sich nicht verbinden.');
        }

        $data = $validator->validated();

        if ($write && ! hash_equals($this->graph->version($landkarte), $data['version'])) {
            return $this->refuse(409, '', 'map_conflict', 'Diese Karte wurde woanders geändert. Neu laden, damit nichts überschrieben wird.');
        }

        $stageIds = $landkarte->stages()->pluck('id');
        $onMap = fn (string $type, string $id) => LandkarteRef::query()->whereIn('stage_id', $stageIds)->where('type', $type)->where('ref_id', $id)->exists();

        if (! $onMap($data['from_type'], $data['from_id']) || ! $onMap($data['to_type'], $data['to_id'])) {
            return $this->refuse(422, '', 'map_connect_not_on_map', 'Beide Karten müssen auf dieser Landkarte liegen.');
        }

        return [
            'action' => $data['action'],
            'connection' => [
                'from_type' => $data['from_type'],
                'from_id' => $data['from_id'],
                'to_type' => $data['to_type'],
                'to_id' => $data['to_id'],
            ],
        ];
    }

    /** The reason of the owner when it gave one, otherwise this screen's own words. */
    protected function refuse(int $status, string $reason, string $key, string $fallback): JsonResponse
    {
        $own = Lang::has('statamic-funnels::messages.'.$key) ? __('statamic-funnels::messages.'.$key) : $fallback;

        return response()->json(['message' => $reason !== '' ? $reason : $own], $status);
    }

    /** Old bookmarks of the 1.25 map of one funnel land on the Landkarten. */
    public function legacy()
    {
        $this->authorizeAccess();

        return redirect(cp_route('statamic-funnels.landkarten.index'));
    }

    /** 1.26 had the Landkarten under `utilities/funnels/maps`; those bookmarks land on the new address. */
    public function legacyIndex()
    {
        $this->authorizeAccess();

        return redirect(cp_route('statamic-funnels.landkarten.index'), 301);
    }

    public function legacyShow(Landkarte $landkarte)
    {
        $this->authorizeAccess();

        return redirect(cp_route('statamic-funnels.landkarten.show', $landkarte->id), 301);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access funnels utility'), 403);
    }

    /**
     * Every word the pages show, translated here: Statamic's JavaScript `__()`
     * does not know this addon's language file.
     *
     * @return array<string, string>
     */
    protected function labels(): array
    {
        $keys = [
            'landkarten_title', 'landkarten_description', 'landkarten_empty', 'landkarten_empty_help', 'landkarte_new', 'landkarte_name',
            'landkarte_name_placeholder', 'landkarte_create', 'landkarte_delete', 'landkarte_delete_confirm', 'landkarte_lanes_count', 'landkarte_open',
            'map_add_stage', 'map_stage_name', 'map_stage_name_placeholder', 'map_rename', 'map_remove_stage', 'map_remove_stage_confirm',
            'map_add_ref', 'map_pick_type', 'map_search', 'map_no_objects', 'map_remove_ref', 'map_move_left', 'map_move_right',
            'map_move_up', 'map_move_down', 'map_stage_left', 'map_stage_right', 'map_on', 'map_off', 'map_no_switch', 'map_broken',
            'map_broken_help', 'map_unavailable', 'map_unavailable_help', 'map_no_number', 'map_save', 'map_saved', 'map_unsaved',
            'map_period', 'map_open_in_cp', 'map_cancel', 'map_add', 'map_edit', 'map_done', 'map_purpose', 'map_purpose_placeholder',
            'map_help', 'map_help_click', 'map_legend_main', 'map_legend_wait', 'map_legend_problem', 'map_legend_hand', 'map_problem_one',
            'map_problem_many', 'map_dead_reference', 'map_open_editor',
            'map_open_inside', 'map_open_inside_funnel', 'map_open_inside_automation', 'map_double_click_hint', 'map_panel_status', 'map_panel_number', 'map_panel_rule', 'map_panel_connections',
            'map_panel_in', 'map_panel_out', 'map_panel_none', 'map_panel_problems', 'map_panel_close', 'map_edge_origin_derived',
            'map_edge_origin_hand', 'map_edge_number', 'map_edge_number_none', 'map_main_path_found', 'map_main_path_no_revenue',
            'map_main_path_unreachable', 'map_main_path_title', 'map_draw_edge', 'map_draw_edge_from', 'map_draw_edge_to', 'map_draw_edge_add',
            'map_remove_edge', 'map_edge_hand_label', 'map_edge_label', 'map_ghost_help',
            'map_conflict', 'map_conflict_banner', 'map_reload', 'map_saving', 'map_saved_state', 'map_not_saved', 'map_save_failed',
            'map_note_edge', 'map_note_edge_from', 'map_note_edge_help', 'map_edge_fixed_help', 'map_edge_note_label',
            'map_edge_origin_note', 'map_legend_real', 'map_legend_note', 'map_grip_hint', 'map_connect_gone', 'map_connect_none',
            'map_connect_pair', 'map_connect_allowed', 'map_connect_forbidden', 'map_connect_failed', 'map_connect_title',
            'map_disconnect_title', 'map_connect_do', 'map_disconnect_do', 'map_connected', 'map_disconnected', 'map_undo', 'map_undone',
            'map_disconnect',
        ];

        return collect($keys)
            ->mapWithKeys(fn (string $key) => [$key => __('statamic-funnels::messages.'.$key)])
            ->all();
    }
}
