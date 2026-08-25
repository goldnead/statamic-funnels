<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\StepOrder;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Funnels in the Control Panel: the list, and the editor.
 *
 * The editor is the shared canvas from `@goldnead/flow-canvas`, the same one the
 * automations editor is built on. Not a copy of it — the same files — which is
 * the only way two editors stay looking like one product.
 */
class FunnelsController extends CpController
{
    /** How many entries one picker request returns. */
    protected const ENTRY_LIMIT = 100;

    public function __construct(
        protected StepRegistry $registry,
        protected GraphWriter $writer,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeAccess();

        return Inertia::render('statamic-funnels::Funnels/Index', [
            'funnels' => Funnel::query()
                ->withCount(['steps', 'visits'])
                ->orderBy('title')
                ->get()
                ->map(fn (Funnel $funnel) => $this->row($funnel))
                ->all(),
            'createUrl' => cp_route('utilities.funnels.store'),
            'indexUrl' => cp_route('utilities.funnels'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
        ]);

        $funnel = Funnel::create([
            'title' => $data['title'],
            'handle' => $this->uniqueHandle($data['title']),
            'published' => false,
        ]);

        // A new funnel is not empty: it gets the one step every funnel must
        // have. An editor that opens onto nothing makes the first minute a
        // guessing game about what a funnel even is.
        $this->writer->seed($funnel);

        return redirect(cp_route('utilities.funnels.edit', $funnel->id));
    }

    public function edit(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        $funnel->load(['steps', 'edges']);

        return Inertia::render('statamic-funnels::Funnels/Edit', [
            'funnel' => [
                'id' => $funnel->id,
                'handle' => $funnel->handle,
                'title' => $funnel->title,
                'published' => $funnel->published,
                'nodes' => $funnel->steps->map(fn ($step) => [
                    'node_key' => $step->node_key,
                    'type' => $step->type,
                    'label' => $step->label,
                    'slug' => $step->slug,
                    'config' => $step->config ?? [],
                    'disabled' => $step->disabled,
                ])->values()->all(),
                'edges' => $funnel->edges->map(fn ($edge) => [
                    'from_node_key' => $edge->from_node_key,
                    'to_node_key' => $edge->to_node_key,
                    'from_output' => $edge->from_output,
                ])->values()->all(),
            ],
            'library' => $this->registry->library(),
            // Where people stop. Passed with the page so the canvas can show it
            // on the cards themselves: a drop-off number in a report somewhere
            // else is a number nobody looks at.
            'stats' => StepStats::forFunnel($funnel),
            // Translated here, not in the browser. Statamic's JavaScript `__()`
            // only knows core and application strings; an addon's language file
            // never reaches it, so a label written in JS renders as the raw key
            // — `statamic-funnels::nodes.kind_entry` in the middle of the
            // canvas. Everything a human reads therefore comes from PHP.
            'labels' => $this->labels(),
            'saveUrl' => cp_route('utilities.funnels.update', $funnel->id),
            'indexUrl' => cp_route('utilities.funnels'),
            'publicUrl' => route('statamic-funnels.entry', $funnel->handle),
            // What the config panel offers where a step asks for a form or an
            // offer. Sent with the page so the panel never has to fetch.
            'forms' => Form::all()->map(fn ($form) => [
                'value' => $form->handle(),
                'label' => $form->title(),
            ])->values()->all(),
            'offers' => Offer::query()->orderBy('name')->get()->map(fn (Offer $offer) => [
                'value' => $offer->handle,
                'label' => $offer->name.($offer->amount() ? ' · '.$offer->amount().' '.$offer->currency() : ''),
            ])->all(),
            // Entries are not sent whole. A site with five thousand pages would
            // put five thousand rows into the page payload for a field most
            // steps never use, so the picker searches instead — and gets the
            // ones already chosen up front, so a saved step shows a title
            // rather than an id.
            'entries' => $this->entryOptions(null, $funnel),
            'entriesUrl' => cp_route('utilities.funnels.entries'),
            'previewUrl' => cp_route('utilities.funnels.preview', $funnel->id),
            // The same device list the Control Panel's own Live Preview offers,
            // read from the same config key. A funnel preview that invented its
            // own three widths would disagree with the entry preview one screen
            // over, and the user would be right to trust neither.
            'devices' => collect(config('statamic.live_preview.devices', []))
                ->map(fn ($size, $name) => [
                    'name' => $name,
                    'width' => $size['width'] ?? null,
                    'height' => $size['height'] ?? null,
                ])->values()->all(),
        ]);
    }

    /**
     * Mint a pass and say where to point the iframe.
     *
     * The graph comes from the editor, not from the table, so the preview shows
     * what is on screen rather than what was last saved. That is the whole
     * point: a preview of the saved version answers a question nobody asked.
     */
    public function preview(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'node_key' => ['required', 'string', 'max:64'],
            // The pass from the last refresh, so this one overwrites it instead
            // of leaving another copy of the graph on disk.
            'token' => ['nullable', 'string', 'max:128'],
            'nodes' => ['array'],
            'nodes.*.node_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nodes.*.type' => ['required', 'string', 'max:64'],
            'nodes.*.label' => ['nullable', 'string', 'max:191'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.disabled' => ['nullable', 'boolean'],
            'edges' => ['array'],
            'edges.*.from_node_key' => ['required', 'string', 'max:64'],
            'edges.*.to_node_key' => ['required', 'string', 'max:64'],
            'edges.*.from_output' => ['nullable', 'string', 'max:64'],
        ]);

        $graph = ['nodes' => $data['nodes'] ?? [], 'edges' => $data['edges'] ?? []];

        $token = PreviewToken::mint($funnel, $graph, $data['token'] ?? null);

        return response()->json([
            'token' => $token,
            'url' => route('statamic-funnels.preview', [
                'funnel' => $funnel->handle,
                'nodeKey' => $data['node_key'],
            ]).'?token='.$token,
            // The order the stepper walks. Worked out on the server because it
            // is the same walk the front end does, and two implementations of
            // "what comes next" is one too many.
            'order' => StepOrder::keys($graph['nodes'], $graph['edges']),
        ]);
    }

