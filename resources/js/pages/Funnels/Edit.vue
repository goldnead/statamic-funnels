<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Button, Badge, Field, Input, Select, Textarea, Switch, Heading, Combobox } from '@statamic/cms/ui';
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
    entries: { type: Array, default: () => [] },
    entriesUrl: { type: String, default: '' },
    previewUrl: { type: String, default: '' },
    devices: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
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

    (entry.schema ?? []).forEach((field) => { config[field.handle] = null; });

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

    if (target?.kind === 'replace-entry') {
        removeNode(target.fromNodeKey, { silent: true });
    }

    pendingTarget.value = null;
    selectedKey.value = key;
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

    return out;
});

/** Options for a config field, where the field asks for something the site has. */
function optionsFor(field) {
    if (field.type === 'form') return props.forms;
    if (field.type === 'offer') return props.offers;

    return [];
}

// The entry picker searches the server rather than filtering a list that was
// sent with the page. A site with thousands of pages should not pay for them in
// every editor load, and `ignoreFilter` is what stops the Combobox from also
// filtering the answer it just received.
const entryOptions = ref([...props.entries]);
let entrySearchTimer = null;

function searchEntries(query) {
    if (!props.entriesUrl) return;

    clearTimeout(entrySearchTimer);
    entrySearchTimer = setTimeout(async () => {
        try {
            const url = `${props.entriesUrl}?search=${encodeURIComponent(query ?? '')}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const body = await response.json();
            // Keep whatever is currently chosen in the list. Dropping it would
            // blank the field's own label while the menu is open.
            const chosen = entryOptions.value.filter(
                (option) => option.value === selected.value?.config?.entry,
            );
            const fresh = body.options ?? [];
            entryOptions.value = [
                ...fresh,
                ...chosen.filter((c) => !fresh.some((f) => f.value === c.value)),
            ];
        } catch {
            // A failed search leaves the previous options standing, which is
            // more useful than an empty menu.
        }
    }, 250);
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
                :library="library"
                :kinds="KINDS"
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
                    :nodes="graph.nodes"
                    :edges="graph.edges"
                    :library="library"
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

                <Field
                    v-for="field in selectedType?.schema ?? []"
                    :key="field.handle"
                    :label="field.label"
                    :instructions="field.instructions"
                    class="mb-4"
                >
                    <Combobox
                        v-if="field.type === 'entry'"
                        v-model="selected.config[field.handle]"
                        :options="entryOptions"
                        :placeholder="t('fields', 'entryPlaceholder', 'Search pages…')"
                        searchable
                        clearable
                        ignore-filter
                        @search="searchEntries"
                        @update:model-value="record(`entry:${selected.node_key}`)"
                    />
                    <Select
                        v-else-if="field.type === 'form' || field.type === 'offer'"
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
