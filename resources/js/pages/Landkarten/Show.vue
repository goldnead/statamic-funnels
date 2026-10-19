<script setup>
import { computed, nextTick, provide, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { toast } from '@statamic/cms/api';
import { VueFlow, Panel as FlowPanel, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { ControlBar } from '@goldnead/flow-canvas';
import {
    Header, Button, Badge, Select, Input, Stack, Field, Heading, Icon, Description, Popover, Panel, Card, Alert,
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
 * dragging: every move is a labelled, keyboard-reachable action. Details of a
 * card or a line open in a Statamic stack, like an entry does.
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
const { fitView, getViewport, setViewport } = useVueFlow(FLOW);

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

const nodeGone = (key) => displayNodes.value.find((n) => n.key === key)?.status === 'missing';

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

    // A line is red when it ends at something that is gone, and on the main path when it joins two
    // neighbours of it, drawn by hand or derived.
    const way = props.mainPath.status === 'found' ? props.mainPath.node_keys ?? [] : [];
    const onWay = (e) => way.some((k, i) => k === e.source && way[i + 1] === e.target);

    return list.map((e) => ({
        ...e,
        bad: e.bad || !!nodeGone(e.target),
        main: e.main || onWay(e),
    }));
});

const layout = computed(() => layoutNodes(local.value.lanes.map((l) => ({ ...l })), displayNodes.value, { editing: editing.value }));
const flowNodes = computed(() => layout.value.flowNodes);
const flowEdgeList = computed(() => flowEdges(displayEdges.value, displayNodes.value, layout.value.rows));
const nodeByKey = computed(() => Object.fromEntries(displayNodes.value.map((n) => [n.key, n])));

// --- selection --------------------------------------------------------------

const selectedKey = ref(null);
const selectedEdge = ref(null);
// The stack can be closed while the way stays lit: a click on the empty board ends the selection.
const panelOpen = ref(false);

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
    panelOpen.value = true;
}

function selectEdge(id) {
    selectedKey.value = null;
    selectedEdge.value = id;
    panelOpen.value = true;
}

function clearSelection() {
    selectedKey.value = null;
    selectedEdge.value = null;
    panelOpen.value = false;
}

const selected = computed(() => (selectedKey.value ? nodeByKey.value[selectedKey.value] ?? null : null));
const edge = computed(() => (selectedEdge.value ? displayEdges.value.find((e) => e.id === selectedEdge.value) ?? null : null));

