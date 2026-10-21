<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Cp;

use DomainException;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\Simulation\PersonSources;
use Goldnead\BrandContext\Simulation\SimAction;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Models\LandkarteRef;
use Goldnead\StatamicFunnels\Support\LandkarteGraph;
use Goldnead\StatamicFunnels\Support\MailFrame;
use Goldnead\StatamicFunnels\Support\PagePreview;
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
use Throwable;

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
            'detailsUrl' => cp_route('statamic-funnels.landkarten.details', $landkarte->id),
            'connectionRules' => $this->graph->connectionRules(),
            'previewUrl' => cp_route('statamic-funnels.landkarten.connections.preview', $landkarte->id),
            'connectUrl' => cp_route('statamic-funnels.landkarten.connections.store', $landkarte->id),
            'probelaufUrl' => class_exists(Simulator::class) ? cp_route('statamic-funnels.landkarten.probelauf', $landkarte->id) : null,
            'probelaufMailUrl' => class_exists(Simulator::class) ? cp_route('statamic-funnels.landkarten.probelauf.mail', $landkarte->id) : null,
            'probelaufPageUrl' => class_exists(Simulator::class) ? cp_route('statamic-funnels.landkarten.probelauf.page', $landkarte->id) : null,
            // Only when a CRM (or anything else that knows real people) is there to copy a person from.
            'probelaufPersonsUrl' => class_exists(PersonSources::class) && ! app(PersonSources::class)->isEmpty() ? cp_route('statamic-funnels.landkarten.probelauf.persons', $landkarte->id) : null,
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
     * What a card's panel shows beyond the number: asked of the owning addon,
     * once, when the panel opens. Empty for an addon that has nothing to add.
     */
    public function details(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'ref_id' => ['required', 'string', 'max:191'],
            'days' => ['nullable', 'integer'],
        ]);

        abort_unless($this->registry->has($data['type']), 404);

        // Only what lies on this map: the endpoint is no way to read any id of any addon.
        abort_unless(
            LandkarteRef::query()
                ->where('type', $data['type'])
                ->where('ref_id', $data['ref_id'])
                ->whereHas('stage', fn ($stage) => $stage->where('landkarte_id', $landkarte->id))
                ->exists(),
            404,
        );

        // The owning addon decides with the right that guards the object in its own CP.
        if (! $this->registry->detailsAllowed($data['type'], $data['ref_id'], User::current())) {
            return response()->json(['blocks' => [], 'restricted' => true], 403);
        }

        $days = (int) ($data['days'] ?? 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        return response()->json(['blocks' => $this->registry->details($data['type'], $data['ref_id'], MapPeriod::lastDays($days)) ?? []]);
    }

    /**
     * The dry run ("Probelauf") of this map, planned from the graphs the owning addons describe. Reads,
     * never does: no mail, no run, no write. Each addon decides with its own right what the signed-in user
     * may see of it; what she may not see is named, not shown.
     *
     * Without a person it is the tree of every way (`roots`). With the test person (`person=test`) and a
     * start (`start=type:id:event`, one of `starts`) it is the one way she walks, in order, by day (`entries`).
     */
    public function probelauf(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        abort_unless(class_exists(Simulator::class), 404);

        $data = $request->validate([
            'person' => ['nullable', 'in:unknown,test'],
            'start' => ['nullable', 'string', 'max:300'],
            ...$this->personRules(),
        ]);

        $built = $this->graph->build($landkarte, MapPeriod::lastDays(30));
        [$nodes, $edges] = $this->probeInput($built);

        $simulator = new Simulator($this->registry);
        $person = $data['person'] ?? 'unknown';

        $starts = array_map(fn (array $s) => [
            ...$s,
            'key' => "{$s['type']}:{$s['id']}:{$s['event']}",
            'event_label' => Lang::has('statamic-funnels::messages.probe_event_'.$s['event']) ? __('statamic-funnels::messages.probe_event_'.$s['event']) : $s['event'],
        ], $simulator->starts($nodes, $edges));

        // The screen opens on "gets a lead magnet": the first start is a lead magnet when the map has one (the sort is stable).
        usort($starts, fn (array $a, array $b) => ($a['type'] === 'lead_magnet' ? 0 : 1) <=> ($b['type'] === 'lead_magnet' ? 0 : 1));

        if ($person === 'test') {
            $chosen = collect($starts)->firstWhere('key', $data['start'] ?? ($starts[0]['key'] ?? null));

            abort_if($chosen === null && ($data['start'] ?? null) !== null, 422);

            $who = $this->personFrom($data);
            $timeline = $chosen
                ? $simulator->timeline($nodes, $edges, ['type' => $chosen['type'], 'id' => $chosen['id'], 'event' => $chosen['event']], $who, User::current())
                : ['entries' => [], 'stats' => ['entries' => 0, 'days' => [], 'cut' => 0]];

            return response()->json([
                'person' => 'test',
                'starts' => $starts,
                'start' => $chosen['key'] ?? null,
                'entries' => $timeline['entries'],
                'stats' => $timeline['stats'],
                // What the page may offer the person to do or to have: the products and automations the map shows.
                'products' => collect($built['nodes'])->where('type', 'product')->reject(fn (array $n) => $n['ghost'] ?? false)->map(fn (array $n) => ['key' => 'product:'.$n['ref_id'], 'label' => $n['label']])->values()->all(),
                'automations' => collect($built['nodes'])->where('type', 'automation')->reject(fn (array $n) => $n['ghost'] ?? false)->map(fn (array $n) => ['id' => (string) $n['ref_id'], 'label' => $n['label']])->values()->all(),
                'options' => $this->pickerOptions($built),
                'state' => [
                    'unsubscribed' => ($who->traits['marketing.unsubscribed'] ?? false) === true,
                    'tags' => $who->traits['contact.tags'] ?? [],
                    'access' => $who->access,
                    'ran' => $who->ran,
                    'actions' => array_map(fn (SimAction $a) => $a->toArray(), $who->actions),
                ],
            ]);
        }

        $tree = $simulator->tree($nodes, $edges, SimPerson::unknown(), User::current());

        $unreached = [];

        foreach ($tree['stats']['unreached'] as $key) {
            [$type, $id] = explode(':', $key, 2);
            $card = collect($built['nodes'])->firstWhere('key', $key);
            $unreached[] = ['key' => $key, 'label' => $card['label'] ?? $id, 'type_label' => $card['type_label'] ?? $type];
        }

        return response()->json([
            'person' => 'unknown',
            'starts' => $starts,
            'roots' => $tree['roots'],
            'stats' => [...$tree['stats'], 'unreached' => $unreached],
        ]);
    }

    /**
     * What the person editor offers to choose from, taken from the map instead of typed by hand: the access she can hold
     * (every lead magnet, product and course on the map, by the name its card shows and the kind it is) and the tags
     * the map's conditions ask for (said by the automations on the map that can name them). Tags stay free to type: a
     * condition of an addon that cannot name its own is still a tag a person may have.
     *
     * @param  array<string, mixed>  $built  {@see LandkarteGraph::build()}
     * @return array{access: list<array{value: string, label: string, kind: string}>, tags: list<string>}
     */
    protected function pickerOptions(array $built): array
    {
        $nodes = collect($built['nodes'])->reject(fn (array $n) => $n['ghost'] ?? false);

        $access = $nodes
            ->whereIn('type', ['lead_magnet', 'product', 'course'])
            ->map(fn (array $n) => ['value' => (string) $n['ref_id'], 'label' => (string) $n['label'], 'kind' => (string) ($n['type_label'] ?? $n['type'])])
            ->unique('value')
            ->values()
            ->all();

        $tags = [];

        // The automations' own provider, when that addon is there and has the method (an older one has not).
        $automations = 'Goldnead\\StatamicAutomations\\Integrations\\Map\\AutomationMapObjects';
        $provider = class_exists($automations) ? app($automations) : null;

        if ($provider !== null && method_exists($provider, 'simulationTags')) {
            foreach ($nodes->where('type', 'automation') as $node) {
                try {
                    foreach ((array) $provider->simulationTags((string) $node['ref_id']) as $tag) {
                        if (is_string($tag) && $tag !== '') {
                            $tags[$tag] = $tag;
                        }
                    }
                } catch (Throwable $e) {
                    // A list of suggestions is not worth a failed page; the tags stay free to type.
                    report($e);
                }
            }
        }

        return ['access' => $access, 'tags' => array_slice(array_values($tags), 0, self::PERSON_TAGS)];
    }

    /**
     * A real person to copy the test person from, read only. `q` searches (the CRM finds her), `like` copies: what
     * every addon that knows her, and that this user may read, says (tags and traits, access, automations, whether
     * she asked not to be written to). Nothing is written and nothing is kept: the answer fills the form of the page,
     * and the way is planned from the form like any other person. No name or address is logged.
     */
    public function probelaufPersons(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        abort_unless(class_exists(PersonSources::class), 404);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'like' => ['nullable', 'string', 'max:200'],
        ]);

        $sources = app(PersonSources::class);

        if (($data['like'] ?? '') !== '') {
            $found = $sources->describe($data['like'], User::current());

            abort_if($found === null, 404);

            $who = $found['person'];
            $traits = $who->traits;
            $tags = is_array($traits['contact.tags'] ?? null) ? $traits['contact.tags'] : [];
            $unsubscribed = ($traits['marketing.unsubscribed'] ?? false) === true;
            unset($traits['contact.tags'], $traits['marketing.unsubscribed']);

            return response()->json([
                'tags' => $tags,
                'access' => $who->access,
                'ran' => $who->ran,
                'unsubscribed' => $unsubscribed,
                'traits' => $traits,
                // The sources that did not speak (no right, or a failure): a copy silent about a part reads as "none".
                'missing' => array_map(fn (string $id) => Lang::has('statamic-funnels::messages.probe_source_'.$id) ? __('statamic-funnels::messages.probe_source_'.$id) : $id, $found['missing']),
            ]);
        }

        $q = trim((string) ($data['q'] ?? ''));

        return response()->json(['persons' => mb_strlen($q) < 2 ? [] : $sources->search($q, User::current(), 8)]);
    }

    /**
     * What a request may say about the test person: lists of plain words, the traits as one object, the actions as a list.
     * Each may come as JSON (the page posts it) or, for a plain GET, as a comma list or a JSON string. Everything is capped
     * when it is read ({@see personFrom()}), to what the places that know real people hand over at most.
     *
     * @return array<string, list<string>>
     */
    protected function personRules(): array
    {
        return [
            'tags' => ['nullable'],
            'access' => ['nullable'],
            'ran' => ['nullable'],
            'actions' => ['nullable'],
            // Other things a condition can read (`contact.status`, custom fields).
            'traits' => ['nullable'],
            'unsubscribed' => ['nullable', 'boolean'],
        ];
    }

    /** What the places that know real people hand over at most: the dry run takes that much, so a copy never needs more. */
    public const PERSON_TAGS = 30;

    public const PERSON_ACCESS = 50;

    public const PERSON_RAN = 50;

    public const PERSON_TRAITS = 80;

    /**
     * The test person the request describes: her tags (`contact.tags`, what a condition reads), the access she holds, the
     * automations she has been through, other traits, and what she does after which mail. The person lives in the
     * request and in nobody's database: nothing about her is stored, and no real person is read here.
     *
     * @param  array<string, mixed>  $data  validated by {@see personRules()}
     */
    protected function personFrom(array $data): SimPerson
    {
        $words = function (mixed $list, int $max): array {
            $items = is_array($list) ? $list : explode(',', (string) $list);

            return collect($items)
                ->map(fn ($w) => is_scalar($w) ? mb_substr(trim((string) $w), 0, 64) : '')
                ->filter()
                ->unique()
                ->take($max)
                ->values()
                ->all();
        };
        $json = function (mixed $value): array {
            if (is_array($value)) {
                return $value;
            }

            if ($value === null || $value === '') {
                return [];
            }

            $decoded = json_decode((string) $value, true);

            abort_unless(is_array($decoded), 422);

            return $decoded;
        };

        $actions = [];

        foreach (array_slice($json($data['actions'] ?? null), 0, SimAction::MAX) as $row) {
            $action = is_array($row) ? SimAction::fromArray($row) : null;

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        $traits = [];

        foreach (array_slice($json($data['traits'] ?? null), 0, self::PERSON_TRAITS, true) as $key => $value) {
            $isWords = is_array($value) && count($value) <= self::PERSON_TAGS && array_filter($value, fn ($w) => ! is_scalar($w)) === [];

            if (is_string($key) && preg_match('/^[a-z0-9_.\-]{1,80}$/i', $key) && (is_scalar($value) || $isWords)) {
                $traits[$key] = is_array($value) ? array_values(array_map(fn ($w) => mb_substr((string) $w, 0, 200), $value)) : (is_string($value) ? mb_substr($value, 0, 500) : $value);
            }
        }

        $tags = $words($data['tags'] ?? null, self::PERSON_TAGS);

        if ($tags !== []) {
            $traits['contact.tags'] = $tags;
        }

        if (! empty($data['unsubscribed'])) {
            $traits['marketing.unsubscribed'] = true;
        }

        return SimPerson::known(
            $traits,
            $words($data['access'] ?? null, self::PERSON_ACCESS),
            $words($data['ran'] ?? null, self::PERSON_RAN),
            $actions,
        );
    }

    /**
     * The map as the planner reads it: the objects (without the ghosts of things that are gone) and the
     * connections between them.
     *
     * @param  array<string, mixed>  $built  {@see LandkarteGraph::build()}
     * @return array{0: list<array{type: string, id: string}>, 1: list<array{from_type: string, from_id: string, to_type: string, to_id: string, kind: string, label: string}>}
     */
    protected function probeInput(array $built): array
    {
        $nodes = collect($built['nodes'])
            ->reject(fn (array $n) => $n['ghost'] ?? false)
            ->map(fn (array $n) => ['type' => $n['type'], 'id' => (string) $n['ref_id']])
            ->values()
            ->all();

        $edges = collect($built['edges'])
            ->map(function (array $e) {
                [$fromType, $fromId] = explode(':', $e['source'], 2);
                [$toType, $toId] = explode(':', $e['target'], 2);

                return ['from_type' => $fromType, 'from_id' => $fromId, 'to_type' => $toType, 'to_id' => $toId, 'kind' => $e['kind'], 'label' => (string) ($e['label'] ?? '')];
            })
            ->all();

        return [$nodes, $edges];
    }

    /**
     * One mail of the test person's way, as she would read it. Looked at, not delivered: the owning addon
     * writes it for her, and the registry makes it safe (no script, no remote image, no live link) and
     * frames it for an iframe with an empty `sandbox`. The entry is named by its place in the way the server
     * plans itself, never by what the client says about it, and only a mail the user may see is written.
     */
    public function probelaufMail(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        abort_unless(class_exists(Simulator::class), 404);

        [$entry, $person, $type, $id] = $this->entryOnTheWay($request, $landkarte, 'mail');

        $mail = $this->registry->mailPreview($type, $id, $entry['meta'] ?? [], $person);

        abort_if($mail === null, 404);

        return response()->json([
            'entry' => $entry['id'],
            'subject' => $mail['subject'] !== '' ? $mail['subject'] : $entry['label'],
            'from' => $mail['from'],
            'html' => MailFrame::calm($mail['html'], __('statamic-funnels::messages.probe_mail_image_word')),
            'links' => $mail['links'],
            'blocked_images' => $mail['blocked_images'],
        ]);
    }

    /**
     * One page of the test person's way, as she would see it. Rendered on the server from the Statamic entry the way
     * names, never requested over the network, and on a request that is only a look: it is no visit, no step event, no
     * share-out of a split test, and no tracking code reaches the page (see {@see PagePreview}). The markup comes back
     * made safe, for an iframe with an empty `sandbox`; a page that cannot be built that way answers what it can say
     * instead (its address, the picture of a funnel step that shows it) and why.
     */
    public function probelaufPage(Request $request, Landkarte $landkarte)
    {
        $this->authorizeAccess();

        abort_unless(class_exists(Simulator::class), 404);

        [$entry] = $this->entryOnTheWay($request, $landkarte, 'page');

        $shown = (string) ($entry['meta']['entry'] ?? '');

        abort_if($shown === '', 404);

        return response()->json(['entry' => $entry['id'], ...app(PagePreview::class)->render($shown)]);
    }

    /**
     * The entry of the planned way a request names, and what it takes to hand it out: the person, and the type and id of
     * the object that owns it. The way is planned again here from the start and the person, so an entry is named by its
     * place in what the server plans, never by what the client says about it; the user must be allowed to see the owner.
     *
     * @return array{0: array<string, mixed>, 1: SimPerson, 2: string, 3: string}
     */
    protected function entryOnTheWay(Request $request, Landkarte $landkarte, string $kind): array
    {
        $data = $request->validate([
            'start' => ['required', 'string', 'max:300'],
            'entry' => ['required', 'string', 'regex:/^e\d{1,5}$/'],
            // What the client saw under that entry. The way is planned again for every request, so an entry's
            // place can move if the map changed in between: an entry under another name is not handed out.
            'label' => ['required', 'string', 'max:300'],
            ...$this->personRules(),
        ]);

        $built = $this->graph->build($landkarte, MapPeriod::lastDays(30));
        [$nodes, $edges] = $this->probeInput($built);
        $simulator = new Simulator($this->registry);

        $chosen = collect($simulator->starts($nodes, $edges))
            ->first(fn (array $s) => "{$s['type']}:{$s['id']}:{$s['event']}" === $data['start']);

        abort_if($chosen === null, 422);

        $person = $this->personFrom($data);
        $timeline = $simulator->timeline($nodes, $edges, ['type' => $chosen['type'], 'id' => $chosen['id'], 'event' => $chosen['event']], $person, User::current());
        $entry = collect($timeline['entries'])->firstWhere('id', $data['entry']);

        abort_if($entry === null || $entry['kind'] !== $kind || ! is_string($entry['owner']), 404);
        abort_if($entry['label'] !== $data['label'], 409);

        [$type, $id] = explode(':', $entry['owner'], 2);

        abort_unless($this->registry->simulationAllowed($type, $id, User::current()), 403);

        return [$entry, $person, $type, $id];
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
            $preview = $this->registry->previewConnection($c['connection'], $c['action'], User::current());
        } catch (AuthorizationException $e) {
            return $this->refuse(403, $e->getMessage(), 'map_connect_forbidden', 'Das darfst du nicht.');
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->refuse(422, $e->getMessage(), 'map_connect_impossible', 'Das lässt sich nicht verbinden.');
        }

        // `before` is what the user is about to confirm: the write compares against it.
        return response()->json([
            'kind' => $preview['kind'],
            'consequence' => $preview['consequence'],
            'before' => $preview['before'] ?? '',
        ]);
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
            $result = $this->registry->applyConnection($c['connection'], $c['action'], User::current(), $c['expected_before']);
        } catch (AuthorizationException $e) {
            return $this->refuse(403, $e->getMessage(), 'map_connect_forbidden', 'Das darfst du nicht.');
        } catch (InvalidArgumentException $e) {
            return $this->refuse(422, $e->getMessage(), 'map_connect_impossible', 'Das lässt sich nicht verbinden.');
        } catch (DomainException) {
            // The field is no longer what the user confirmed (ConnectionConflict) or the
            // owner refused on its own re-check right before writing: either way somebody
            // else got there first, and nothing was written.
            return response()->json(['message' => __('statamic-funnels::messages.map_connect_conflict')], 409);
        }

        $undo = [
            'action' => $result['undo']['action'],
            'from_type' => $result['undo']['from_type'],
            'from_id' => $result['undo']['from_id'],
            'to_type' => $result['undo']['to_type'],
            'to_id' => $result['undo']['to_id'],
        ];

        // What a disconnect removed travels to the client and back, so the undo restores exactly that.
        if (isset($result['undo']['restore']) && is_array($result['undo']['restore'])) {
            $undo['restore'] = $result['undo']['restore'];
        }

        return response()->json([
            'kind' => $result['kind'],
            'consequence' => $result['consequence'],
            'before' => $result['before'],
            'after' => $result['after'],
            'undo' => $undo,
        ]);
    }

    /**
     * The request of both connection calls, checked: the contract is there, the
     * body is whole, (for a write) the picture is current and both ends are
     * objects of this map. Whatever is wrong comes back as the answer.
     *
     * @return JsonResponse|array{action: string, connection: array<string, mixed>, expected_before: string|null}
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
            'expected_before' => ['nullable', 'string', 'max:1000'],
            'restore' => ['nullable', 'array'],
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

        $connection = [
            'from_type' => $data['from_type'],
            'from_id' => $data['from_id'],
            'to_type' => $data['to_type'],
            'to_id' => $data['to_id'],
        ];

        // Only an undo of a disconnect carries what the disconnect removed.
        if (is_array($data['restore'] ?? null)) {
            $connection['restore'] = $data['restore'];
        }

        return [
            'action' => $data['action'],
            'connection' => $connection,
            // An empty `before` reaches us as null (the request strips empty strings): a key that is
            // there still means "the field was empty when you looked".
            'expected_before' => array_key_exists('expected_before', $data) ? (string) $data['expected_before'] : null,
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
            'map_disconnect', 'map_connect_conflict', 'map_dead_stub_help', 'map_details_loading', 'map_details_failed', 'map_details_restricted', 'map_details_more',
            'probe_tab_map', 'probe_tab_probe', 'probe_title', 'probe_title_test', 'probe_help', 'probe_help_test', 'probe_loading', 'probe_failed', 'probe_empty', 'probe_summary',
            'probe_unreached', 'probe_expand', 'probe_collapse', 'probe_now', 'probe_day', 'probe_hours', 'probe_minutes', 'probe_unavailable',
            'probe_person_unknown', 'probe_person_test', 'probe_lens_label', 'probe_who_label', 'probe_who_missing', 'probe_who_test', 'probe_who_real', 'probe_who_search', 'probe_who_search_hint', 'probe_who_copied', 'probe_who_reset', 'probe_who_none', 'probe_who_read_only', 'probe_unsubscribed_label', 'probe_lens_account', 'probe_lens_pages', 'probe_account_empty', 'probe_account_access', 'probe_account_course', 'probe_account_open_unknown', 'probe_pages_empty', 'probe_pages_note', 'probe_why', 'probe_tags_label', 'probe_tags_placeholder', 'probe_access_label', 'probe_access_placeholder', 'probe_ran_label', 'probe_until_label', 'probe_until_all', 'probe_until_day', 'probe_standing', 'probe_standing_access', 'probe_standing_unsub', 'probe_action_title', 'probe_action_buy', 'probe_action_click', 'probe_action_unsub', 'probe_action_product', 'probe_action_link', 'probe_action_after', 'probe_action_days', 'probe_action_add', 'probe_action_remove', 'probe_action_no_products', 'probe_action_no_links', 'probe_action_help', 'probe_action_full', 'probe_kind_action', 'probe_lens_timeline', 'probe_lens_mailbox', 'probe_mail_from_none', 'probe_mailbox_empty', 'probe_mail_select', 'probe_mail_failed', 'probe_mail_none', 'probe_mail_loading', 'probe_mail_from', 'probe_mail_subject', 'probe_mail_images_one', 'probe_mail_images_many', 'probe_mail_links', 'probe_mail_links_help', 'probe_mail_frame', 'probe_person_label', 'probe_start_label', 'probe_start_none', 'probe_timeline_empty', 'probe_timeline_summary', 'probe_timeline_summary_one', 'probe_kind_decision', 'probe_merged', 'probe_kind_enter', 'probe_kind_mail', 'probe_kind_wait', 'probe_kind_access', 'probe_kind_page',
            'probe_kind_stop', 'probe_kind_unknown', 'probe_kind_fork', 'probe_kind_rejoin', 'probe_kind_denied', 'probe_kind_more', 'probe_kind_trait',
            'probe_event_requested', 'probe_event_purchased', 'probe_event_started', 'probe_event_entered', 'probe_event_granted', 'probe_badge', 'probe_adjust', 'probe_all_ways', 'probe_all_ways_plain', 'probe_back', 'probe_person_find', 'probe_sentence_requested', 'probe_sentence_purchased', 'probe_sentence_started', 'probe_sentence_entered', 'probe_sentence_granted', 'probe_sees', 'probe_pick_step', 'probe_why_title', 'probe_skip_to', 'probe_skip_reset', 'probe_count_mail_one', 'probe_count_mail_many', 'probe_count_access_one', 'probe_count_access_many', 'probe_count_page_one', 'probe_count_page_many', 'probe_unknown_foot', 'probe_and', 'probe_do_label', 'probe_do_opens', 'probe_do_clicks', 'probe_do_clicks_menu', 'probe_do_buys', 'probe_do_buys_named', 'probe_do_buys_many', 'probe_do_unsub', 'probe_do_when', 'probe_do_now', 'probe_do_after_one', 'probe_do_after_many', 'probe_do_no_products', 'probe_mail_image_word', 'probe_page_desktop', 'probe_page_phone', 'probe_page_device', 'probe_page_width', 'probe_page_links', 'probe_page_link_goal', 'probe_page_loading', 'probe_page_failed', 'probe_page_frame', 'probe_page_thumb_alt', 'probe_page_thumb_note', 'probe_page_unavailable_app_route', 'probe_page_unavailable_draft', 'probe_page_unavailable_gone', 'probe_page_unavailable_no_address', 'probe_page_unavailable_not_a_page', 'probe_page_unavailable_too_big', 'probe_page_unavailable_failed', 'probe_stack_title', 'probe_stack_help', 'probe_who_title', 'probe_who_test_option', 'probe_who_test_desc', 'probe_who_real_option', 'probe_who_real_desc', 'probe_traits_title', 'probe_traits_from_map', 'probe_traits_add', 'probe_traits_free', 'probe_access_title', 'probe_access_none', 'probe_path_title', 'probe_unsub_help', 'probe_reset', 'probe_done',
        ];

        return collect($keys)
            ->mapWithKeys(fn (string $key) => [$key => __('statamic-funnels::messages.'.$key)])
            ->all();
    }
}