    /** What the entry picker searches. */
    public function entries(Request $request)
    {
        $this->authorizeAccess();

        return response()->json([
            'options' => $this->entryOptions((string) $request->query('search', '')),
        ]);
    }

    /**
     * Entries as picker options.
     *
     * Only what a visitor could actually be shown: published entries in
     * collections that have a route. A step pointing at a routeless entry would
     * render, but nothing about it would be a page, and the picker offering it
     * is the kind of choice that only shows up as a bug later.
     *
     * @return list<array<string, string>>
     */
    protected function entryOptions(?string $search = null, ?Funnel $funnel = null): array
    {
        $collections = Collection::all()
            ->filter(fn ($collection) => $collection->route(Site::default()->handle()) !== null)
            ->map(fn ($collection) => $collection->handle())
            ->values();

        if ($collections->isEmpty()) {
            return [];
        }

        $query = EntryFacade::query()
            ->whereIn('collection', $collections->all())
            ->where('published', true);

        if ($search !== null && $search !== '') {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $entries = $query->orderBy('title')->limit(self::ENTRY_LIMIT)->get();

        // Whatever the funnel already points at, even if the search or the
        // limit would have left it out. Otherwise editing an unrelated step
        // silently blanks a picker that had a value.
        if ($funnel) {
            $chosen = $funnel->steps
                ->map(fn ($step) => $step->config['entry'] ?? null)
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->reject(fn ($id) => $entries->contains(fn ($entry) => $entry->id() === $id))
                ->map(fn ($id) => EntryFacade::find($id))
                ->filter();

            $entries = $entries->merge($chosen);
        }

        return $entries
            ->map(fn ($entry) => [
                'value' => (string) $entry->id(),
                'label' => (string) $entry->get('title', $entry->slug()),
                'group' => (string) ($entry->collection()->title() ?? $entry->collection()->handle()),
            ])
            ->sortBy(fn (array $option) => $option['group'].$option['label'])
            ->values()
            ->all();
    }

    public function update(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        // Every key the writer reads has to be listed, because `validate()`
        // returns *only* what it validated. Leave `label` and `config` out and
        // the graph saves with no labels and no configuration — the editor
        // looks like it worked and the funnel is empty.
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'handle' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('funnels', 'handle')->ignore($funnel->id)],
            'published' => ['boolean'],

            'nodes' => ['array'],
            'nodes.*.node_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nodes.*.type' => ['required', 'string', 'max:64'],
            'nodes.*.label' => ['nullable', 'string', 'max:191'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.disabled' => ['nullable', 'boolean'],

            'edges' => ['array'],
            'edges.*.from_node_key' => ['required', 'string', 'max:64'],
            'edges.*.to_node_key' => ['required', 'string', 'max:64'],
            'edges.*.from_output' => ['nullable', 'string', 'max:64'],
        ]);