watch(displayNodes, (list) => {
    if (selectedKey.value && !list.some((n) => n.key === selectedKey.value)) clearSelection();
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

// A single click opens the stack, a double click opens the editor inside the
// map. The stack would sit on top of the second click, so the first click waits
// a moment to see whether a second one follows.
const DOUBLE_CLICK_MS = 230;
let clickTimer = null;

function onNodeClick({ node }) {
    if (node.type !== 'landkarte') return;
    clearTimeout(clickTimer);
    clickTimer = setTimeout(() => select(node.id), DOUBLE_CLICK_MS);
}

function onNodeDoubleClick({ node }) {
    if (node.type !== 'landkarte') return;
    clearTimeout(clickTimer);
    drill(node.data);
}

function onPaneClick() {
    clearTimeout(clickTimer);
    clearSelection();
}

function onStackToggle(open) {
    if (!open) panelOpen.value = false;
}

const stackTitle = computed(() => selected.value?.label ?? edge.value?.label ?? '');
const stackIcon = computed(() => (selected.value ? (selected.value.status === 'missing' ? 'warning-diamond' : kindOf(selected.value.type).icon) : 'link'));

// --- the reading aids -------------------------------------------------------

const mainText = computed(() => {
    if (props.mainPath.status === 'found') {
        const goal = nodeByKey.value[props.mainPath.goal];
        return t('map_main_path_found').replace(':goal', goal?.label ?? '');
    }
    return t(props.mainPath.status === 'unreachable' ? 'map_main_path_unreachable' : 'map_main_path_no_revenue');
});

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

// Where the board opens: everything in view, every phase visible, flush with
// the top of the board instead of floating in the middle of it.
function settleView() {
    nextTick(async () => {
        await fitView({ padding: 0.04, minZoom: 0.2, maxZoom: 1 });
        const { x, zoom } = getViewport();
        setViewport({ x, y: 16, zoom });
    });
}

const periodOptions = computed(() => props.period.options);
const helpLines = computed(() => [
    { key: 'main', text: t('map_legend_main') },
    { key: 'wait', text: t('map_legend_wait') },
    { key: 'hand', text: t('map_legend_hand') },
    { key: 'bad', text: t('map_legend_problem') },
]);
</script>

<template>
    <div class="lk-page" data-landkarte data-flow-full-bleed>
        <Head :title="[landkarte.name, t('landkarten_title', 'Maps')]" />

        <Header :title="landkarte.name" icon="travel-map">
            <Badge v-if="isDirty" color="default" :text="t('map_unsaved', 'Unsaved changes')" pill />
            <Popover align="end" class="w-80">
                <template #trigger>
                    <Button variant="ghost" icon="info" :aria-label="t('map_help')" :title="t('map_help')" data-lk-help />
                </template>
                <div class="space-y-3 p-1 text-sm" data-lk-help-body>
                    <Heading :text="t('map_help')" size="base" />
                    <p class="text-gray-900 dark:text-gray-100">{{ t('map_help_click') }}</p>
                    <ul class="space-y-1.5">
                        <li v-for="line in helpLines" :key="line.key" class="flex items-center gap-2">
                            <span class="lk-key" :class="`lk-key--${line.key}`" />
                            <span>{{ line.text }}</span>
                        </li>
                    </ul>
                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ mainText }}</p>
                </div>
            </Popover>
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

        <Description :text="t('landkarten_description')" class="mb-4" />

        <p v-if="errors && Object.keys(errors).length" class="mb-3 text-sm text-red-600" role="alert">{{ Object.values(errors)[0] }}</p>

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
                :default-edge-options="{ type: 'landkarte' }"
                class="fc-canvas"
                @pane-ready="settleView"
                @node-click="onNodeClick"
                @node-double-click="onNodeDoubleClick"
                @pane-click="onPaneClick"
            >
                <template #node-landkarte="slot"><MapNode v-bind="slot" :selected="selectedKey === slot.id" /></template>
                <template #node-lane="slot"><MapLane :data="slot.data" /></template>
                <template #node-adder="slot">
                    <button type="button" class="lk-adder nodrag nopan" data-lk-adder @click.stop="openPicker(slot.data.index)">
                        <Icon name="plus" class="size-4" /> {{ t('map_add_ref', 'Add object') }}
                    </button>
                </template>
                <template #edge-landkarte="slot"><MapEdge v-bind="slot" /></template>

                <Background pattern-color="var(--color-gray-300)" :gap="20" :size="1" />

                <FlowPanel position="bottom-right" class="lk-controls">
                    <ControlBar :flow-id="FLOW" />
                </FlowPanel>
            </VueFlow>

            <!-- Arrowheads, one per state of a line: a marker cannot inherit its line's colour. -->
            <svg class="lk-defs" aria-hidden="true" width="0" height="0">
                <defs>
                    <marker v-for="m in [['lk-arrow', 'var(--color-gray-400)'], ['lk-arrow-main', 'var(--color-primary)'], ['lk-arrow-on', 'var(--color-gray-600)'], ['lk-arrow-bad', 'var(--color-red-500)']]" :id="m[0]" :key="m[0]" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse" markerUnits="strokeWidth">
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

        <!-- Details of a card or a line: a Statamic stack, the way an entry opens. -->
        <Stack
            :open="panelOpen && !!(selected || edge)"
            size="narrow"
            :icon="stackIcon"
            :title="stackTitle"
            @update:open="onStackToggle"
        >
            <div v-if="selected" class="space-y-6 p-4" data-lk-panel>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :text="selected.type_label" />
                    <span v-if="selected.detail" class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ selected.detail }}</span>
                </div>

                <Alert v-if="selected.status === 'missing'" variant="error" :text="selected.ghost ? t('map_ghost_help') : t('map_broken_help')" />
                <Alert v-else-if="selected.status === 'unavailable'" variant="warning" :text="t('map_unavailable_help')" />
                <Alert v-if="(selected.problems ?? []).length" variant="error" :heading="t('map_panel_problems')">
                    <ul class="list-disc pl-4"><li v-for="p in selected.problems" :key="p">{{ p }}</li></ul>
                </Alert>

                <Panel>
                    <Card>
                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-gray-600 dark:text-gray-400">{{ t('map_panel_status') }}</dt>
                                <dd v-if="selected.status === 'missing'" class="text-red-600 dark:text-red-400">{{ selected.ghost ? t('map_dead_reference') : t('map_broken') }}</dd>
                                <dd v-else-if="selected.status === 'unavailable'">{{ t('map_unavailable') }}</dd>
                                <dd v-else>{{ selected.enabled === true ? t('map_on') : selected.enabled === false ? t('map_off') : t('map_no_switch') }}</dd>
                            </div>
                            <div v-if="selected.status === 'found'">
                                <dt class="text-xs text-gray-600 dark:text-gray-400">{{ t('map_panel_number') }}</dt>
                                <dd v-if="selected.metric" class="text-lg font-semibold tabular-nums">{{ Number(selected.metric.value).toLocaleString() }}</dd>
                                <dd v-else>{{ t('map_no_number') }}</dd>
                                <dd v-if="metricRule" class="text-xs text-gray-600 dark:text-gray-400">{{ t('map_panel_rule').replace(':rule', metricRule) }}</dd>
                            </div>
                        </dl>
                    </Card>
                </Panel>

                <Panel :heading="t('map_panel_connections')">
                    <Card>
                        <template v-if="connections.incoming.length || connections.outgoing.length">
                            <template v-for="group in [{ id: 'in', title: t('map_panel_in'), list: connections.incoming }, { id: 'out', title: t('map_panel_out'), list: connections.outgoing }]" :key="group.id">
                                <div v-if="group.list.length" class="mb-3 last:mb-0">
                                    <div class="mb-1 text-xs text-gray-600 dark:text-gray-400">{{ group.title }}</div>
                                    <ul class="space-y-1 text-sm">
                                        <li v-for="c in group.list" :key="c.edge.id" class="flex items-baseline gap-2">
                                            <span v-if="group.id === 'out'" class="shrink-0 text-xs text-gray-600 dark:text-gray-400">{{ c.edge.label }}</span>
                                            <button
                                                type="button"
                                                class="min-w-0 truncate text-left font-medium hover:underline"
                                                @click="c.other && !c.other.ghost ? select(c.other.key) : selectEdge(c.edge.id)"
                                            >{{ c.other?.label ?? (group.id === 'in' ? c.edge.source : c.edge.target) }}</button>
                                        </li>
                                    </ul>
                                </div>
                            </template>
                        </template>
                        <p v-else class="text-sm text-gray-600 dark:text-gray-400">{{ t('map_panel_none') }}</p>
                    </Card>
                </Panel>

                <Panel v-if="editing && !selected.ghost" :heading="t('map_edit')">
                    <Card class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" icon="arrow-left" :disabled="laneOf <= 0" :aria-label="t('map_move_left')" @click="moveCardToLane(selected.key, -1)" />
                            <Button size="sm" icon="arrow-up" :disabled="atTop" :aria-label="t('map_move_up')" @click="moveCard(selected.key, -1)" />
                            <Button size="sm" icon="arrow-down" :disabled="atBottom" :aria-label="t('map_move_down')" @click="moveCard(selected.key, 1)" />
                            <Button size="sm" icon="arrow-right" :disabled="laneOf < 0 || laneOf >= local.lanes.length - 1" :aria-label="t('map_move_right')" @click="moveCardToLane(selected.key, 1)" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" icon="link" :text="t('map_draw_edge_from')" @click="openLink(selected.key)" />
                            <Button size="sm" variant="danger" icon="trash" :text="t('map_remove_ref')" @click="removeCard(selected.key)" />
                        </div>
                    </Card>
                </Panel>
            </div>

            <div v-else-if="edge" class="space-y-6 p-4" data-lk-panel>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :text="edge.hand ? t('map_edge_origin_hand') : t('map_edge_origin_derived')" />
                    <Badge v-if="edge.main" :text="t('map_legend_main')" />
                </div>

                <Panel>
                    <Card>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <button type="button" class="font-medium hover:underline" @click="select(edge.source)">{{ nodeByKey[edge.source]?.label }}</button>
                            <Icon name="arrow-right" class="size-3.5 text-gray-600 dark:text-gray-400" />
                            <button type="button" class="font-medium hover:underline" @click="select(edge.target)">{{ nodeByKey[edge.target]?.label }}</button>
                        </div>
                    </Card>
                </Panel>

                <Panel :heading="t('map_edge_number')">
                    <Card>
                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ t('map_edge_number_none') }}</p>
                        <p v-if="!edge.hand && edge.derived_from" class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ t('map_edge_origin_derived') }}: {{ edge.derived_from }}</p>
                    </Card>
                </Panel>

                <p v-if="edge.main" class="text-sm text-gray-900 dark:text-gray-100">{{ t('map_main_path_title') }}</p>

                <div v-if="editing && edge.hand">
                    <Button variant="danger" icon="trash" :text="t('map_remove_edge')" @click="removeHand(edge.hand_index)" />
                </div>
            </div>

            <template #footer-end>
                <template v-if="selected">
                    <Button v-if="selected.cp_url" :text="t('map_open_editor')" :href="selected.cp_url" />
                    <Button v-if="selected.drill_url" variant="primary" icon="arrow-right" :text="t(`map_open_inside_${selected.type}`, t('map_open_inside'))" @click="drill(selected)" />
                </template>
            </template>
        </Stack>

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
.lk-board { height: calc(100vh - 13rem); min-height: 460px; border: 1px solid var(--color-gray-200); border-radius: 0.75rem; }
.dark .lk-board { border-color: var(--color-gray-700); }
.lk-defs { position: absolute; }
.lk-controls { margin: 0 0.9rem 0.9rem 0; }

