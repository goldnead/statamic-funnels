<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, Field, Input, Select, Textarea, Switch, Heading,
    PublishContainer, PublishFields, PublishFieldsProvider,
} from '@statamic/cms/ui';
import { Canvas, NodeLibrary, setNodeOutputSpecs, useHistory } from '@goldnead/flow-canvas';
import { nodeIcon, withLabels } from '../../support/nodeKinds.js';
import PreviewPanel from '../../components/PreviewPanel.vue';

/**
 * The funnel editor.
 *
 * The canvas, the node library, the adders, the auto-layout, the undo stack:
 * all of it is the same code the automations editor runs. Not a copy — the same
 * files, from `@goldnead/flow-canvas`. What this page adds is what a funnel
 * *means*: five kinds of step, and a panel to configure them.
 */
const props = defineProps({
    funnel: { type: Object, required: true },
    library: { type: Object, required: true },
    saveUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    publicUrl: { type: String, required: true },
    forms: { type: Array, default: () => [] },
    offers: { type: Array, default: () => [] },
    // `{ blueprint, meta }` fuer Statamics `entries`-Feldtyp. `meta` je
    // Knotenschluessel, `''` fuer einen Schritt, den es beim Laden noch nicht gab.
    entryField: { type: Object, default: null },
    previewUrl: { type: String, default: '' },
    devices: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    splits: { type: Object, default: () => ({}) },
    mailStats: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    // `{ enabled, available }`. On but not available is the one state worth a
    // word in the side panel: the host has no browser to photograph pages with.
    thumbnails: { type: Object, default: () => ({ enabled: false, available: false }) },
});

// Every word the editor shows comes from the server: Statamic's JavaScript
// `__()` does not know this addon's language file, and a label written in JS
// would render as its raw key across the canvas.
const KINDS = computed(() => withLabels(props.labels.kinds));

const t = (group, key, fallback = '') => props.labels?.[group]?.[key] ?? fallback;

// The output specs travel with the library payload and are evaluated by the
// shared canvas, so an offer draws two handles and a page draws one without
// this page knowing why.
setNodeOutputSpecs(props.library);

const graph = ref({
    title: props.funnel.title,
    handle: props.funnel.handle,
    published: props.funnel.published,
    nodes: JSON.parse(JSON.stringify(props.funnel.nodes)),
    edges: JSON.parse(JSON.stringify(props.funnel.edges)),
});

const selectedKey = ref(null);
const showLibrary = ref(true);
const pendingTarget = ref(null);
const saving = ref(false);
const showPreview = ref(false);

// The history reads and writes the graph through these two, so a restored
// snapshot lands where the canvas is looking. Called without them it destructures
// `undefined` and the page dies at setup — which is exactly what happened the
// first time this editor was opened.
const history = useHistory({
    getState: () => ({ nodes: graph.value.nodes, edges: graph.value.edges }),
    setState: (state) => {
        graph.value.nodes = state.nodes;
        graph.value.edges = state.edges;
        selectedKey.value = null;
    },
});

const selected = computed(() => graph.value.nodes.find((n) => n.node_key === selectedKey.value) ?? null);

const selectedType = computed(() => {
    if (!selected.value) return null;

    return Object.values(props.library).flat().find((m) => m.handle === selected.value.type) ?? null;
});

const pickKind = computed(() => {
    if (!pendingTarget.value) return 'step';

    return pendingTarget.value.kind === 'replace-entry'
        ? 'replace-entry'
        : (pendingTarget.value.fromNodeKey ? 'step' : 'entry');
});

