<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\Facades\Form;
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
        ]);
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
            ],
            'ui' => [
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
