<script setup>
import { computed, nextTick, onBeforeUnmount, provide, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { toast } from '@statamic/cms/api';
import { VueFlow, Panel as FlowPanel, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { ControlBar } from '@goldnead/flow-canvas';
import {
    Header, Button, Badge, Select, Input, Stack, Field, Heading, Icon, Description, Popover, Panel, Card, Alert, ConfirmationModal,
} from '@statamic/cms/ui';
import MapNode from '../../components/landkarte/MapNode.vue';
import MapLane from '../../components/landkarte/MapLane.vue';
import MapEdge from '../../components/landkarte/MapEdge.vue';
import {
    connectOutcome, dropSlot, flowEdges, kindOf, layoutNodes, moveRef, strokeOf, targetsFor, wayThrough,
} from '../../support/landkarteLayout.js';
import { postJson } from '../../support/landkarteRequest.js';

/**
 * A Landkarte.
 *
 * Phase lanes run left to right, each card is an object that lives in another
 * addon, each line is a connection that addon can read from its own data (or
 * one drawn by hand). The page holds a type and an id per card and nothing
 * else: label, link, switch, number and broken references come from the owner on
 * every load.
 *
 * There is no edit mode. A card can always be dragged (to another phase or
 * another place in its phase) and the map saves itself a moment later; the
 * buttons in a card's panel do the same without a pointer. An arrow dragged from
 * the grip of a card to another card sets up the real connection in the addon
 * that owns it, after asking in plain words what that does. Details of a card or
 * a line open in a Statamic stack, like an entry does.
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
    detailsUrl: { type: String, required: true },
    connectionRules: { type: Array, default: () => [] },
    previewUrl: { type: String, default: '' },
    connectUrl: { type: String, default: '' },
    showUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    labels: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;
const clone = (value) => JSON.parse(JSON.stringify(value));
const FLOW = 'landkarte';
const { fitView, getViewport, setViewport, setNodes } = useVueFlow(FLOW);

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
const isDirty = computed(() => JSON.stringify(local.value) !== saved.value);

// Someone else saved this map since this page loaded: nothing is written until it is reloaded.
const conflict = ref(false);
// What was refused for another reason than a conflict, so the same picture is not sent again in a loop.
let failed = null;
const failedNow = ref(false);

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

// --- saving by itself -------------------------------------------------------

const SAVE_AFTER_MS = 800;
let saveTimer = null;

const saveState = computed(() => {
    if (conflict.value) return 'conflict';
    if (saving.value) return 'saving';
    if (isDirty.value && failedNow.value) return 'error';
    if (isDirty.value) return 'saving';
    return 'saved';
});

function saveSoon() {
    clearTimeout(saveTimer);
    if (conflict.value) return;
    saveTimer = setTimeout(save, SAVE_AFTER_MS);
}

// Every change to the picture is saved a moment after the last one; one request at a time.
watch(
    () => JSON.stringify(local.value),
    (now) => {
        failedNow.value = now === failed;
        if (now === saved.value || now === failed) return;
        saveSoon();
    },
);

function save() {
    clearTimeout(saveTimer);
    if (saving.value || conflict.value || !isDirty.value) return;

    saving.value = true;
    sent = JSON.stringify(local.value);
    const attempt = sent;

    router.put(
        props.saveUrl,
        { version: props.version, name: local.value.name, stages: local.value.lanes, edges: local.value.hand },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { failed = null; failedNow.value = false; },
            onError: (errors) => {
                sent = null;
                const messages = Object.values(errors ?? {}).map(String);

                if (messages.includes(t('map_conflict'))) {
                    conflict.value = true;
                    return;
                }

                failed = attempt;
                failedNow.value = true;
                toast.error(messages[0] ?? t('map_save_failed', 'The map could not be saved.'), {});
            },
            onFinish: () => {
                saving.value = false;
                // Something was changed while the request was on its way.
                if (isDirty.value && !conflict.value && JSON.stringify(local.value) !== failed) saveSoon();
            },
        },
    );
}

// Reload what the server has now and drop what this page was holding.
function reloadMap() {
    clearTimeout(saveTimer);
    router.get(props.showUrl, { days: props.period.days }, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            local.value = modelFrom(props);
            saved.value = JSON.stringify(local.value);
            added.value = {};
            failed = null;
            failedNow.value = false;
            conflict.value = false;
        },
    });
}

onBeforeUnmount(() => { clearTimeout(saveTimer); clearTimeout(clickTimer); clearTimeout(timer); });

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
            id: `hand:${i}`, source, target, kind: 'hand', label: h.label || t('map_edge_note_label', 'Note'),
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

const layout = computed(() => layoutNodes(local.value.lanes.map((l) => ({ ...l })), displayNodes.value, { adders: true, draggable: !conflict.value }));
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

// What the owning addon adds for the open card (a course's lessons, an automation's
// mails, a price). Asked once per card and period when its panel opens, never for the
// whole map; a failed ask leaves the panel as it was.
const detailsCache = ref({});
const detailsKey = computed(() => (selected.value?.status === 'found' && !selected.value.ghost
    ? `${selected.value.type}:${selected.value.ref_id}:${props.period.days}`
    : null));
const details = computed(() => (detailsKey.value ? detailsCache.value[detailsKey.value] ?? null : null));

watch(detailsKey, async (key) => {
    if (!key || detailsCache.value[key]) return;

    const { type, ref_id: refId } = selected.value;
    detailsCache.value = { ...detailsCache.value, [key]: { loading: true, failed: false, blocks: [] } };

    let result = { loading: false, failed: true, blocks: [] };

    try {
        const url = new URL(props.detailsUrl, window.location.origin);
        url.searchParams.set('type', type);
        url.searchParams.set('ref_id', refId);
        url.searchParams.set('days', props.period.days);

        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (response.ok) result = { loading: false, failed: false, blocks: (await response.json()).blocks ?? [] };
    } catch {
        // keep the failed result
    }

    detailsCache.value = { ...detailsCache.value, [key]: result };
}, { immediate: true });

function drill(node) {
    if (node?.drill_url) router.visit(node.drill_url);
}

// A single click opens the stack, a double click opens the editor inside the
// map. The stack would sit on top of the second click, so the first click waits
// a moment to see whether a second one follows.
const DOUBLE_CLICK_MS = 230;
let clickTimer = null;

function onNodeClick({ node }) {
    if (node.type !== 'landkarte' || Date.now() - lastDragEnd < 150) return;
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
    const lane = local.value.lanes[index];
    ask({
        title: t('map_remove_stage'),
        body: `${lane?.name ?? ''}: ${t('map_remove_stage_confirm', 'Remove this phase?')}`,
        button: t('map_remove_stage'),
        danger: true,
        run: () => { local.value.lanes.splice(index, 1); },
    });
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

// --- asking before something is done ------------------------------------------

// One Statamic confirmation for everything that needs a yes: removing a phase,
// making a connection, undoing one.
const confirmation = ref({ open: false, busy: false, title: '', body: '', button: '', danger: false, run: null });

function ask({ title, body, button, danger = false, run }) {
    confirmation.value = { open: true, busy: false, title, body, button, danger, run };
}

async function confirmed() {
    const { run } = confirmation.value;
    confirmation.value.busy = true;

    try {
        await run?.();
    } finally {
        confirmation.value.open = false;
        confirmation.value.busy = false;
    }
}

function onConfirmOpen(open) {
    if (!confirmation.value.busy) confirmation.value.open = open;
}

// --- dragging cards -------------------------------------------------------------

let lastDragEnd = 0;

function onNodeDragStop({ node }) {
    lastDragEnd = Date.now();
    if (node.type !== 'landkarte') return;

    const next = moveRef(local.value.lanes, node.id, dropSlot(local.value.lanes, node.id, node.position));
    if (next) local.value.lanes = next;

    // A card dropped where it was, or in a place it cannot take, goes back to its slot.
    nextTick(() => setNodes(flowNodes.value));
}

// --- drawing a real connection ----------------------------------------------------

const canConnect = computed(() => props.connectionRules.length > 0 && !!props.previewUrl && !!props.connectUrl && !conflict.value);

// The card an arrow is being drawn from, while the pointer is down on its grip.
const connecting = ref(null);
const targets = computed(() => (connecting.value ? targetsFor(props.connectionRules, displayNodes.value, connecting.value) : new Set()));

const typeName = (type) => displayNodes.value.find((n) => n.type === type)?.type_label ?? props.types.find((x) => x.value === type)?.label ?? type;

function onConnectStart({ nodeId }) {
    clearTimeout(clickTimer);
    connecting.value = nodeId;
}

function pointOf(event) {
    const p = event?.changedTouches?.[0] ?? event;
    return p && Number.isFinite(p.clientX) ? [p.clientX, p.clientY] : null;
}

function cardUnder(event) {
    const at = pointOf(event);
    if (!at) return null;

    for (const el of document.elementsFromPoint(at[0], at[1])) {
        const key = el.closest?.('[data-lk-node]')?.getAttribute('data-lk-node');
        if (key) return key;
    }
    return null;
}

function onConnectEnd(event) {
    const from = connecting.value;
    connecting.value = null;
    if (!from) return;

    const toKey = cardUnder(event);
    if (!toKey || toKey === from) return;

    const a = nodeByKey.value[from];
    const b = nodeByKey.value[toKey];
    const outcome = connectOutcome(props.connectionRules, a, b);

    if (!outcome.ok) {
        toast.info(refusalText(outcome, a, b), { duration: 6000 });
        return;
    }

    previewConnection(a, b);
}

function refusalText(outcome, a, b) {
    if (outcome.reason === 'gone') return t('map_connect_gone');

    const text = outcome.reason === 'none'
        ? t('map_connect_none').replace(':from', typeName(a.type))
        : t('map_connect_pair').replace(':from', typeName(a.type)).replace(':to', typeName(b.type));
    const allowed = outcome.allowed.map(typeName).join(', ');

    return outcome.reason === 'pair' && allowed ? `${text} ${t('map_connect_allowed').replace(':types', allowed)}` : text;
}

const bodyOf = (a, b) => {
    const [from_type, from_id] = splitKey(a.key);
    const [to_type, to_id] = splitKey(b.key);
    return { from_type, from_id, to_type, to_id };
};

// What the server said no with, as a sentence; a refused request never fails silently.
function refuse(response) {
    // Somebody else got there first (another tab, another user): say so, and show the
    // map as it is now, so the next try starts from what is really stored.
    if (response.status === 409) {
        toast.error(response.data?.message || t('map_connect_conflict'), { duration: 8000 });
        reloadMap();
        return;
    }

    const fallback = response.status === 403 ? t('map_connect_forbidden') : t('map_connect_failed');
    toast.error(response.data?.message || fallback, { duration: 8000 });
}

async function previewConnection(a, b, action = 'connect') {
    const body = { action, ...bodyOf(a, b) };
    const response = await postJson(props.previewUrl, body);

    if (!response.ok) return refuse(response);

    ask({
        title: action === 'connect' ? t('map_connect_title') : t('map_disconnect_title'),
        body: response.data.consequence,
        button: action === 'connect' ? t('map_connect_do') : t('map_disconnect_do'),
        danger: action === 'disconnect',
        // What the user is shown and confirms: the write refuses when the field says something else by then.
        run: () => writeConnection({ ...body, expected_before: response.data.before ?? '' }, action),
    });
}

async function writeConnection(body, action) {
    const response = await postJson(props.connectUrl, { ...body, action, version: props.version });
    if (!response.ok) return refuse(response);

    router.reload({ preserveScroll: true, preserveState: true });
    if (action === 'disconnect') clearSelection();
    offerUndo(response.data, action);
}

// "Connected." stays for a while with a button that puts it back as it was.
function offerUndo(result, action) {
    const undo = result?.undo;
    const text = action === 'connect' ? t('map_connected') : t('map_disconnected');

    toast.success(text, {
        duration: UNDO_MS,
        className: 'lk-toast',
        action: undo
            ? [
                { text: t('map_undo'), onClick: (e, toastObject) => { toastObject.goAway(0); undoConnection(undo); } },
                { text: '×', onClick: (e, toastObject) => toastObject.goAway(0) },
            ]
            : undefined,
    });
}

const UNDO_MS = 10000;

async function undoConnection(undo) {
    // `rest` carries `restore` when the owner handed back what the disconnect removed: it goes back as it came.
    const { action, ...rest } = undo;
    const response = await postJson(props.connectUrl, { ...rest, action, version: props.version });
    if (!response.ok) return refuse(response);

    router.reload({ preserveScroll: true, preserveState: true });
    toast.info(t('map_undone'), { duration: 4000 });
}

// "Remove connection" in a line's panel: ask first, with what it would undo.
function disconnectEdge(e) {
    if (!e?.disconnectable) return;
    previewConnection(
        { key: `${e.from_type}:${e.from_id}` },
        { key: `${e.to_type}:${e.to_id}` },
        'disconnect',
    );
}

provide('landkarte', {
    t, lit, litEdges, dimmed, selectedEdge, select, selectEdge, openLane, moveLane, removeLane, openPicker,
    connecting, canConnect, targets,
    // A card of a type no rule starts from gets no grip: offering a handle that can only refuse is noise.
    startsFrom: (type) => props.connectionRules.some((rule) => rule.from_type === type),
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
    { key: 'real', text: t('map_legend_real') },
    { key: 'note', text: t('map_legend_note') },
    { key: 'wait', text: t('map_legend_wait') },
    { key: 'bad', text: t('map_legend_problem') },
]);
</script>

<template>
    <div class="lk-page" data-landkarte data-flow-full-bleed>
        <Head :title="[landkarte.name, t('landkarten_title', 'Maps')]" />

        <Header :title="landkarte.name" icon="travel-map">
            <span class="lk-save" :class="`lk-save--${saveState}`" role="status" aria-live="polite" data-lk-save-state>
                {{ saveState === 'saving' ? t('map_saving', 'Saving…') : saveState === 'conflict' || saveState === 'error' ? t('map_not_saved', 'Not saved') : t('map_saved_state', 'Saved') }}
            </span>
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
                :disabled="isDirty || saving"
                :aria-label="t('map_period', 'Period')"
                @update:model-value="setPeriod"
            />
            <Button :text="t('map_note_edge', 'Note arrow')" icon="link" :disabled="!displayNodes.length" @click="openLink()" />
            <Button :text="t('map_add_stage', 'Add phase')" icon="plus" @click="openLane()" />
        </Header>

        <Description :text="t('landkarten_description')" class="mb-4" />

        <Alert v-if="conflict" variant="warning" class="mb-3" data-lk-conflict>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ t('map_conflict_banner') }}</span>
                <Button size="sm" :text="t('map_reload')" @click="reloadMap" />
            </div>
        </Alert>
        <p v-else-if="errors && Object.keys(errors).length" class="mb-3 text-sm text-red-600" role="alert">{{ Object.values(errors)[0] }}</p>

        <div class="lk-board sa-canvas-frame" data-landkarte-board>
            <VueFlow
                :id="FLOW"
                :nodes="flowNodes"
                :edges="flowEdgeList"
                :nodes-draggable="!conflict"
                :nodes-connectable="canConnect"
                :node-drag-threshold="4"
                :connection-radius="0"
                :connection-line-style="{ stroke: 'var(--color-gray-500)', strokeWidth: 1.5, strokeDasharray: '4 4' }"
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
                @node-drag-stop="onNodeDragStop"
                @connect-start="onConnectStart"
                @connect-end="onConnectEnd"
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
                <Button icon="plus" :text="t('map_add_stage')" @click="openLane()" />
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
                    <span v-if="selected.detail" class="text-sm text-gray-600 dark:text-gray-400">{{ selected.detail }}</span>
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

                <p v-if="details?.loading" class="text-sm text-gray-600 dark:text-gray-400" data-lk-details-loading>{{ t('map_details_loading') }}</p>
                <p v-else-if="details?.failed" class="text-sm text-gray-600 dark:text-gray-400">{{ t('map_details_failed') }}</p>
                <Panel v-for="(block, i) in details?.blocks ?? []" :key="i" :heading="block.title || undefined" data-lk-details>
                    <Card>
                        <dl v-if="block.kind === 'facts'" class="space-y-3 text-sm">
                            <div v-for="row in block.rows" :key="row.label">
                                <dt class="text-xs text-gray-600 dark:text-gray-400">{{ row.label }}</dt>
                                <dd class="break-words">
                                    <a v-if="row.href" :href="row.href" target="_blank" rel="noopener" class="hover:underline">{{ row.value }}</a>
                                    <template v-else>{{ row.value }}</template>
                                </dd>
                            </div>
                        </dl>
                        <template v-else-if="block.kind === 'list'">
                            <ol class="space-y-3 text-sm">
                                <li v-for="(item, n) in block.items" :key="n" class="flex items-start gap-3">
                                    <img v-if="item.image" :src="item.image" alt="" loading="lazy" class="h-12 w-20 shrink-0 rounded border border-gray-200 object-cover object-top dark:border-gray-700" />
                                    <div class="min-w-0">
                                        <div class="font-medium break-words">{{ item.title }}</div>
                                        <div v-if="item.meta" class="text-xs text-gray-600 dark:text-gray-400">{{ item.meta }}</div>
                                    </div>
                                </li>
                            </ol>
                            <p v-if="block.more" class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ t('map_details_more').replace(':count', block.more) }}</p>
                        </template>
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

                <Panel v-if="!selected.ghost" :heading="t('map_edit')">
                    <Card class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" icon="arrow-left" :disabled="laneOf <= 0" :aria-label="t('map_move_left')" @click="moveCardToLane(selected.key, -1)" />
                            <Button size="sm" icon="arrow-up" :disabled="atTop" :aria-label="t('map_move_up')" @click="moveCard(selected.key, -1)" />
                            <Button size="sm" icon="arrow-down" :disabled="atBottom" :aria-label="t('map_move_down')" @click="moveCard(selected.key, 1)" />
                            <Button size="sm" icon="arrow-right" :disabled="laneOf < 0 || laneOf >= local.lanes.length - 1" :aria-label="t('map_move_right')" @click="moveCardToLane(selected.key, 1)" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" icon="link" :text="t('map_note_edge_from')" @click="openLink(selected.key)" />
                            <Button size="sm" variant="danger" icon="trash" :text="t('map_remove_ref')" @click="removeCard(selected.key)" />
                        </div>
                    </Card>
                </Panel>
            </div>

            <div v-else-if="edge" class="space-y-6 p-4" data-lk-panel>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :text="edge.hand ? t('map_edge_origin_note') : t('map_edge_origin_derived')" />
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

                <Alert v-if="edge.bad" variant="error" :text="t('map_dead_stub_help')" data-lk-dead-help />

                <p v-if="edge.main" class="text-sm text-gray-900 dark:text-gray-100">{{ t('map_main_path_title') }}</p>

                <p v-if="edge.hand" class="text-sm text-gray-600 dark:text-gray-400">{{ t('map_note_edge_help') }}</p>
                <p v-else-if="!edge.disconnectable" class="text-sm text-gray-600 dark:text-gray-400">{{ t('map_edge_fixed_help') }}</p>

                <div v-if="edge.hand">
                    <Button variant="danger" icon="trash" :text="t('map_remove_edge')" @click="removeHand(edge.hand_index)" />
                </div>
                <div v-else-if="edge.disconnectable">
                    <Button variant="danger" icon="trash" :text="t('map_disconnect')" @click="disconnectEdge(edge)" />
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
                    <Heading :text="t('map_note_edge')" size="lg" />
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

        <!-- Every yes the map asks for: a removed phase, a new connection, an undone one. -->
        <ConfirmationModal
            :open="confirmation.open"
            :title="confirmation.title"
            :body-text="confirmation.body"
            :button-text="confirmation.button"
            :cancel-text="t('map_cancel', 'Cancel')"
            :danger="confirmation.danger"
            :busy="confirmation.busy"
            data-lk-confirm
            @update:open="onConfirmOpen"
            @confirm="confirmed"
        />
    </div>
</template>

<style>
.lk-board { height: calc(100vh - 13rem); min-height: 460px; border: 1px solid var(--color-gray-200); border-radius: 0.75rem; }
.dark .lk-board { border-color: var(--color-gray-700); }
.lk-defs { position: absolute; }
.lk-controls { margin: 0 0.9rem 0.9rem 0; }

/* The toast's own button style is a shouting grey; here it must read as the one thing to click. */
.toasted-container .toasted.lk-toast button.action {
    margin-left: 0.75rem; padding: 0.15rem 0.4rem; font-size: 0.875rem; font-weight: 600; letter-spacing: 0;
    text-transform: none; opacity: 1; color: var(--color-gray-900); text-decoration: underline; text-underline-offset: 3px;
}
.toasted-container .toasted.lk-toast button.action:hover { color: var(--color-gray-600); }
.dark .toasted-container .toasted.lk-toast button.action { color: var(--color-white); }

.lk-save { font-size: 0.8rem; color: var(--color-gray-600); }
.dark .lk-save { color: var(--color-gray-400); }
.lk-save--conflict, .lk-save--error { color: var(--color-red-600); }

.lk-key { display: inline-block; width: 1.6rem; height: 0; border-top: 1.5px solid var(--color-gray-400); }
.lk-key--main { border-top: 2.25px solid var(--color-primary); }
.lk-key--real { border-top: 1.5px solid var(--color-gray-400); }
.lk-key--note { border-top: 1.5px dashed var(--color-gray-500); }
.lk-key--wait { border-top: 2px dotted var(--color-gray-500); }
.lk-key--bad { border-top: 1.75px solid var(--color-red-500); }

/*
 * The confirmation is Statamic's own component (the one core uses), set in the middle of the
 * screen and as one card: the frame and the card inside it would otherwise be two boxes, and
 * the buttons sit in the frame's padding under the text.
 */
[data-lk-confirm][data-ui-modal-content] {
    top: 50%; translate: -50% -50%; max-width: 28rem; padding: 0;
    background: var(--color-white); border: 1px solid var(--color-gray-300); backdrop-filter: none;
}
.dark [data-lk-confirm][data-ui-modal-content] { background: var(--color-gray-800); border-color: var(--color-gray-700); }
[data-lk-confirm][data-ui-modal-content] > div:first-child {
    border: 0; background: transparent; box-shadow: none; border-radius: 0; padding: 1.25rem 1.25rem 0.75rem;
}
[data-lk-confirm][data-ui-modal-content] > div:not(:first-child) { padding: 0 1.25rem 1.25rem; }

.lk-adder {
    display: flex; align-items: center; justify-content: center; gap: 0.4rem; width: 204px; padding: 0.6rem;
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
