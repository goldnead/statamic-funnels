<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\FunnelMap;
use Goldnead\StatamicFunnels\Support\Setup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The map of a funnel in the Control Panel: phases as columns, references to
 * objects in other addons as cards.
 *
 * Beside the editor, not inside it. The editor draws the path one visitor walks
 * in a session; the map draws what a funnel is made of across days and
 * addons, and the two do not share a shape.
 */
class MapController extends CpController
{
    /** Periods on offer. One for the whole map: two cards over different spans may not be compared. */
    public const PERIODS = [7, 30, 90];

    public function __construct(protected FunnelMap $map, protected MapObjectRegistry $registry) {}

    public function show(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        if ($setup = Setup::guard(__('statamic-funnels::messages.map_title'), 'funnels', 'funnel_steps', 'funnel_stages', 'funnel_stage_refs')) {
            return $setup;
        }

        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $period = MapPeriod::lastDays($days);

        return Inertia::render('statamic-funnels::Funnels/Map', [
            'funnel' => [
                'id' => $funnel->id,
                'handle' => $funnel->handle,
                'title' => $funnel->title,
                'published' => (bool) $funnel->published,
            ],
            ...$this->map->build($funnel, $period),
            'types' => collect($this->map->pickableTypes())
                ->map(fn (string $label, string $type) => ['value' => $type, 'label' => $label])
                ->values()
                ->all(),
            'period' => [
                'days' => $days,
                'label' => $period->label(),
                'options' => collect(self::PERIODS)->map(fn (int $d) => [
                    'value' => $d,
                    'label' => __('statamic-funnels::messages.map_period', ['days' => $d]),
                ])->all(),
            ],
            'version' => $this->map->version($funnel),
            'saveUrl' => cp_route('utilities.funnels.map.update', $funnel->id),
            'objectsUrl' => cp_route('utilities.funnels.map.objects', $funnel->id),
            'mapUrl' => cp_route('utilities.funnels.map', $funnel->id),
            'editUrl' => cp_route('utilities.funnels.edit', $funnel->id),
            'indexUrl' => cp_route('utilities.funnels'),
            'labels' => $this->labels(),
        ]);
    }

    public function update(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'version' => ['required', 'string'],
            'stages' => ['present', 'array', 'max:20'],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.name' => ['required', 'string', 'max:191'],
            'stages.*.refs' => ['nullable', 'array', 'max:100'],
            'stages.*.refs.*.type' => ['required', 'string', 'max:64'],
            'stages.*.refs.*.ref_id' => ['required', 'string', 'max:191'],
        ]);

        // Saved on an older picture than the stored one: another tab or another
        // person changed the map since this page loaded. Refuse, say why.
        if (! hash_equals($this->map->version($funnel), $data['version'])) {
            return back()->withErrors(['stages' => __('statamic-funnels::messages.map_conflict')]);
        }

        // A phase id that is not this funnel's is a stale or forged request.
        // Treating it as a new phase would quietly duplicate; refusing is cheaper.
        $own = $funnel->stages()->pluck('id')->all();

        foreach ($data['stages'] as $i => $row) {
            if (isset($row['id']) && ! in_array((int) $row['id'], $own, true)) {
                return back()->withErrors(["stages.$i.id" => __('statamic-funnels::messages.map_stage_foreign')]);
            }
        }

        $this->map->save($funnel, $data['stages']);

        return back();
    }

    /** What the picker lists: objects of one type, optionally narrowed by a search. */
    public function objects(Request $request, Funnel $funnel)
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

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access funnels utility'), 403);
    }

    /**
     * Every word the page shows, translated here: Statamic's JavaScript `__()`
     * does not know this addon's language file.
     *
     * @return array<string, string>
     */
    protected function labels(): array
    {
        $keys = [
            'title', 'path', 'path_help', 'stages', 'add_stage', 'stage_name', 'stage_name_placeholder',
            'rename', 'remove_stage', 'remove_stage_confirm', 'add_ref', 'pick_type', 'search',
            'no_objects', 'remove_ref', 'move_left', 'move_right', 'move_up', 'move_down', 'stage_left', 'stage_right',
            'on', 'off', 'no_switch', 'broken', 'broken_help', 'unavailable', 'unavailable_help',
            'no_number', 'empty', 'empty_help', 'save', 'saved', 'unsaved', 'editor', 'period',
            'open_in_cp', 'points_to', 'path_empty', 'hint_numbers', 'cancel', 'add',
        ];

        return collect($keys)
            ->mapWithKeys(fn (string $key) => [$key => __('statamic-funnels::messages.map_'.$key)])
            ->all();
    }
}
