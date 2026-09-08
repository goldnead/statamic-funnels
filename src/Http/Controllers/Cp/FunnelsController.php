<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Goldnead\StatamicFunnels\Support\MailStats;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\Setup;
use Goldnead\StatamicFunnels\Support\StepOrder;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\Facades\Action;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
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
    public function __construct(
        protected StepRegistry $registry,
        protected GraphWriter $writer,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeAccess();

        // Three tables, because the two counts below join onto the funnels.
        // Offers and payments are not among them: the listing shows title,
        // status and the two numbers, and nothing from a sibling addon.
        if ($setup = Setup::guard(__('statamic-funnels::messages.utility_nav'), 'funnels', 'funnel_steps', 'funnel_visits')) {
            return $setup;
        }

        return Inertia::render('statamic-funnels::Funnels/Index', [
            'funnels' => Funnel::query()
                ->withCount(['steps', 'visits'])
                ->orderBy('title')
                ->get()
                ->map(fn (Funnel $funnel) => $this->row($funnel))
                ->all(),
            'columns' => $this->columns(),
            // Without an action URL the listing renders no checkboxes and no
            // bulk toolbar, which is the difference between this screen and the
            // Collections screen a user just came from.
            'actionUrl' => cp_route('utilities.funnels.actions'),
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
                    // With the thumbnail's URL folded in, when there is one.
                    'config' => Thumbnails::configForEditor($step),
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
            // Only for steps actually running a test. A funnel where every card
            // sprouted an A and a B would bury the one number that matters
            // under two that say the same thing.
            'splits' => StepStats::byVariant($funnel),
            // Was die Mail-Knoten getan haben: ausgeloest, zugestellt,
            // fehlgeschlagen. Aus der Auslieferungstabelle, nicht aus
            // Wegmarken — eine Mail ist keine Station.
            'mailStats' => MailStats::forFunnel($funnel),
            // Whether the cards can carry a picture of their page. `enabled`
            // without `available` is the one state the editor has to say
            // something about: the feature is on and the host cannot do it.
            'thumbnails' => [
                'enabled' => Thumbnails::enabled(),
                'available' => Thumbnails::available(),
            ],
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
            // Die Seitenauswahl: Statamics `entries`-Feldtyp, nicht eine
            // Combobox mit eigener Suchroute. Eintraege reisen nicht mit —
            // Suche, Auswahl-Stack und Titel holt der Feldtyp selbst ueber
            // die Kernrouten, wie in jedem anderen Publish-Formular auch.
            'entryField' => $this->entryField($funnel),
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

        // Ein Mail-Knoten hat keine Seite; das Iframe zeigt fuer ihn die
        // gerenderte Mail. Entschieden am Typ im Graphen, der gerade auf dem
        // Bildschirm ist — auch fuer einen Knoten, der noch nicht gespeichert
        // wurde.
        $type = collect($graph['nodes'])->firstWhere('node_key', $data['node_key'])['type'] ?? null;

        return response()->json([
            'token' => $token,
            'url' => route($type === 'mail' ? 'statamic-funnels.preview-mail' : 'statamic-funnels.preview', [
                'funnel' => $funnel->handle,
                'nodeKey' => $data['node_key'],
            ]).'?token='.$token,
            // The order the stepper walks. Worked out on the server because it
            // is the same walk the front end does, and two implementations of
            // "what comes next" is one too many.
            'order' => StepOrder::keys($graph['nodes'], $graph['edges']),
        ]);
    }

    /**
     * Die Seitenauswahl eines Schritts — Statamics eigener `entries`-Feldtyp.
     *
     * Adrian am 03.09.2026: „sollte nicht nur eine Combobox sein sondern die
     * ausfuehrliche Entry-Auswahl, die man in Statamic auch an anderer Stelle
     * gewohnt ist." Genau die gibt es fertig; nachgebaut wurde vorher eine
     * Combobox mit eigener Suchroute, die weder Auswahl-Stack noch
     * Collection-Filter noch Statusanzeige kannte.
     *
     * Gebaut wird ein Ein-Feld-Blueprint je Handle, den ein Schritt-Schema als
     * `entry` deklariert (heute `entry` und `variant_entry`), mit Beschriftung
     * und Hilfetext aus demselben Schema — der Panel zeigt also weiter die
     * Worte des Addons, nicht die des Feldtyps.
     *
     * `collections` traegt die eine Regel weiter, die der alte Picker
     * durchsetzte: nur Collections mit Route. Ein Schritt, der auf einen
     * routenlosen Eintrag zeigt, rendert zwar, ist aber keine Seite.
     *
     * Das Metadaten-Paket kommt je Knoten, weil der Feldtyp die Titel der
     * bereits gewaehlten Eintraege daraus liest. `''` ist der leere Fall: ein
     * Schritt, den der Editor gerade erst angelegt hat.
     *
     * @return array{blueprint: array<string, mixed>, meta: array<string, array<string, mixed>>}
     */
    protected function entryField(Funnel $funnel): array
    {
        $blueprint = $this->entryBlueprint();
        $handles = $blueprint->fields()->all()->keys()->all();

        $meta = ['' => $blueprint->fields()->preProcess()->meta()->all()];

        foreach ($funnel->steps as $step) {
            $values = collect($handles)
                ->mapWithKeys(fn (string $handle) => [$handle => $step->config[$handle] ?? null])
                ->all();

            $meta[$step->node_key] = $blueprint->fields()->addValues($values)->preProcess()->meta()->all();
        }

        return ['blueprint' => $blueprint->toPublishArray(), 'meta' => $meta];
    }

    /**
     * Jedes Feld, das irgendein Schritt-Typ als `entry` deklariert, nach Handle.
     *
     * Aus der Registry gelesen statt hier aufgezaehlt: ein Schritt-Typ, der
     * morgen ein drittes Seitenfeld mitbringt, bekommt die Auswahl ohne
     * Aenderung an dieser Datei.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function entryHandles(): array
    {
        return collect($this->registry->all())
            ->flatMap(fn (string $class) => $class::schema())
            ->filter(fn ($field) => is_array($field)
                && ($field['type'] ?? null) === 'entry'
                && is_string($field['handle'] ?? null))
            ->keyBy('handle')
            ->all();
    }

    protected function entryBlueprint(): \Statamic\Fields\Blueprint
    {
        $collections = Collection::all()
            ->filter(fn ($collection) => $collection->route(Site::default()->handle()) !== null)
            ->map(fn ($collection) => $collection->handle())
            ->values()
            ->all();

        // Ohne eine einzige Collection mit Route gibt es keine Seite, auf die
        // ein Schritt zeigen koennte — der alte Picker lieferte hier eine leere
        // Liste. Dem Feldtyp `collections: []` zu geben hiesse „alle", und beim
        // Vorladen der Spalten wirft er dann `Collection [] not found`. Also
        // gar kein Feld statt eines kaputten.
        if ($collections === []) {
            return Blueprint::makeFromFields([]);
        }

        return Blueprint::makeFromFields(
            collect($this->entryHandles())
                ->map(fn (array $field, string $handle) => array_filter([
                    'type' => 'entries',
                    'display' => $field['label'] ?? $handle,
                    'instructions' => $field['instructions'] ?? null,
                    'collections' => $collections,
                    'max_items' => 1,
                    // Eine Seite entsteht in ihrer Collection, nicht in einem
                    // Seitenpanel des Funnel-Editors.
                    'create' => false,
                ], fn ($value) => $value !== null))
                ->all()
        );
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

            // Genau ein Einstieg. Adrian am 03.09.2026: „Ich kann mehrere
            // Einstiege erstellen, das soll so nicht sein" — von ihm als Fehler
            // bestaetigt. Die Sperre steht hier und nicht nur im Editor, weil
            // ein zweiter Einstieg sonst still gewinnt: `Funnel::entryStep()`
            // nimmt `firstWhere('type', 'entry')`, also den erstbesten, und
            // welcher das ist, entscheidet die Zeilenreihenfolge. Der Funnel
            // liefe dann an einem Einstieg vorbei, den jemand sichtbar angelegt
            // hat, und niemand saehe warum.
            //
            // Null Einstiege bleiben erlaubt: ein Entwurf darf unfertig sein,
            // und das Veroeffentlichen prueft das ohnehin.
            'nodes' => ['array', function ($attribute, $value, $fail) {
                $entries = collect($value ?? [])->where('type', 'entry')->count();

                if ($entries > 1) {
                    $fail(__('statamic-funnels::messages.one_entry_only', ['count' => $entries]));
                }
            }],
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

    /**
     * Every word the editor shows, translated on the server.
     *
     * @return array<string, mixed>
     */
    protected function labels(): array
    {
        return [
            'kinds' => [
                // `unique` fuettert eine Weiche, die es in der geteilten
                // Bibliothek (flow-canvas, NodeLibrary) laengst gibt und die
                // hier nie gesetzt war: beim Anhaengen eines Schrittes fallen
                // die einmaligen Arten heraus, beim Ersetzen des Einstiegs
                // bleiben nur sie uebrig. Ohne das Feld bot der „+" mitten im
                // Flow einen zweiten Einstieg an — Adrians Befund F27.
                'entry' => ['label' => __('statamic-funnels::nodes.kind_entry'), 'plural' => __('statamic-funnels::nodes.kind_entry_plural'), 'replaceLabel' => __('statamic-funnels::nodes.replace_entry'), 'unique' => true],
                'page' => ['label' => __('statamic-funnels::nodes.kind_page'), 'plural' => __('statamic-funnels::nodes.kind_page_plural')],
                'offer' => ['label' => __('statamic-funnels::nodes.kind_offer'), 'plural' => __('statamic-funnels::nodes.kind_offer_plural')],
                'finish' => ['label' => __('statamic-funnels::nodes.kind_finish'), 'plural' => __('statamic-funnels::nodes.kind_finish_plural')],
                'mail' => ['label' => __('statamic-funnels::nodes.kind_mail'), 'plural' => __('statamic-funnels::nodes.kind_mail_plural')],
            ],
            // Die Ausgaenge eines Schritts im Inspector, und was man dort
            // anhaengen kann.
            'outputs' => [
                'heading' => __('statamic-funnels::nodes.outputs_heading'),
                'default' => __('statamic-funnels::nodes.output_default'),
                'accepted' => __('statamic-funnels::nodes.output_accepted'),
                'declined' => __('statamic-funnels::nodes.output_declined'),
                'attachMail' => __('statamic-funnels::nodes.attach_mail'),
                'attachStep' => __('statamic-funnels::nodes.attach_step'),
                'mailsHere' => __('statamic-funnels::nodes.mails_here'),
            ],
            'mail' => [
                'queued' => __('statamic-funnels::messages.mail_stats_queued'),
                'sent' => __('statamic-funnels::messages.mail_stats_sent'),
                'failed' => __('statamic-funnels::messages.mail_stats_failed'),
                'trigger' => __('statamic-funnels::nodes.mail_trigger'),
                'trigger_default' => __('statamic-funnels::nodes.mail_trigger_default'),
                'trigger_accepted' => __('statamic-funnels::nodes.mail_trigger_accepted'),
                'trigger_declined' => __('statamic-funnels::nodes.mail_trigger_declined'),
                'trigger_none' => __('statamic-funnels::nodes.mail_trigger_none'),
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
            // The words for the deadline's three kinds. Handles in a config
            // panel read as `rolling`, which is not a word anybody chose.
            'options' => [
                'none' => __('statamic-funnels::nodes.countdown_none'),
                'fixed' => __('statamic-funnels::nodes.countdown_fixed'),
                'rolling' => __('statamic-funnels::nodes.countdown_rolling'),
            ],
            'stats' => [
                'visits' => __('statamic-funnels::messages.stats_visits'),
                'continued' => __('statamic-funnels::messages.stats_continued'),
                'rate' => __('statamic-funnels::messages.stats_rate'),
                'split' => __('statamic-funnels::messages.stats_split'),
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
            'thumbnails' => [
                'hint' => __('statamic-funnels::messages.thumbnails_need_chromium'),
            ],
        ];
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Gate::allows('access funnels utility'), 403);
    }

    /**
     * The table's head.
     *
     * Labels are translated here rather than in the template because the
     * listing prints `column.label` as it receives it, and the field names have
     * to match the keys `row()` writes — client-side sorting reads
     * `row[column.field]`, so a column whose field is not a key on the row
     * sorts every row to the same place.
     *
     * @return list<array<string, mixed>>
     */
    protected function columns(): array
    {
        return [
            ['field' => 'title', 'label' => __('statamic-funnels::messages.column_title'), 'visible' => true, 'sortable' => true],
            ['field' => 'published', 'label' => __('statamic-funnels::messages.column_status'), 'visible' => true, 'sortable' => true],
            ['field' => 'steps_count', 'label' => __('statamic-funnels::messages.column_steps'), 'visible' => true, 'sortable' => true],
            ['field' => 'visits_count', 'label' => __('statamic-funnels::messages.column_visits'), 'visible' => true, 'sortable' => true],
        ];
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
            'public_url' => route('statamic-funnels.entry', $funnel->handle),
            // Handed over rather than fetched: the row menu would otherwise ask
            // the server the moment it is opened.
            'actions' => Action::for($funnel, []),
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