.lk-key { display: inline-block; width: 1.6rem; height: 0; border-top: 1.5px solid var(--color-gray-400); }
.lk-key--main { border-top: 2.25px solid var(--color-primary); }
.lk-key--wait { border-top-style: dashed; }
.lk-key--bad { border-top: 1.75px dashed var(--color-red-500); }
.lk-key--hand { border-top: 2px dotted var(--color-gray-400); }

.lk-adder {
    display: flex; align-items: center; justify-content: center; gap: 0.4rem; width: 232px; padding: 0.6rem;
    border: 1px dashed var(--color-gray-300); border-radius: 0.75rem; font-size: 0.8rem;
    color: var(--color-gray-600); background: transparent; cursor: pointer;
}
.dark .lk-adder { color: var(--color-gray-400); border-color: var(--color-gray-600); }
.lk-adder:hover { border-color: var(--color-gray-500); color: var(--color-gray-900); }
.dark .lk-adder:hover { color: var(--color-gray-100); }

.lk-empty { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 0.75rem; align-items: center; justify-content: center; text-align: center; padding: 2rem; z-index: 5; }
.lk-empty p { max-width: 44ch; color: var(--color-gray-700); font-size: 0.9rem; }
.dark .lk-empty p { color: var(--color-gray-300); }
</style>