/**
 * Die Bibliothek, wie sie angeboten werden darf.
 *
 * Ein Funnel hat genau einen Einstieg (von Adrian am 03.09.2026 bestaetigt).
 * Sobald einer im Graphen liegt, faellt die Einstiegs-Gruppe aus der Auswahl —
 * man kann keinen zweiten mehr anlegen, statt ihn anzulegen und beim Speichern
 * abgewiesen zu werden. Der Server prueft es trotzdem noch einmal: eine Sperre,
 * die nur im Browser steht, ist keine.
 *
 * Zwei Ausnahmen, beide notwendig:
 *
 * - Beim „Einstieg ersetzen" (`pickKind === 'replace-entry'`) muss die Gruppe
 *   sichtbar bleiben, sonst oeffnet sich eine leere Liste und der vorhandene
 *   Einstieg laesst sich nie mehr austauschen.
 * - Nachgeschlagen wird weiter in der vollstaendigen `props.library`
 *   (`selectedType`, `addNode`, `setNodeOutputSpecs`): der vorhandene
 *   Einstiegsknoten braucht seine Beschreibung und seine Ausgaenge.
 */
const hasEntry = computed(() => graph.value.nodes.some((n) => n.type === 'entry'));

const availableLibrary = computed(() => {
    if (!hasEntry.value || pickKind.value === 'replace-entry') return props.library;

    const offered = {};
    for (const [group, items] of Object.entries(props.library)) {
        const kept = items.filter((item) => item.kind !== 'entry');
        if (kept.length) offered[group] = kept;
    }

    return offered;
});

/**
 * Dieselbe Auswahl noch einmal, aber fuer die Gruppenkoepfe der Bibliothek.
 *
 * Die Bibliothek baut ihre Reiter aus `kinds`, nicht aus dem Bestand — ohne
 * diesen Schritt bliebe der Reiter „Einstieg" mit einer 0 daneben stehen und
 * fragte den Leser, warum dort nichts ist. Nur die Bibliothek bekommt die
 * gekuerzte Fassung; die Leinwand braucht weiter alle Bezeichnungen, sonst
 * verliert die vorhandene Einstiegskarte ihre Beschriftung.
 */
const libraryKinds = computed(() => {
    if (!hasEntry.value || pickKind.value === 'replace-entry') return KINDS.value;

    const kinds = { ...KINDS.value };
    delete kinds.entry;

    return kinds;
});

/**
 * Die Felder, die zum aktuellen Zustand des Schrittes passen.
 *
 * Ein Feld darf `visible_when: { <handle>: 'filled' }` mitbringen und
 * erscheint dann nur, wenn das genannte Feld einen Wert hat. Gebraucht wird das
 * bisher fuer genau eine Sache: die A/B-Variantenfelder haengen an
 * `split_share`, dem Schalter des Tests (Adrians Befund F25).
 *
 * Bewusst nur diese eine Bedingung. Ein Feld, dessen Sichtbarkeit von einer
 * kleinen Ausdruckssprache abhaengt, ist beim naechsten Lesen teurer als die
 * drei Zeilen, die sie sich spart.
 */
function fieldIsVisible(field) {
    const rules = field?.visible_when;
    if (!rules) return true;

    return Object.entries(rules).every(([handle, rule]) => {
        const value = selected.value?.config?.[handle];
        const filled = value !== null && value !== undefined && value !== '';

        return rule === 'filled' ? filled : !filled;
    });
}

const visibleFields = computed(() => (selectedType.value?.schema ?? []).filter(fieldIsVisible));

function record(tag = null) {
    history.record(tag);
}