        $this->writer->write($funnel, $data);

        return back()->with('message', __('statamic-funnels::messages.saved'));
    }

    public function destroy(Request $request, Funnel $funnel)
    {
        $this->authorizeAccess();

        $funnel->delete();

        return redirect(cp_route('utilities.funnels'));
    }

    /**
     * Every word the editor shows, translated on the server.
     *
     * @return array<string, mixed>
     */
    protected function labels(): array
    {
        return [
            'kinds' => [
                'entry' => ['label' => __('statamic-funnels::nodes.kind_entry'), 'plural' => __('statamic-funnels::nodes.kind_entry_plural'), 'replaceLabel' => __('statamic-funnels::nodes.replace_entry')],
                'page' => ['label' => __('statamic-funnels::nodes.kind_page'), 'plural' => __('statamic-funnels::nodes.kind_page_plural')],
                'offer' => ['label' => __('statamic-funnels::nodes.kind_offer'), 'plural' => __('statamic-funnels::nodes.kind_offer_plural')],
                'finish' => ['label' => __('statamic-funnels::nodes.kind_finish'), 'plural' => __('statamic-funnels::nodes.kind_finish_plural')],
            ],
            'adder' => [
                'root' => __('statamic-funnels::nodes.add_entry'),
                'step' => __('statamic-funnels::nodes.add_step'),
            ],
            'pick' => [
                'entry' => __('statamic-funnels::nodes.pick_entry'),
                'replaceEntry' => __('statamic-funnels::nodes.pick_replace_entry'),
                'step' => __('statamic-funnels::nodes.pick_step'),
            ],
            'fields' => [
                'label' => __('statamic-funnels::nodes.field_label'),
                'entryPlaceholder' => __('statamic-funnels::nodes.field_entry_placeholder'),
            ],
            'stats' => [
                'visits' => __('statamic-funnels::messages.stats_visits'),
                'continued' => __('statamic-funnels::messages.stats_continued'),
                'rate' => __('statamic-funnels::messages.stats_rate'),
            ],
            'preview' => [
                'previous' => __('statamic-funnels::messages.preview_previous'),
                'next' => __('statamic-funnels::messages.preview_next'),
                'steps' => __('statamic-funnels::messages.preview_steps'),
                'close' => __('statamic-funnels::messages.preview_close'),
                'loading' => __('statamic-funnels::messages.preview_loading'),
                'failed' => __('statamic-funnels::messages.preview_failed'),
                'retry' => __('statamic-funnels::messages.preview_retry'),
                'empty' => __('statamic-funnels::messages.preview_empty'),
                'frame' => __('statamic-funnels::messages.preview_frame'),
                'responsive' => __('statamic-funnels::messages.preview_responsive'),
            ],
            'ui' => [
                'preview' => __('statamic-funnels::messages.preview'),
                'draft' => __('statamic-funnels::messages.draft'),
                'live' => __('statamic-funnels::messages.live'),
                'handle' => __('statamic-funnels::messages.field_handle'),
                'published' => __('statamic-funnels::messages.field_published'),
                'undo' => __('Undo'),
                'redo' => __('Redo'),
                'save' => __('Save'),
            ],
        ];
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access funnels utility'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Funnel $funnel): array
    {
        return [
            'id' => $funnel->id,
            'handle' => $funnel->handle,
            'title' => $funnel->title,
            'published' => $funnel->published,
            'steps_count' => $funnel->steps_count,
            'visits_count' => $funnel->visits_count,
            'edit_url' => cp_route('utilities.funnels.edit', $funnel->id),
            'delete_url' => cp_route('utilities.funnels.destroy', $funnel->id),
            'public_url' => route('statamic-funnels.entry', $funnel->handle),
        ];
    }

    protected function uniqueHandle(string $title): string
    {
        $base = Str::slug($title) ?: 'funnel';
        $handle = $base;
        $i = 2;

        while (Funnel::where('handle', $handle)->exists()) {
            $handle = $base.'-'.$i++;
        }

        return $handle;
    }
}
