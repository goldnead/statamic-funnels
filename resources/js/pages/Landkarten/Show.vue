<script setup>
import { computed, nextTick, provide, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { toast } from '@statamic/cms/api';
import { VueFlow, Panel, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { MiniMap } from '@vue-flow/minimap';
import { ControlBar } from '@goldnead/flow-canvas';
import {
    Header, Button, Badge, Select, Input, Stack, Field, Heading, Icon,
} from '@statamic/cms/ui';
import MapNode from '../../components/landkarte/MapNode.vue';
import MapLane from '../../components/landkarte/MapLane.vue';
import MapEdge from '../../components/landkarte/MapEdge.vue';
import { flowEdges, kindOf, layoutNodes, wayThrough } from '../../support/landkarteLayout.js';

/**
 * A Landkarte.
 *
 * Phase lanes run left to right, each card is an object that lives in another
 * addon, each line is a connection that addon can read from its own data (or
 * one drawn by hand). The page holds a type and an id per card and nothing
 * else: label, link, switch, number and broken references come from the owner on
 * every load.
 *
 * Reading comes first, editing second. Moving is done with buttons, not by
 * dragging: every move is a labelled, keyboard-reachable action.
 */
const props = defineProps({
    landkarte: { type: Object, required: true },
    lanes: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    edges: { type: Array, default: () => [] },
    mainPath: { type: Object, required: true },
    problemCount: { type: Number, default: 0 },
    version: { type: String, required: true },
    types: { type: Array, default: () => [] },
    period: { type: Object, required: true },
    saveUrl: { type: String, required: true },
    objectsUrl: { type: String, required: true },
    showUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    labels: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;
const clone = (value) => JSON.parse(JSON.stringify(value));
const FLOW = 'landkarte';
const { fitView, setCenter, getViewport } = useVueFlow(FLOW);

// --- what is stored, as a form -------------------------------------------

const splitKey = (key) => {
    const at = key.indexOf(':');
    return [key.slice(0, at), key.slice(at + 1)];
};

function modelFrom(p) {
    const lanes = p.lanes.map((lane, index) => ({
        id: lane.id,
        name: lane.name,
        purpose: lane.purpose ?? '',
        refs: p.nodes
            .filter((n) => !n.ghost && n.lane === index)
            .sort((a, b) => a.row - b.row)
            .map((n) => ({ type: n.type, ref_id: n.ref_id })),
    }));

    const hand = p.edges
        .filter((e) => e.hand)
        .map((e) => {
            const [from_type, from_ref] = splitKey(e.source);
            const [to_type, to_ref] = splitKey(e.target);
            return { from_type, from_ref, to_type, to_ref, label: e.label === t('map_edge_hand_label') ? '' : e.label };
        });

    return { name: p.landkarte.name, lanes, hand };
}

const local = ref(modelFrom(props));
const saved = ref(JSON.stringify(local.value));
const saving = ref(false);
const editing = ref(false);
const isDirty = computed(() => JSON.stringify(local.value) !== saved.value);

// Cards the page added this session, before the server has described them.
const added = ref({});

let sent = null;

watch(
    () => [props.version, props.lanes, props.nodes, props.edges],
    () => {
        const fresh = modelFrom(props);
        const untouched = sent === null ? !isDirty.value : JSON.stringify(local.value) === sent;

        saved.value = JSON.stringify(fresh);
        if (untouched) {
            local.value = fresh;
            added.value = {};
        }
        sent = null;
    },
);

function save() {
    saving.value = true;
    sent = JSON.stringify(local.value);

    router.put(
        props.saveUrl,
        { version: props.version, name: local.value.name, stages: local.value.lanes, edges: local.value.hand },
        {
            preserveScroll: true,
            onSuccess: () => { toast.success(t('map_saved', 'Saved.'), {}); },
            onError: (errors) => { sent = null; toast.error(String(Object.values(errors ?? {})[0] ?? ''), {}); },
            onFinish: () => { saving.value = false; },
        },
    );
}

function setPeriod(days) {
    router.get(props.showUrl, { days }, { preserveScroll: true });
}

// --- the picture ------------------------------------------------------------

const serverCards = computed(() => Object.fromEntries(props.nodes.map((n) => [n.key, n])));

const displayNodes = computed(() => {
    const out = [];
    const placed = new Set();

    local.value.lanes.forEach((lane, li) => {
        lane.refs.forEach((ref, row) => {
            const key = `${ref.type}:${ref.ref_id}`;
            const card = serverCards.value[key] ?? added.value[key];
            if (!card || placed.has(key)) return;
            placed.add(key);
            out.push({ ...card, lane: li, row, ghost: false });
        });
    });

    // A card for something that is gone, drawn because an edge points at it.
    for (const node of props.nodes) {
        if (node.ghost && !placed.has(node.key)) {
            out.push({ ...node, lane: Math.min(node.lane, Math.max(0, local.value.lanes.length - 1)), row: null });
        }
    }

    const onWay = new Set(props.mainPath.node_keys ?? []);
    return out.map((n) => ({ ...n, main: onWay.has(n.key) }));
});

const displayEdges = computed(() => {
    const keys = new Set(displayNodes.value.map((n) => n.key));
    const list = props.edges.filter((e) => !e.hand && keys.has(e.source) && keys.has(e.target));
    const pairs = new Set(list.map((e) => `${e.source}>${e.target}`));

    local.value.hand.forEach((h, i) => {
        const source = `${h.from_type}:${h.from_ref}`;
        const target = `${h.to_type}:${h.to_ref}`;
        if (!keys.has(source) || !keys.has(target) || pairs.has(`${source}>${target}`)) return;
        list.push({
            id: `hand:${i}`, source, target, kind: 'hand', label: h.label || t('map_edge_hand_label'),
            derived_from: null, dashed: true, hand: true, hand_index: i, main: false, bad: false,
        });
    });

    return list;
});

const layout = computed(() => layoutNodes(local.value.lanes.map((l, i) => ({ ...l, tone: props.lanes[i]?.tone ?? i % 5 })), displayNodes.value, { editing: editing.value }));
const flowNodes = computed(() => layout.value.flowNodes);
const flowEdgeList = computed(() => flowEdges(displayEdges.value, displayNodes.value, layout.value.rows));
const nodeByKey = computed(() => Object.fromEntries(displayNodes.value.map((n) => [n.key, n])));

// --- selection --------------------------------------------------------------

const selectedKey = ref(null);
const selectedEdge = ref(null);

const way = computed(() => {
    if (selectedKey.value) return wayThrough(selectedKey.value, displayEdges.value);
    if (selectedEdge.value) {
        const edge = displayEdges.value.find((e) => e.id === selectedEdge.value);
        return edge ? { nodes: new Set([edge.source, edge.target]), ids: new Set([edge.id]) } : { nodes: new Set(), ids: new Set() };
    }
    return { nodes: new Set(), ids: new Set() };
});

const lit = computed(() => way.value.nodes);
const litEdges = computed(() => way.value.ids);
const dimmed = computed(() => !!(selectedKey.value || selectedEdge.value));

function select(key) {
    selectedEdge.value = null;
    selectedKey.value = key;
}

function selectEdge(id) {
    selectedKey.value = null;
    selectedEdge.value = id;
}

function clearSelection() {
    selectedKey.value = null;
    selectedEdge.value = null;
}

const selected = computed(() => (selectedKey.value ? nodeByKey.value[selectedKey.value] ?? null : null));
const edge = computed(() => (selectedEdge.value ? displayEdges.value.find((e) => e.id === selectedEdge.value) ?? null : null));

watch(displayNodes, (list) => {
    if (selectedKey.value && !list.some((n) => n.key === selectedKey.value)) selectedKey.value = null;
});

const connections = computed(() => {
    if (!selected.value) return { incoming: [], outgoing: [] };
    const describe = (e, otherKey) => ({ edge: e, other: nodeByKey.value[otherKey] });
    return {
        incoming: displayEdges.value.filter((e) => e.target === selected.value.key).map((e) => describe(e, e.source)),
        outgoing: displayEdges.value.filter((e) => e.source === selected.value.key).map((e) => describe(e, e.target)),
    };
});

const metricRule = computed(() => (selected.value?.metric ? `${selected.value.metric.what}, ${selected.value.metric.period}` : null));

function drill(node) {
    if (node?.drill_url) router.visit(node.drill_url);
}

function onNodeClick({ node }) {
    if (node.type === 'landkarte') select(node.id);
}

function onNodeDoubleClick({ node }) {
    if (node.type === 'landkarte') drill(node.data);
}

const problemNodes = computed(() => displayNodes.value.filter((n) => n.status === 'missing' || (n.problems ?? []).length));
const problemTotal = computed(() => props.problemCount);

async function jumpToProblem() {
    const target = problemNodes.value[0];
    if (!target) return;
    select(target.key);
    await nextTick();
    fitView({ nodes: [target.key], duration: 400, maxZoom: 1, padding: 1.4 });
}

// --- the reading aids -------------------------------------------------------

const mainText = computed(() => {
    if (props.mainPath.status === 'found') {
        const goal = nodeByKey.value[props.mainPath.goal];
        return t('map_main_path_found').replace(':goal', goal?.label ?? '');
    }
    return t(props.mainPath.status === 'unreachable' ? 'map_main_path_unreachable' : 'map_main_path_no_revenue');
});

const legendOpen = ref(true);
const legendKinds = computed(() => {
    const seen = new Map();
    displayNodes.value.forEach((n) => { if (!seen.has(n.type)) seen.set(n.type, n.type_label); });
    return [...seen].map(([type, label]) => ({ type, label, ...kindOf(type) }));
});

const minimapClass = (node) => (node?.type === 'lane' ? 'lk-minimap-lane' : '');

// --- lanes ------------------------------------------------------------------

const laneDialog = ref({ open: false, index: null, name: '', purpose: '' });

function openLane(index = null) {
    const lane = index === null ? null : local.value.lanes[index];
    laneDialog.value = { open: true, index, name: lane?.name ?? '', purpose: lane?.purpose ?? '' };
}

function commitLane() {
    const { index, name, purpose } = laneDialog.value;
    const trimmed = name.trim();
    if (!trimmed) return;

    if (index === null) local.value.lanes.push({ id: null, name: trimmed, purpose: purpose.trim(), refs: [] });
    else Object.assign(local.value.lanes[index], { name: trimmed, purpose: purpose.trim() });

    laneDialog.value.open = false;
}

function moveLane(index, by) {
    const target = index + by;
    if (target < 0 || target >= local.value.lanes.length) return;
    const [lane] = local.value.lanes.splice(index, 1);
    local.value.lanes.splice(target, 0, lane);
}

function removeLane(index) {
    if (window.confirm(t('map_remove_stage_confirm', 'Remove this phase?'))) local.value.lanes.splice(index, 1);
}

// --- cards ------------------------------------------------------------------

function locate(key) {
    for (let li = 0; li < local.value.lanes.length; li++) {
        const ci = local.value.lanes[li].refs.findIndex((r) => `${r.type}:${r.ref_id}` === key);
        if (ci !== -1) return [li, ci];
    }
    return null;
}

function moveCard(key, by) {
    const at = locate(key);
    if (!at) return;
    const refs = local.value.lanes[at[0]].refs;
    const target = at[1] + by;
    if (target < 0 || target >= refs.length) return;
    const [card] = refs.splice(at[1], 1);
    refs.splice(target, 0, card);
}

function moveCardToLane(key, by) {
    const at = locate(key);
    if (!at || !local.value.lanes[at[0] + by]) return;
    const [card] = local.value.lanes[at[0]].refs.splice(at[1], 1);
    local.value.lanes[at[0] + by].refs.push(card);
}

function removeCard(key) {
    const at = locate(key);
    if (!at) return;
    local.value.lanes[at[0]].refs.splice(at[1], 1);
    clearSelection();
}

const atTop = computed(() => selected.value && locate(selected.value.key)?.[1] === 0);
const atBottom = computed(() => {
    if (!selected.value) return true;
    const at = locate(selected.value.key);
    return !at || at[1] === local.value.lanes[at[0]].refs.length - 1;
});
const laneOf = computed(() => (selected.value ? locate(selected.value.key)?.[0] ?? -1 : -1));

// --- picker -----------------------------------------------------------------

const picker = ref({ open: false, laneIndex: null, type: null, q: '', objects: [], loading: false, failed: false });

function openPicker(laneIndex) {
    picker.value = { open: true, laneIndex, type: props.types[0]?.value ?? null, q: '', objects: [], loading: false, failed: false };
    if (picker.value.type) search();
}

let timer = null;
const searchSoon = () => { clearTimeout(timer); timer = setTimeout(search, 250); };

async function search() {
    const { type, q } = picker.value;
    if (!type) return;

    picker.value.loading = true;
    picker.value.failed = false;

    try {
        const url = new URL(props.objectsUrl, window.location.origin);
        url.searchParams.set('type', type);
        if (q) url.searchParams.set('q', q);

        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(String(response.status));
        if (picker.value.type === type && picker.value.q === q) picker.value.objects = (await response.json()).objects;
    } catch {
        picker.value.failed = true;
        picker.value.objects = [];
    } finally {
        picker.value.loading = false;
    }
}

function pickType(type) {
    picker.value.type = type;
    picker.value.objects = [];
    search();
}

const typeLabel = (type) => props.types.find((x) => x.value === type)?.label ?? type;
const alreadyOnMap = (object) => !!locate(`${object.type}:${object.id}`);

function addObject(object) {
    const key = `${object.type}:${object.id}`;
    if (locate(key)) return;

    local.value.lanes[picker.value.laneIndex].refs.push({ type: object.type, ref_id: object.id });
    // The number arrives with the next load; an invented zero would be a claim.
    added.value[key] = {
        key, type: object.type, type_label: typeLabel(object.type), ref_id: object.id, status: 'found', label: object.label,
        cp_url: object.cp_url, enabled: object.enabled, detail: object.detail, problems: object.problems ?? [], metric: null, ghost: false, drill_url: null,
    };
}

// --- hand-drawn connections -------------------------------------------------

const link = ref({ open: false, from: null, to: null, label: '' });
const nodeOptions = computed(() => displayNodes.value.filter((n) => !n.ghost).map((n) => ({ value: n.key, label: `${n.type_label}: ${n.label}` })));

function openLink(from = null) {
    link.value = { open: true, from, to: null, label: '' };
}

function commitLink() {
    const { from, to, label } = link.value;
    if (!from || !to || from === to) return;
    const [from_type, from_ref] = splitKey(from);
    const [to_type, to_ref] = splitKey(to);

    local.value.hand.push({ from_type, from_ref, to_type, to_ref, label: label.trim() });
    link.value.open = false;
}

function removeHand(index) {
    local.value.hand.splice(index, 1);
    clearSelection();
}

function toggleEditing() {
    editing.value = !editing.value;
    clearSelection();
}

provide('landkarte', {
    t, editing, lit, litEdges, dimmed, selectedEdge, select, selectEdge, openLane, moveLane, removeLane, openPicker,
});

// The side panel is its own column, so opening or closing it changes the width
// of the board: fit again, once per toggle and not per selection.
watch(
    () => !!(selected.value || edge.value),
    async () => {
        await nextTick();
        setTimeout(() => fitView({ duration: 250, padding: 0.08, maxZoom: 1 }), 60);
    },
);

const periodOptions = computed(() => props.period.options);
</script>

<template>
    <div class="lk-page" data-landkarte data-flow-full-bleed>
        <Head :title="[landkarte.name, t('landkarten_title', 'Maps')]" />

        <Header :title="landkarte.name" icon="travel-map">
            <Badge v-if="isDirty" color="default" :text="t('map_unsaved', 'Unsaved changes')" pill />
            <Select
                class="w-44"
                :model-value="period.days"
                :options="periodOptions"
                :disabled="isDirty"
                :aria-label="t('map_period', 'Period')"
                @update:model-value="setPeriod"
            />
            <template v-if="editing">
                <Button :text="t('map_add_stage', 'Add phase')" icon="plus" @click="openLane()" />
                <Button :text="t('map_draw_edge', 'Draw connection')" icon="link" @click="openLink()" />
                <Button variant="primary" :text="t('map_save', 'Save')" :disabled="saving || !isDirty" @click="save" />
                <Button :text="t('map_done', 'Done')" :disabled="isDirty" @click="toggleEditing" />
            </template>
            <Button v-else :text="t('map_edit', 'Edit')" icon="edit" @click="toggleEditing" />
        </Header>

        <p class="lk-lede">{{ t('landkarten_description') }}</p>

        <p v-if="errors && Object.keys(errors).length" class="mb-3 text-sm text-red-600" role="alert">{{ Object.values(errors)[0] }}</p>

        <div class="lk-shell" :class="{ 'lk-shell--panel': selected || edge }">
            <div class="lk-board sa-canvas-frame" data-landkarte-board>
                <VueFlow
                    :id="FLOW"
                    :nodes="flowNodes"
                    :edges="flowEdgeList"
                    :nodes-draggable="false"
                    :nodes-connectable="false"
                    :edges-updatable="false"
                    :elements-selectable="false"
                    :zoom-on-double-click="false"
                    :pan-on-drag="true"
                    :min-zoom="0.2"
                    :max-zoom="1.5"
                    :fit-view-on-init="true"
                    :default-edge-options="{ type: 'landkarte' }"
                    class="fc-canvas"
                    @node-click="onNodeClick"
                    @node-double-click="onNodeDoubleClick"
                    @pane-click="clearSelection"
                >
                    <template #node-landkarte="slot"><MapNode v-bind="slot" :selected="selectedKey === slot.id" /></template>
                    <template #node-lane="slot"><MapLane :data="slot.data" /></template>
                    <template #node-adder="slot">
                        <button type="button" class="lk-adder nodrag nopan" data-lk-adder @click.stop="openPicker(slot.data.index)">
                            <Icon name="plus" class="size-4" /> {{ t('map_add_ref', 'Add object') }}
                        </button>
                    </template>
                    <template #edge-landkarte="slot"><MapEdge v-bind="slot" /></template>

                    <Background pattern-color="var(--color-gray-300)" :gap="20" :size="1.2" />
                    <MiniMap pannable zoomable :node-class-name="minimapClass" />

                    <Panel position="top-left" class="lk-flags">
                        <button
                            v-if="problemTotal"
                            type="button"
                            class="lk-flag lk-flag--bad"
                            data-lk-problems
                            :title="t('map_problem_jump')"
                            @click="jumpToProblem"
                        >
                            <Icon name="warning-diamond" class="size-4" />
                            {{ problemTotal === 1 ? t('map_problem_one') : t('map_problem_many').replace(':count', problemTotal) }}
                        </button>
                        <span class="lk-flag lk-flag--main" :class="{ 'lk-flag--muted': mainPath.status !== 'found' }" data-lk-main>
                            <span class="lk-flag__swatch" /> {{ mainText }}
                        </span>
                    </Panel>

                    <Panel position="bottom-left" class="lk-legend-panel">
                        <div class="lk-legend" data-lk-legend>
                            <button type="button" class="lk-legend__title" :aria-expanded="legendOpen" @click="legendOpen = !legendOpen">
                                {{ t('map_legend') }}
                                <Icon :name="legendOpen ? 'x' : 'plus'" class="size-3" />
                            </button>
                            <template v-if="legendOpen">
                                <ul class="lk-legend__kinds">
                                    <li v-for="k in legendKinds" :key="k.type"><Icon :name="k.icon" class="size-3.5" :class="`lk-accent-text--${k.accent}`" /> {{ k.label }}</li>
                                </ul>
                                <ul class="lk-legend__lines">
                                    <li><span class="lk-key lk-key--main" /> {{ t('map_legend_main') }}</li>
                                    <li><span class="lk-key lk-key--wait" /> {{ t('map_legend_wait') }}</li>
                                    <li><span class="lk-key lk-key--bad" /> {{ t('map_legend_problem') }}</li>
                                    <li><span class="lk-key lk-key--hand" /> {{ t('map_legend_hand') }}</li>
                                </ul>
                            </template>
                        </div>
                    </Panel>

                    <Panel position="bottom-right" class="lk-controls">
                        <ControlBar :flow-id="FLOW" />
                    </Panel>
                </VueFlow>

                <!-- Arrowheads, one per state of a line: a marker cannot inherit its line's colour. -->
                <svg class="lk-defs" aria-hidden="true" width="0" height="0">
                    <defs>
                        <marker v-for="m in [['lk-arrow', 'var(--color-gray-400)'], ['lk-arrow-main', 'var(--color-green-700)'], ['lk-arrow-on', 'var(--color-blue-500)'], ['lk-arrow-bad', 'var(--color-red-500)']]" :id="m[0]" :key="m[0]" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse" markerUnits="strokeWidth">
                            <path d="M 0 0 L 10 5 L 0 10 z" :fill="m[1]" />
                        </marker>
                    </defs>
                </svg>

                <div v-if="!lanes.length" class="lk-empty">
                    <Heading :text="t('landkarten_empty', 'No phases yet')" size="lg" />
                    <p>{{ t('landkarten_empty_help') }}</p>
                    <Button icon="plus" :text="t('map_add_stage')" @click="editing = true; openLane()" />
                </div>
            </div>

            <!-- Side panel: its own column, not a sheet over the picture. -->
            <aside v-if="selected" class="lk-panel" data-lk-panel aria-live="polite">
                <header class="lk-panel__head">
                    <span class="lk-tag" :class="`lk-accent-pill--${kindOf(selected.type).accent}`">{{ selected.type_label }}</span>
                    <Button variant="ghost" size="sm" icon="x" inset :aria-label="t('map_panel_close')" @click="clearSelection" />
                </header>
                <h2 class="lk-panel__title" :class="{ 'lk-panel__title--gone': selected.status === 'missing' }">{{ selected.label }}</h2>
                <p v-if="selected.detail" class="lk-panel__mono">{{ selected.detail }}</p>

                <dl class="lk-panel__facts">
                    <div>
                        <dt>{{ t('map_panel_status') }}</dt>
                        <dd v-if="selected.status === 'missing'" class="lk-bad">{{ selected.ghost ? t('map_dead_reference') : t('map_broken') }}</dd>
                        <dd v-else-if="selected.status === 'unavailable'">{{ t('map_unavailable') }}</dd>
                        <dd v-else>{{ selected.enabled === true ? t('map_on') : selected.enabled === false ? t('map_off') : t('map_no_switch') }}</dd>
                    </div>
                    <div v-if="selected.status === 'found'">
                        <dt>{{ t('map_panel_number') }}</dt>
                        <dd v-if="selected.metric"><strong>{{ Number(selected.metric.value).toLocaleString() }}</strong></dd>
                        <dd v-else>{{ t('map_no_number') }}</dd>
                        <dd v-if="metricRule" class="lk-panel__rule">{{ t('map_panel_rule').replace(':rule', metricRule) }}</dd>
                    </div>
                </dl>

                <p v-if="selected.status === 'missing'" class="lk-panel__help lk-bad">{{ selected.ghost ? t('map_ghost_help') : t('map_broken_help') }}</p>
                <p v-else-if="selected.status === 'unavailable'" class="lk-panel__help">{{ t('map_unavailable_help') }}</p>

                <section v-if="(selected.problems ?? []).length" class="lk-panel__section">
                    <h3>{{ t('map_panel_problems') }}</h3>
                    <ul class="lk-panel__problems"><li v-for="p in selected.problems" :key="p">{{ p }}</li></ul>
                </section>

                <section class="lk-panel__section">
                    <h3>{{ t('map_panel_connections') }}</h3>
                    <template v-if="connections.incoming.length || connections.outgoing.length">
                        <p v-if="connections.incoming.length" class="lk-panel__dir">{{ t('map_panel_in') }}</p>
                        <ul class="lk-panel__links">
                            <li v-for="c in connections.incoming" :key="c.edge.id">
                                <button type="button" @click="c.other && !c.other.ghost ? select(c.other.key) : selectEdge(c.edge.id)">
                                    <span class="lk-panel__rel">{{ c.edge.label }}</span> {{ c.other?.label ?? c.edge.source }}
                                </button>
                            </li>
                        </ul>
                        <p v-if="connections.outgoing.length" class="lk-panel__dir">{{ t('map_panel_out') }}</p>
                        <ul class="lk-panel__links">
                            <li v-for="c in connections.outgoing" :key="c.edge.id">
                                <button type="button" @click="c.other && !c.other.ghost ? select(c.other.key) : selectEdge(c.edge.id)">
                                    <span class="lk-panel__rel">{{ c.edge.label }}</span> {{ c.other?.label ?? c.edge.target }}
                                </button>
                            </li>
                        </ul>
                    </template>
                    <p v-else class="lk-panel__help">{{ t('map_panel_none') }}</p>
                </section>

                <div class="lk-panel__actions">
                    <Button v-if="selected.drill_url" variant="primary" icon="arrow-right" :text="t('map_open_inside')" @click="drill(selected)" />
                    <Button v-if="selected.cp_url" :icon="selected.drill_url ? undefined : 'external-link'" :text="t('map_open_editor')" :href="selected.cp_url" />
                </div>

                <section v-if="editing && !selected.ghost" class="lk-panel__section lk-panel__edit">
                    <div class="lk-panel__move">
                        <Button size="sm" icon="arrow-left" :disabled="laneOf <= 0" :aria-label="t('map_move_left')" @click="moveCardToLane(selected.key, -1)" />
                        <Button size="sm" icon="arrow-up" :disabled="atTop" :aria-label="t('map_move_up')" @click="moveCard(selected.key, -1)" />
                        <Button size="sm" icon="arrow-down" :disabled="atBottom" :aria-label="t('map_move_down')" @click="moveCard(selected.key, 1)" />
                        <Button size="sm" icon="arrow-right" :disabled="laneOf < 0 || laneOf >= local.lanes.length - 1" :aria-label="t('map_move_right')" @click="moveCardToLane(selected.key, 1)" />
                    </div>
                    <div class="lk-panel__move">
                        <Button size="sm" icon="link" :text="t('map_draw_edge_from')" @click="openLink(selected.key)" />
                        <Button size="sm" variant="destructive" icon="trash" :text="t('map_remove_ref')" @click="removeCard(selected.key)" />
                    </div>
                </section>
            </aside>

            <aside v-else-if="edge" class="lk-panel" data-lk-panel aria-live="polite">
                <header class="lk-panel__head">
                    <span class="lk-tag">{{ edge.hand ? t('map_edge_origin_hand') : t('map_edge_origin_derived') }}</span>
                    <Button variant="ghost" size="sm" icon="x" inset :aria-label="t('map_panel_close')" @click="clearSelection" />
                </header>
                <h2 class="lk-panel__title">{{ edge.label }}</h2>
                <p class="lk-panel__path">
                    <button type="button" @click="select(edge.source)">{{ nodeByKey[edge.source]?.label }}</button>
                    <Icon name="arrow-right" class="size-3.5" />
                    <button type="button" @click="select(edge.target)">{{ nodeByKey[edge.target]?.label }}</button>
                </p>
                <dl class="lk-panel__facts">
                    <div>
                        <dt>{{ t('map_panel_status') }}</dt>
                        <dd v-if="edge.hand">{{ t('map_edge_origin_hand') }}</dd>
                        <dd v-else>{{ t('map_edge_origin_derived') }}: {{ edge.derived_from }}</dd>
                    </div>
                    <div>
                        <dt>{{ t('map_edge_number') }}</dt>
                        <dd>{{ t('map_edge_number_none') }}</dd>
                    </div>
                </dl>
                <section v-if="edge.main" class="lk-panel__section"><p class="lk-panel__help">{{ t('map_main_path_title') }}</p></section>
                <div v-if="editing && edge.hand" class="lk-panel__actions">
                    <Button variant="destructive" icon="trash" :text="t('map_remove_edge')" @click="removeHand(edge.hand_index)" />
                </div>
            </aside>
        </div>

        <!-- Name a phase and say what it is for. -->
        <Stack v-model:open="laneDialog.open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="laneDialog.index === null ? t('map_add_stage') : t('map_rename')" size="lg" />
                </div>
                <div class="flex-1 space-y-4 px-6 py-5">
                    <Field :label="t('map_stage_name')" required>
                        <Input v-model="laneDialog.name" :placeholder="t('map_stage_name_placeholder')" maxlength="191" @keydown.enter="commitLane" />
                    </Field>
                    <Field :label="t('map_purpose')">
                        <Input v-model="laneDialog.purpose" :placeholder="t('map_purpose_placeholder')" maxlength="191" @keydown.enter="commitLane" />
                    </Field>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t('map_cancel', 'Cancel')" @click="laneDialog.open = false" />
                        <Button variant="primary" :text="laneDialog.index === null ? t('map_add') : t('map_rename')" :disabled="!laneDialog.name.trim()" @click="commitLane" />
                    </div>
                </div>
            </div>
        </Stack>

        <!-- Pin an object. -->
        <Stack v-model:open="picker.open" size="half">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="t('map_add_ref')" size="lg" />
                </div>
                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <Field :label="t('map_pick_type')">
                        <Select :model-value="picker.type" :options="types" @update:model-value="pickType" />
                    </Field>
                    <Field :label="t('map_search')">
                        <Input v-model="picker.q" type="search" @update:model-value="searchSoon" />
                    </Field>

                    <p v-if="picker.failed" class="text-sm text-red-600" role="alert">{{ t('map_unavailable_help') }}</p>
                    <p v-else-if="!picker.loading && !picker.objects.length" class="text-sm text-gray-600 dark:text-gray-400">{{ t('map_no_objects') }}</p>

                    <ul class="divide-y divide-content-border rounded-lg border border-content-border">
                        <li v-for="object in picker.objects" :key="object.id" class="flex items-center justify-between gap-3 px-3 py-2">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ object.label }}</div>
                                <div v-if="object.detail" class="truncate text-sm text-gray-600 dark:text-gray-400">{{ object.detail }}</div>
                            </div>
                            <Button size="sm" icon="plus" :text="t('map_add')" :disabled="alreadyOnMap(object)" @click="addObject(object)" />
                        </li>
                    </ul>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end">
                        <Button variant="primary" :text="t('map_done')" @click="picker.open = false" />
                    </div>
                </div>
            </div>
        </Stack>

        <!-- Draw a connection by hand, for a relation no addon can read. -->
        <Stack v-model:open="link.open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="t('map_draw_edge')" size="lg" />
                </div>
                <div class="flex-1 space-y-4 px-6 py-5">
                    <Field :label="t('map_draw_edge_from')"><Select v-model="link.from" :options="nodeOptions" /></Field>
                    <Field :label="t('map_draw_edge_to')"><Select v-model="link.to" :options="nodeOptions" /></Field>
                    <Field :label="t('map_edge_label')"><Input v-model="link.label" :placeholder="t('map_edge_hand_label')" maxlength="191" /></Field>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t('map_cancel')" @click="link.open = false" />
                        <Button variant="primary" :text="t('map_draw_edge_add')" :disabled="!link.from || !link.to || link.from === link.to" @click="commitLink" />
                    </div>
                </div>
            </div>
        </Stack>
    </div>
</template>

<style>
.lk-lede { margin: -0.5rem 0 1rem; max-width: 60ch; font-size: 0.9rem; color: var(--color-gray-700); }
.dark .lk-lede { color: var(--color-gray-300); }
.lk-shell { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; align-items: stretch; }
.lk-shell--panel { grid-template-columns: minmax(0, 1fr) 340px; }
.lk-board { height: calc(100vh - 15rem); min-height: 560px; border: 1px solid var(--color-gray-200); border-radius: 0.75rem; }
.dark .lk-board { border-color: var(--color-gray-700); }
.lk-defs { position: absolute; }

.lk-flags { display: flex; flex-direction: column; gap: 0.4rem; align-items: flex-start; margin: 0.9rem 0 0 0.9rem; pointer-events: none; }
.lk-flag {
    display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.7rem; border-radius: 999px;
    font-size: 0.78rem; font-weight: 600; pointer-events: auto;
    background: var(--color-white, #fff); border: 1px solid var(--color-gray-300); color: var(--color-gray-800);
}
.dark .lk-flag { background: var(--color-gray-800); border-color: var(--color-gray-600); color: var(--color-gray-200); }
.lk-flag--bad { border-color: var(--color-red-400); color: var(--color-red-700); background: var(--color-red-50); cursor: pointer; }
.dark .lk-flag--bad { color: var(--color-red-300); background: color-mix(in srgb, var(--color-red-500) 16%, var(--color-gray-900)); }
.lk-flag__swatch { width: 1.4rem; height: 0; border-top: 4px solid var(--color-green-700); border-radius: 2px; }
.dark .lk-flag__swatch { border-top-color: var(--color-green-400); }
.lk-flag--muted { font-weight: 500; color: var(--color-gray-600); }
.lk-flag--muted .lk-flag__swatch { border-top-color: var(--color-gray-400); border-top-style: dashed; border-top-width: 3px; }

.lk-legend-panel { margin: 0 0 0.9rem 0.9rem; }
.lk-controls { margin: 0 0.9rem 0.9rem 0; }
/* The minimap sits above the zoom bar, not under it. */
.lk-board .vue-flow__minimap { margin-bottom: 64px; }
.lk-legend {
    width: 25rem; max-width: 60vw; padding: 0.55rem 0.75rem; border-radius: 0.6rem; font-size: 0.74rem;
    background: var(--color-white, #fff); border: 1px solid var(--color-gray-200); color: var(--color-gray-800);
}
.dark .lk-legend { background: var(--color-gray-800); border-color: var(--color-gray-700); color: var(--color-gray-200); }
.lk-legend__title { display: flex; width: 100%; align-items: center; justify-content: space-between; font-weight: 700; font-size: 0.78rem; }
.lk-legend ul { list-style: none; margin: 0.4rem 0 0; padding: 0; }
.lk-legend__kinds { display: grid; grid-template-columns: repeat(3, auto); gap: 0.25rem 0.8rem; }
.lk-legend__kinds li, .lk-legend__lines li { display: flex; align-items: center; gap: 0.4rem; }
.lk-legend__lines { margin-top: 0.5rem; padding-top: 0.45rem; border-top: 1px solid var(--color-gray-200); display: grid; grid-template-columns: 1fr 1fr; gap: 0.25rem 0.8rem; }
.dark .lk-legend__lines { border-top-color: var(--color-gray-700); }
.lk-key { display: inline-block; width: 1.6rem; height: 0; border-top: 2px solid var(--color-gray-500); }
.lk-key--main { border-top: 4px solid var(--color-green-700); }
.lk-key--wait { border-top-style: dashed; }
.lk-key--bad { border-top: 2px dashed var(--color-red-500); }
.lk-key--hand { border-top: 2px dotted var(--color-gray-500); }
.lk-accent-text--blue { color: var(--color-blue-600); } .lk-accent-text--green { color: var(--color-emerald-600); }
.lk-accent-text--sky { color: var(--color-sky-600); } .lk-accent-text--indigo { color: var(--color-indigo-600); }
.lk-accent-text--fuchsia { color: var(--color-fuchsia-600); } .lk-accent-text--purple { color: var(--color-purple-600); }
.lk-accent-text--amber { color: var(--color-amber-600); } .lk-accent-text--gray { color: var(--color-gray-500); }

.lk-adder {
    display: flex; align-items: center; justify-content: center; gap: 0.4rem; width: 250px; padding: 0.7rem;
    border: 1.5px dashed var(--color-gray-400); border-radius: 0.75rem; font-size: 0.8rem; font-weight: 600;
    color: var(--color-gray-700); background: color-mix(in srgb, var(--color-white, #fff) 60%, transparent); cursor: pointer;
}
.dark .lk-adder { color: var(--color-gray-300); background: transparent; border-color: var(--color-gray-600); }
.lk-adder:hover { border-color: var(--color-blue-500); color: var(--color-blue-600); }

.lk-empty { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 0.75rem; align-items: center; justify-content: center; text-align: center; padding: 2rem; z-index: 5; }
.lk-empty p { max-width: 44ch; color: var(--color-gray-700); font-size: 0.9rem; }

.lk-panel {
    height: calc(100vh - 15rem); min-height: 560px; overflow-y: auto; padding: 1rem 1.1rem 1.2rem; border-radius: 0.75rem;
    background: var(--color-white, #fff); border: 1px solid var(--color-gray-200); font-size: 0.85rem; color: var(--color-gray-800);
}
.dark .lk-panel { background: var(--color-gray-800); border-color: var(--color-gray-700); color: var(--color-gray-200); }
.lk-panel__head { display: flex; align-items: center; justify-content: space-between; }
.lk-panel__title { margin: 0.55rem 0 0; font-size: 1.15rem; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.lk-panel__title--gone { text-decoration: line-through; }
.lk-panel__mono { margin: 0.2rem 0 0; font-family: var(--font-mono, ui-monospace, monospace); font-size: 0.74rem; color: var(--color-gray-600); overflow-wrap: anywhere; }
.dark .lk-panel__mono { color: var(--color-gray-400); }
.lk-panel__facts { margin: 1rem 0 0; display: grid; gap: 0.8rem; }
.lk-panel__facts dt { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-gray-600); }
.dark .lk-panel__facts dt { color: var(--color-gray-400); }
.lk-panel__facts dd { margin: 0.15rem 0 0; }
.lk-panel__facts strong { font-size: 1.4rem; font-variant-numeric: tabular-nums; }
.lk-panel__rule { font-size: 0.76rem; color: var(--color-gray-600); }
.dark .lk-panel__rule { color: var(--color-gray-400); }
.lk-panel__help { margin: 0.8rem 0 0; font-size: 0.8rem; color: var(--color-gray-700); }
.dark .lk-panel__help { color: var(--color-gray-300); }
.lk-bad { color: var(--color-red-700); font-weight: 600; }
.dark .lk-bad { color: var(--color-red-300); }
.lk-panel__section { margin-top: 1.1rem; padding-top: 0.9rem; border-top: 1px solid var(--color-gray-200); }
.dark .lk-panel__section { border-top-color: var(--color-gray-700); }
.lk-panel__section h3 { margin: 0 0 0.4rem; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-gray-600); }
.dark .lk-panel__section h3 { color: var(--color-gray-400); }
.lk-panel__problems { margin: 0; padding-left: 1.1rem; color: var(--color-red-700); }
.dark .lk-panel__problems { color: var(--color-red-300); }
.lk-panel__dir { margin: 0.5rem 0 0.2rem; font-size: 0.74rem; color: var(--color-gray-600); }
.dark .lk-panel__dir { color: var(--color-gray-400); }
.lk-panel__links { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.2rem; }
.lk-panel__links button, .lk-panel__path button { text-align: left; color: var(--color-blue-700); }
.dark .lk-panel__links button, .dark .lk-panel__path button { color: var(--color-blue-300); }
.lk-panel__links button:hover, .lk-panel__path button:hover { text-decoration: underline; }
.lk-panel__rel { display: inline-block; margin-right: 0.3rem; padding: 0 0.4rem; border-radius: 999px; background: var(--color-gray-100); color: var(--color-gray-700); font-size: 0.7rem; font-weight: 600; }
.dark .lk-panel__rel { background: var(--color-gray-700); color: var(--color-gray-200); }
.lk-panel__path { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; margin: 0.5rem 0 0; }
.lk-panel__actions, .lk-panel__move { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
.lk-panel__edit .lk-panel__move { margin-top: 0.5rem; }
.lk-tag {
    display: inline-block; padding: 0.05rem 0.55rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600;
    background: var(--color-gray-100); color: var(--color-gray-700);
}
.dark .lk-tag { background: var(--color-gray-700); color: var(--color-gray-200); }
.lk-accent-pill--blue { background: color-mix(in srgb, var(--color-blue-600) 13%, transparent); color: var(--color-blue-700); }
.lk-accent-pill--green { background: color-mix(in srgb, var(--color-emerald-600) 13%, transparent); color: var(--color-emerald-700); }
.lk-accent-pill--sky { background: color-mix(in srgb, var(--color-sky-600) 13%, transparent); color: var(--color-sky-700); }
.lk-accent-pill--indigo { background: color-mix(in srgb, var(--color-indigo-600) 13%, transparent); color: var(--color-indigo-700); }
.lk-accent-pill--fuchsia { background: color-mix(in srgb, var(--color-fuchsia-600) 13%, transparent); color: var(--color-fuchsia-700); }
.lk-accent-pill--purple { background: color-mix(in srgb, var(--color-purple-600) 13%, transparent); color: var(--color-purple-700); }
.lk-accent-pill--amber { background: color-mix(in srgb, var(--color-amber-600) 13%, transparent); color: var(--color-amber-800); }

@media (max-width: 1100px) {
    .lk-shell--panel { grid-template-columns: minmax(0, 1fr); }
    .lk-panel { height: auto; min-height: 0; }
}
</style>

<style>
.lk-minimap-lane { fill: var(--color-gray-200); opacity: 0.5; }
.dark .lk-minimap-lane { fill: var(--color-gray-700); }
</style>