function newKey(type) {
    return `${type}_${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Adding a node.
 *
 * The library emits a **handle**, not the entry — the same contract the
 * automations editor consumes. Treating it as an object produced nodes typed
 * `undefined`, which fell back to the ordinary kind, showed a blank card, and
 * then failed validation on save: the editor looked like it worked and stored
 * nothing.
 *
 * The edge is drawn immediately, from whichever "+" was armed. A node that
 * appears unconnected and has to be wired afterwards is how a graph editor
 * turns into a puzzle.
 */
function addNode(handle) {
    const entry = Object.values(props.library).flat().find((m) => m.handle === handle);

    if (!entry) return;

    record();

    const key = newKey(entry.handle);
    const config = {};

    // A field's default, where it declares one, otherwise null. A mail node
    // that saved with `delay_unit: null` would read as "no unit" rather than
    // "minutes", and the two are not the same thing to somebody reading it.
    (entry.schema ?? []).forEach((field) => { config[field.handle] = field.default ?? null; });

    graph.value.nodes.push({
        node_key: key,
        type: entry.handle,
        label: entry.label,
        slug: null,
        config,
        disabled: false,
    });

    const target = pendingTarget.value;

    if (target?.fromNodeKey) {
        graph.value.edges.push({
            from_node_key: target.fromNodeKey,
            to_node_key: key,
            from_output: target.output ?? 'default',
        });
    }

    // Picked from the "+" on an existing edge. Two meanings, decided by what
    // was picked: a **mail** hangs off the same output as a sibling and the
    // edge stays — a mail is never a station on the way. Anything else is
    // inserted between the two ends: the edge now leads to the new node, and
    // the new node leads on to where the edge used to go.
    if (target?.kind === 'insert' && target.edge) {
        const edge = graph.value.edges.find(
            (e) =>
                e.from_node_key === target.edge.from_node_key &&
                (e.from_output ?? 'default') === (target.edge.from_output ?? 'default') &&
                e.to_node_key === target.edge.to_node_key,
        );

        if (entry.handle === 'mail' || !edge) {
            graph.value.edges.push({
                from_node_key: target.edge.from_node_key,
                to_node_key: key,
                from_output: target.edge.from_output ?? 'default',
            });
        } else {
            const oldTarget = edge.to_node_key;
            edge.to_node_key = key;
            graph.value.edges.push({ from_node_key: key, to_node_key: oldTarget, from_output: 'default' });
        }
    }

    if (target?.kind === 'replace-entry') {
        removeNode(target.fromNodeKey, { silent: true });
    }

    pendingTarget.value = null;
    selectedKey.value = key;
}

/**
 * The outputs of the selected step, with what already hangs off each.
 *
 * Shown in the inspector because the canvas's "+" only appears on an output
 * with **no** edge yet. A step that already leads somewhere still needs a way
 * to get a mail attached — and a step whose only edge goes to a mail still
 * needs a way to get its real continuation.
 */
const selectedOutputs = computed(() => {
    if (!selected.value || selected.value.type === 'mail') return [];

    const declared = selectedType.value?.outputs?.clauses?.[0]?.outputs ?? [];
    const nodeByKey = Object.fromEntries(graph.value.nodes.map((n) => [n.node_key, n]));

    return declared.map((out) => {
        const edges = graph.value.edges.filter(
            (e) => e.from_node_key === selected.value.node_key && (e.from_output ?? 'default') === out.handle,
        );
        const targets = edges.map((e) => nodeByKey[e.to_node_key]).filter(Boolean);

        return {
            handle: out.handle,
            label: t('outputs', out.handle, out.label ?? out.handle),
            mails: targets.filter((n) => n.type === 'mail').length,
            continues: targets.some((n) => n.type !== 'mail'),
        };
    });
});

/** Where the selected mail hangs, and by which output. */
const selectedMailTrigger = computed(() => {
    if (!selected.value || selected.value.type !== 'mail') return null;

    const edge = graph.value.edges.find((e) => e.to_node_key === selected.value.node_key);
    if (!edge) return t('mail', 'trigger_none', 'Hangs off no step.');

    const parent = graph.value.nodes.find((n) => n.node_key === edge.from_node_key);
    const step = parent?.label || KINDS.value?.[parent?.type]?.label || edge.from_node_key;
    const key = ['accepted', 'declined'].includes(edge.from_output) ? edge.from_output : 'default';

    return t('mail', `trigger_${key}`, `${key}: :step`).replace(':step', step);
});

const selectedMailStats = computed(() =>
    selected.value?.type === 'mail' ? (props.mailStats?.[selected.value.node_key] ?? null) : null,
);

function attachMail(fromKey, output) {
    pendingTarget.value = { fromNodeKey: fromKey, output };
    addNode('mail');
}

function attachStep(fromKey, output) {
    pendingTarget.value = { fromNodeKey: fromKey, output };
    showLibrary.value = true;
}

function removeNode(key, { silent = false } = {}) {
    if (!silent) record();

    graph.value.nodes = graph.value.nodes.filter((n) => n.node_key !== key);
    graph.value.edges = graph.value.edges.filter((e) => e.from_node_key !== key && e.to_node_key !== key);

    if (selectedKey.value === key) selectedKey.value = null;
}

function undo() {
    history.undo();
}

function redo() {
    history.redo();
}

function save() {
    saving.value = true;
    router.patch(props.saveUrl, graph.value, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
}

/**
 * Where people stop, on the cards themselves.
 *
 * A step nobody has reached shows nothing rather than zeroes: the difference
 * between "nobody yet" and "everybody left here" is the only reason to put
 * numbers on a card at all.
 */
const nodeStats = computed(() => {
    const out = {};

    for (const [key, row] of Object.entries(props.stats ?? {})) {
        if (!row || !row.visits) continue;

        out[key] = [
            { key: 'visits', icon: 'eye', value: row.visits, label: t('stats', 'visits', 'Visitors here') },
            // The last step of a walk has nowhere to carry on to. Showing it a
            // "0 / 0 %" would say it is losing everybody, when in fact it is
            // where they were meant to end up.
            ...(row.terminal
                ? []
                : [
                      { key: 'continued', icon: 'arrow-right', value: row.continued, tone: 'done', label: t('stats', 'continued', 'Carried on') },
                      {
                          key: 'rate',
                          icon: 'chart-line',
                          // Already a string, so the card shows it as given
                          // rather than rounding it into thousands.
                          value: row.rate === null ? null : `${row.rate}%`,
                          label: t('stats', 'rate', 'Share who carried on'),
                      },
                  ]),
        ];
    }

    // A mail node's numbers come from the deliveries table: triggered,
    // delivered, failed. Nothing to say until one has gone out.
    for (const [key, row] of Object.entries(props.mailStats ?? {})) {
        if (!row || !row.queued) continue;

        out[key] = [
            { key: 'queued', icon: 'mail', value: row.queued, label: t('mail', 'queued', 'Triggered') },
            { key: 'sent', icon: 'mail-check', value: row.sent, tone: 'done', label: t('mail', 'sent', 'Delivered') },
            ...(row.failed
                ? [{ key: 'failed', icon: 'alert-warning-exclamation-mark', value: row.failed, label: t('mail', 'failed', 'Failed') }]
                : []),
        ];
    }

    return out;
});

/** Options for a config field, where the field asks for something the site has. */
function optionsFor(field) {
    if (field.type === 'form') return props.forms;
    if (field.type === 'offer') return props.offers;
    // A field that carries its own list. Two shapes arrive from the schema:
    // bare handles like the deadline's three kinds, whose words come with the
    // labels payload (the raw handle would read as `rolling` in a German
    // Control Panel), and ready `{ value, label }` pairs, like the billing
    // modes and the mail templates, which are passed through as they are.
    if (field.type === 'select') {
        return (field.options ?? []).map((option) => {
            if (option && typeof option === 'object') return option;

            return { value: option, label: labels.value.options?.[option] ?? option };
        });
    }

    return [];
}

const labels = computed(() => props.labels ?? {});

/**
 * The graph as the canvas draws it: each node with its picture, when the server
 * has one. `config.thumbnail` is the server's record (`path`, `rendered_at`,
 * `url`); the canvas wants one URL on the node. `rendered_at` rides along as a
 * cache-buster, so a re-rendered page is not shown from the browser's cache of
 * the old one at the same path.
 */
const canvasNodes = computed(() =>
    graph.value.nodes.map((node) => {
        const thumb = node.config?.thumbnail;
        if (!thumb?.url) return node;

        return { ...node, thumbnail: `${thumb.url}?v=${encodeURIComponent(thumb.rendered_at ?? '')}` };
    }),
);

/** Pictures are on, the host cannot take them, and the selected step would have one. */
const thumbnailHint = computed(
    () => !!props.thumbnails?.enabled && !props.thumbnails?.available && !!selected.value && selected.value.type !== 'mail',
);

/** The two versions of the selected step, when it is running a test. */
const selectedSplit = computed(() => (selectedKey.value ? (props.splits?.[selectedKey.value] ?? null) : null));

/**
 * Die Seitenauswahl: Statamics eigener `entries`-Feldtyp, gefahren durch einen
 * `PublishContainer` — derselbe Weg, den die Kampagnen-Maske im Marketing-Addon
 * schon nimmt, und der einzige, auf dem ein Kernfeldtyp ausserhalb eines
 * Publish-Formulars seinen Kontext bekommt.
 *
 * Der Graph bleibt unveraendert: `config.entry` ist weiter eine Eintrags-ID als
 * Zeichenkette. Der Feldtyp arbeitet mit einer Liste (`max_items: 1` heisst ein
 * Element, nicht kein Array), also wird an genau dieser Naht umgepackt — und
 * nirgends sonst. Speichern, Vorschau und Auslieferung lesen weiter, was sie
 * immer gelesen haben.
 */
const entryBlueprintFields = computed(
    () => props.entryField?.blueprint?.tabs?.[0]?.sections?.[0]?.fields ?? [],
);

const entryHandles = computed(() => entryBlueprintFields.value.map((field) => field.handle));

/** Die Metadaten des gewaehlten Knotens; fuer einen frisch angelegten der leere Satz. */
const entryMeta = computed(
    () => props.entryField?.meta?.[selectedKey.value] ?? props.entryField?.meta?.[''] ?? {},
);

const entryValues = ref({});

function readEntryValues() {
    const config = selected.value?.config ?? {};

    return Object.fromEntries(
        entryHandles.value.map((handle) => [handle, config[handle] ? [config[handle]] : []]),
    );
}

// Beim Knotenwechsel neu aus dem Graphen lesen. Der Container wird ueber `:key`
// ohnehin neu aufgebaut, damit der Feldtyp mit den Metadaten des neuen Knotens
// startet statt mit denen des vorigen.
watch(selectedKey, () => { entryValues.value = readEntryValues(); }, { immediate: true });

function applyEntryValues(next) {
    entryValues.value = next;

    if (!selected.value) return;

    let changed = false;

    for (const handle of entryHandles.value) {
        const value = (next?.[handle] ?? [])[0] ?? null;

        if (selected.value.config[handle] !== value) {
            selected.value.config[handle] = value;
            changed = true;
        }
    }

    if (changed) record(`entry:${selected.value.node_key}`);
}

/** Nur das eine Feld zeichnen, an der Stelle, an der das Schritt-Schema es fuehrt. */
function entryFieldsFor(handle) {
    return entryBlueprintFields.value.filter((field) => field.handle === handle);
}
</script>

<template>
    <div class="flex h-[calc(100vh-4rem)] flex-col" data-funnel-editor>
        <Head :title="[funnel.title, __('statamic-funnels::messages.utility_title')]" />

        <Header :title="funnel.title" icon="hierarchy">
            <Badge v-if="!graph.published" color="amber" :text="t('ui', 'draft', 'Draft')" />
            <Badge v-else color="green" :text="t('ui', 'live', 'Live')" />
            <Button icon="arrow-left" :text="t('ui', 'undo', 'Undo')" :disabled="!history.canUndo.value" @click="undo" />
            <Button icon="arrow-right" :text="t('ui', 'redo', 'Redo')" :disabled="!history.canRedo.value" @click="redo" />
            <Button
                icon="eye"
                :text="t('ui', 'preview', 'Preview')"
                :variant="showPreview ? 'filled' : 'default'"
                :disabled="!previewUrl"
                @click="showPreview = !showPreview"
            />
            <Button variant="primary" :text="t('ui', 'save', 'Save')" :disabled="saving" @click="save" />
        </Header>

        <div class="flex min-h-0 flex-1 gap-4">
            <NodeLibrary
                v-if="showLibrary"
                class="w-72 shrink-0"
                :library="availableLibrary"
                :kinds="libraryKinds"
                :node-icon="nodeIcon"
                :pick-labels="labels.pick ?? {}"
                :pick-mode="!!pendingTarget"
                :pick-kind="pickKind"
                @add="addNode"
                @toggle="showLibrary = false"
                @cancel-pick="pendingTarget = null"
            />

            <PreviewPanel
                v-if="showPreview"
                class="min-w-0 flex-1"
                :preview-url="previewUrl"
                :nodes="graph.nodes"
                :edges="graph.edges"
                :devices="devices"
                :kinds="KINDS"
                :labels="labels.preview ?? {}"
                :selected-key="selectedKey"
                @select="selectedKey = $event"
                @close="showPreview = false"
            />

            <div v-else class="min-w-0 flex-1 rounded-lg border border-content-border">
                <Canvas
                    :kinds="KINDS"
                    :node-icon="nodeIcon"
                    :adder-labels="labels.adder ?? {}"
                    :nodes="canvasNodes"
                    :edges="graph.edges"
                    :library="availableLibrary"
                    :selected-key="selectedKey"
                    :node-stats="nodeStats"
                    :pending-target="pendingTarget"
                    @select="selectedKey = $event"
                    @toggle-pick="pendingTarget = $event; showLibrary = true"
                    @remove-node="removeNode"
                    @replace-unique="pendingTarget = { kind: 'replace-entry', fromNodeKey: $event }; showLibrary = true"
                />
            </div>

            <div v-if="selected" class="w-80 shrink-0 overflow-y-auto rounded-lg border border-content-border p-4">
                <Heading :text="selected.label || selectedType?.label" size="sm" class="mb-4" />

                <Field :label="t('fields', 'label', 'Label')" class="mb-4">
                    <Input v-model="selected.label" @update:model-value="record(`label:${selected.node_key}`)" />
                </Field>

                <!-- Quiet, and only when it is true: the feature is on and this
                     host has no browser to photograph pages with. Never a grey
                     placeholder on the card — a card without a picture looks
                     like a card, a grey box looks like a fault. -->
                <p v-if="thumbnailHint" class="mb-4 text-2xs text-gray-500 dark:text-gray-400">
                    {{ t('thumbnails', 'hint', 'Thumbnails need Chromium (see the docs).') }}
                </p>

                <!-- Which version is winning, right where the test is set up.
                     A split report on another screen is a report nobody opens. -->
                <div v-if="selectedSplit" class="mb-4 rounded-lg border border-content-border p-3">
                    <p class="mb-2 text-2xs font-medium uppercase tracking-wide text-gray-500">
                        {{ t('stats', 'split', 'Split test') }}
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div v-for="(row, key) in selectedSplit" :key="key">
                            <p class="text-xs font-medium text-gray-500">{{ key.toUpperCase() }}</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                {{ row.rate === null ? '–' : `${row.rate}%` }}
                            </p>
                            <p class="text-2xs text-gray-500">
                                {{ row.continued }} / {{ row.visits }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- A mail says where it hangs and what it has done. The trigger is
                     the edge, not a field: changing it means moving the edge. -->
                <div v-if="selected.type === 'mail'" class="mb-4 rounded-lg border border-content-border p-3">
                    <p class="mb-1 text-2xs font-medium uppercase tracking-wide text-gray-500">
                        {{ t('mail', 'trigger', 'Trigger') }}
                    </p>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ selectedMailTrigger }}</p>
                    <div v-if="selectedMailStats" class="mt-3 grid grid-cols-3 gap-3">
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ t('mail', 'queued', 'Triggered') }}</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ selectedMailStats.queued }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ t('mail', 'sent', 'Delivered') }}</p>
                            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ selectedMailStats.sent }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500">{{ t('mail', 'failed', 'Failed') }}</p>
                            <p class="text-lg font-semibold tabular-nums" :class="selectedMailStats.failed ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100'">{{ selectedMailStats.failed }}</p>
                        </div>
                    </div>
                </div>

                <!-- Der Container haelt den Zustand fuer die Seitenfelder. Er
                     umschliesst die ganze Schleife, weil ein Feldtyp seinen
                     Kontext nur von einem Vorfahren bekommt; gezeichnet wird
                     trotzdem nur das eine Feld, an seiner Stelle im Schema.
                     `:key` baut ihn beim Knotenwechsel neu auf, sonst zeigte er
                     die Auswahl des vorigen Schritts weiter. -->
                <PublishContainer
                    :key="`entries-${selected.node_key}`"
                    name="funnel-step-entries"
                    :blueprint="entryField?.blueprint"
                    :meta="entryMeta"
                    :model-value="entryValues"
                    :track-dirty-state="false"
                    @update:model-value="applyEntryValues"
                >
                    <template v-for="field in visibleFields" :key="field.handle">
                        <div v-if="field.type === 'entry'" class="mb-4">
                            <PublishFieldsProvider :fields="entryFieldsFor(field.handle)">
                                <PublishFields />
                            </PublishFieldsProvider>
                        </div>
                        <Field
                            v-else
                            :label="field.label"
                            :instructions="field.instructions"
                            class="mb-4"
                        >
                            <Select
                                v-if="field.type === 'form' || field.type === 'offer' || field.type === 'select'"
                                v-model="selected.config[field.handle]"
                                :options="optionsFor(field)"
                            />
                            <Textarea
                                v-else-if="field.type === 'textarea'"
                                v-model="selected.config[field.handle]"
                                :rows="4"
                            />
                            <Input v-else v-model="selected.config[field.handle]" />
                        </Field>
                    </template>
                </PublishContainer>

                <!-- The ways out, and what to hang on each. The canvas's "+" only
                     exists on an output with no edge yet; this is how a step that
                     already leads somewhere gets a mail, and how a step whose only
                     edge goes to a mail gets its real continuation. -->
                <div v-if="selectedOutputs.length" class="mt-2 rounded-lg border border-content-border p-3">
                    <p class="mb-2 text-2xs font-medium uppercase tracking-wide text-gray-500">
                        {{ t('outputs', 'heading', 'Outputs') }}
                    </p>
                    <div v-for="out in selectedOutputs" :key="out.handle" class="flex flex-wrap items-center gap-2 py-1.5">
                        <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">
                            {{ out.label }}
                            <span v-if="out.mails" class="ms-1 text-2xs text-gray-500">
                                {{ t('outputs', 'mailsHere', ':count mail(s)').replace(':count', out.mails) }}
                            </span>
                        </span>
                        <Button
                            v-if="!out.continues"
                            size="xs"
                            icon="plus"
                            :text="t('outputs', 'attachStep', 'Attach step')"
                            @click="attachStep(selected.node_key, out.handle)"
                        />
                        <Button
                            size="xs"
                            icon="mail"
                            :text="t('outputs', 'attachMail', 'Attach mail')"
                            @click="attachMail(selected.node_key, out.handle)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4 border-t border-content-border px-4 py-3">
            <Field :label="t('ui', 'handle', 'Handle')" class="w-64">
                <Input v-model="graph.handle" class="font-mono" />
            </Field>
            <Field :label="t('ui', 'published', 'Live')">
                <Switch v-model="graph.published" />
            </Field>
            <a v-if="graph.published" :href="publicUrl" target="_blank" class="text-sm text-primary underline">
                {{ publicUrl }}
            </a>
        </div>
    </div>
</template>
