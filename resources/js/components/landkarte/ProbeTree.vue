<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import { Alert, Badge, Button, Description, Heading, Select } from '@statamic/cms/ui';
import ProbeDetail from './ProbeDetail.vue';
import ProbeNode from './ProbeNode.vue';
import ProbeStack from './ProbeStack.vue';
import ProbeSteps from './ProbeSteps.vue';
import {
    firstToOpen, isExperienced, signatureOf, timeInDay as timeInDayText, timeWords as timeText, unknownCards, untilDay,
} from '../../support/probelauf.js';
import { postJson } from '../../support/landkarteRequest.js';

/**
 * The dry run of a Landkarte ("Probelauf").
 *
 * Planned on the server from what the owning addons say they would do; nothing here sends, saves or starts anything. It
 * opens on one thing: a test person who gets the map's first lead magnet. On the left what happens to her, by day; on the
 * right what she sees at the step that is chosen. Everything about who she is lives in a stack ("Person anpassen"), and
 * the tree of every way through the map is a link away. Loaded when the tab opens and again whenever the person, the start
 * or what she does changes.
 */
const props = defineProps({
    url: { type: String, required: true },
    mailUrl: { type: String, default: null },
    pageUrl: { type: String, default: null },
    // Where a real person can be found and copied from; null when no addon knows real people.
    personsUrl: { type: String, default: null },
    labels: { type: Object, default: () => ({}) },
});

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

provide('probelauf', {
    t,
    timeWords: (seconds) => timeText(seconds, t),
    timeInDay: (seconds) => timeInDayText(seconds, t),
});

// Her way (one line, by day) or every way through the map (a tree).
const view = ref('person');
const state = ref('loading');
// Once there is something to show, planning again does not take it away.
const loadedOnce = ref(false);

// The test person: what the stack lets the operator say about her. She lives in the request and nowhere else.
const who = ref('test');
const tags = ref([]);
const access = ref([]);
const ran = ref([]);
const actions = ref([]);
const unsubscribed = ref(false);
// "As for a real person": what was copied from her beyond the form's fields, and who she is for the page.
const extraTraits = ref({});
const copiedFrom = ref(null);
const missing = ref([]);
const stackOpen = ref(false);

// Fast forward: the day up to which her way is shown (null: all of it).
const until = ref(null);
const selectedSig = ref(null);
// Mails that had nothing to show while nobody had chosen one: the screen passes over them and opens on the next.
const noPreview = ref(new Set());

const start = ref(null);
const starts = ref([]);
const products = ref([]);
const automations = ref([]);
const options = ref({ access: [], tags: [] });
const timeline = ref({ entries: [], stats: { entries: 0, days: [], cut: 0 } });
const tree = ref(null);
const treeState = ref('idle');

// What the planner takes of a person's actions; the page does not offer more.
const MAX_ACTIONS = 12;

// Everything that makes her this person, as the body of a request: the way is planned from it and so is her mail. A body, not a
// URL: what describes a real person must not land in an access log.
function personBody() {
    const body = {
        tags: tags.value,
        access: access.value,
        ran: ran.value,
        actions: actions.value.map((a) => ({
            type: a.type, mail: a.mail, product: a.product ?? null, url: a.url ?? null, after_seconds: a.after_seconds ?? 0,
        })),
    };

    if (unsubscribed.value) body.unsubscribed = true;
    if (Object.keys(extraTraits.value).length) body.traits = extraTraits.value;

    return body;
}

// What she is, without what she does next: another person is another mail and page, a click after the mail is not.
const personSig = computed(() => JSON.stringify([tags.value, access.value, ran.value, unsubscribed.value, extraTraits.value]));

let ticket = 0;

async function load() {
    const mine = ++ticket;
    state.value = 'loading';

    try {
        const response = await postJson(props.url, { person: 'test', ...personBody(), ...(start.value ? { start: start.value } : {}) });

        if (!response.ok) throw new Error(String(response.status));

        const data = response.data;
        // A slower answer to an earlier change must not overwrite the answer to the latest.
        if (mine !== ticket) return;

        starts.value = data.starts ?? [];
        timeline.value = { entries: data.entries, stats: data.stats };
        start.value = data.start;
        products.value = data.products ?? [];
        automations.value = data.automations ?? [];
        options.value = data.options ?? { access: [], tags: [] };

        // An action tied to a mail that is no longer on her way (another start, a changed person) has nothing to act on.
        const keys = new Set(data.entries.map((e) => e.meta?.mail_key).filter(Boolean));
        if (actions.value.some((a) => !keys.has(a.mail))) actions.value = actions.value.filter((a) => keys.has(a.mail));

        state.value = 'ready';
        loadedOnce.value = true;
    } catch {
        if (mine === ticket) state.value = 'failed';
    }
}

// The tree of every way is planned in the background once the first screen is there: the link says how many there are.
async function loadTree() {
    if (treeState.value === 'loading' || treeState.value === 'ready') return;

    treeState.value = 'loading';

    try {
        const response = await postJson(props.url, { person: 'unknown' });

        if (!response.ok) throw new Error(String(response.status));

        tree.value = { roots: response.data.roots, stats: response.data.stats };
        treeState.value = 'ready';
    } catch {
        treeState.value = 'failed';
    }
}

let pause = null;

// Several changes in a row (a chip after a chip) plan once, a moment after the last.
function planSoon() {
    clearTimeout(pause);
    pause = setTimeout(load, 200);
}

onBeforeUnmount(() => clearTimeout(pause));
onMounted(async () => {
    await load();
    loadTree();
});

// --- who she is ----------------------------------------------------------------------------------------------------------

function onPatch(patch) {
    if ('tags' in patch) tags.value = patch.tags;
    if ('access' in patch) access.value = patch.access;
    if ('ran' in patch) ran.value = patch.ran;
    if ('unsubscribed' in patch) unsubscribed.value = patch.unsubscribed;

    planSoon();
}

// What a real person said, into the form. Everything stays editable; the way is planned from the form.
function copyPerson(data) {
    missing.value = data.missing ?? [];
    tags.value = data.tags ?? [];
    access.value = data.access ?? [];
    ran.value = data.ran ?? [];
    unsubscribed.value = Boolean(data.unsubscribed);
    extraTraits.value = data.traits ?? {};
    copiedFrom.value = data.label;
    actions.value = [];
    load();
}

function resetPerson() {
    missing.value = [];
    tags.value = [];
    access.value = [];
    ran.value = [];
    unsubscribed.value = false;
    extraTraits.value = {};
    copiedFrom.value = null;
    actions.value = [];
    who.value = 'test';
    load();
}

function chooseWho(value) {
    if (!value || value === who.value) return;

    who.value = value;

    if (value === 'test' && copiedFrom.value) resetPerson();
}

// The person in the sentence: the test person, the real one she was copied from, or a way to look for one.
const FIND = '__find';
const personOptions = computed(() => [
    { value: 'test', label: t('probe_person_test') },
    ...(copiedFrom.value ? [{ value: 'real', label: copiedFrom.value }] : []),
    ...(props.personsUrl && !copiedFrom.value ? [{ value: FIND, label: t('probe_person_find') }] : []),
]);

function choosePerson(value) {
    if (value === FIND) {
        who.value = 'real';
        stackOpen.value = true;

        return;
    }

    if (value === 'test' && copiedFrom.value) resetPerson();
}

function chooseStart(value) {
    // A cleared select is no choice: the start stays what it was.
    if (!value || value === start.value) return;

    start.value = value;
    until.value = null;
    selectedSig.value = null;
    noPreview.value = new Set();
    actions.value = [];
    load();
}

// --- what she does -------------------------------------------------------------------------------------------------------

const sameAction = (a, b) => a.mail === b.mail && a.type === b.type && (a.url ?? null) === (b.url ?? null) && (a.product ?? null) === (b.product ?? null);

// An action is a switch: pressing it puts it on her way, pressing it again takes it off.
function toggleAction(action) {
    const found = actions.value.findIndex((a) => sameAction(a, action));

    if (found !== -1) {
        actions.value = actions.value.filter((_, i) => i !== found);
    } else if (actions.value.length < MAX_ACTIONS) {
        // A new one waits as long as the others of that mail do: they share one "when".
        const same = actions.value.find((a) => a.mail === action.mail);

        actions.value = [...actions.value, { after_seconds: same?.after_seconds ?? 0, product: null, url: null, ...action }];
    }

    load();
}

function clearActions(mail) {
    if (!actions.value.some((a) => a.mail === mail)) return;

    actions.value = actions.value.filter((a) => a.mail !== mail);
    load();
}

function delayActions(mail, days) {
    actions.value = actions.value.map((a) => (a.mail === mail ? { ...a, after_seconds: Math.max(0, days) * 86400 } : a));
    load();
}

// --- the screen ----------------------------------------------------------------------------------------------------------

const shown = computed(() => untilDay(timeline.value.entries, until.value));
const hasWay = computed(() => shown.value.some(isExperienced));

// The row that is open: the one she chose, found again by what it is when the way was planned anew, else the first mail.
const selected = computed(() => {
    const rows = shown.value.filter(isExperienced);
    const chosen = selectedSig.value ? rows.find((e) => signatureOf(e) === selectedSig.value) : null;

    return chosen ?? firstToOpen(shown.value, noPreview.value);
});

function select(entry) {
    selectedSig.value = signatureOf(entry);
}

function passOver(entry) {
    if (selectedSig.value === null) noPreview.value = new Set([...noPreview.value, signatureOf(entry)]);
}

function skipTo(day) {
    until.value = day;
}

const unknowns = computed(() => unknownCards(shown.value));
const unknownText = computed(() => {
    const names = unknowns.value.map((n) => `„${n}“`);

    if (!names.length) return '';

    const list = names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} ${t('probe_and')} ${names[names.length - 1]}`;

    return t('probe_unknown_foot').replace(':names', () => list);
});

// "holt sich": what the person does at the start, by the event that begins the way.
const startEvent = computed(() => starts.value.find((s) => s.key === start.value)?.event ?? 'requested');
const sentence = computed(() => t(`probe_sentence_${startEvent.value}`, t('probe_sentence_requested')));

const startOptions = computed(() => {
    const labels = starts.value.map((s) => s.label);

    return starts.value.map((s) => ({
        value: s.key,
        // The same name on two kinds of card is told apart by the kind.
        label: labels.filter((l) => l === s.label).length > 1 ? `${s.label} (${s.type_label})` : s.label,
    }));
});

const ways = computed(() => tree.value?.stats.leaves ?? null);
const waysText = computed(() => (ways.value === null ? t('probe_all_ways_plain') : t('probe_all_ways').replace(':count', ways.value)));

const treeSummary = computed(() => (tree.value
    ? t('probe_summary').replace(':ways', tree.value.stats.leaves).replace(':steps', tree.value.stats.nodes)
    : ''));

async function showTree() {
    view.value = 'tree';
    await loadTree();
}
</script>

<template>
    <div class="pr-page" data-landkarte-probelauf>
        <template v-if="view === 'person'">
            <div class="pr-top">
                <div class="pr-sentence" data-pr-sentence>
                    <Select
                        class="pr-select"
                        size="sm"
                        :model-value="copiedFrom ? 'real' : 'test'"
                        :options="personOptions"
                        :aria-label="t('probe_person_label')"
                        data-pr-person
                        @update:model-value="choosePerson"
                    />
                    <span>{{ sentence }}</span>
                    <Select
                        v-if="startOptions.length"
                        class="pr-select pr-select--start"
                        size="sm"
                        :model-value="start"
                        :options="startOptions"
                        :aria-label="t('probe_start_label')"
                        data-pr-start
                        @update:model-value="chooseStart"
                    />
                    <Badge :text="t('probe_badge')" data-pr-badge />
                </div>
                <Button icon="sliders-horizontal" :text="t('probe_adjust')" data-pr-adjust @click="stackOpen = true" />
            </div>

            <div class="pr-hint">
                <span>{{ t('probe_help_test') }}</span>
                <button type="button" class="pr-link" data-pr-all-ways @click="showTree">{{ waysText }}</button>
            </div>

            <p v-if="state === 'loading' && !loadedOnce" class="pr-status" role="status" data-pr-loading>{{ t('probe_loading') }}</p>
            <Alert v-if="state === 'failed'" variant="warning" data-pr-failed>{{ t('probe_failed') }}</Alert>

            <!-- The body stays where it is while the way is planned again: a mail that is open, the day that was chosen. -->
            <template v-if="state === 'ready' || (state === 'loading' && loadedOnce)">
                <p v-if="!starts.length" class="pr-status" data-pr-empty>{{ t('probe_start_none') }}</p>
                <p v-else-if="!hasWay" class="pr-status" data-pr-empty>{{ t('probe_timeline_empty') }}</p>

                <div v-else class="pr-cols">
                    <ProbeSteps :entries="shown" :selected="selected?.id ?? null" :until="until" @select="select" @skip="skipTo" @unskip="until = null" />
                    <ProbeDetail
                        :entry="selected"
                        :entries="shown"
                        :mail-url="mailUrl"
                        :page-url="pageUrl"
                        :start="start"
                        :person-sig="personSig"
                        :person-body="personBody"
                        :max-actions="MAX_ACTIONS"
                        :products="products"
                        :actions="actions"
                        @toggle="toggleAction"
                        @clear="clearActions"
                        @delay="delayActions"
                        @none="passOver"
                    />
                </div>

                <p v-if="unknownText" class="pr-foot" data-pr-unknown>{{ unknownText }}</p>
            </template>
        </template>

        <template v-else>
            <div class="pr-top">
                <div class="pr-head">
                    <Heading :text="t('probe_title')" size="base" />
                    <span v-if="treeSummary" class="pr-summary" data-pr-summary>{{ treeSummary }}</span>
                </div>
                <Button icon="arrow-left" :text="t('probe_back')" data-pr-back @click="view = 'person'" />
            </div>
            <Description :text="t('probe_help')" class="pr-help" />

            <p v-if="treeState === 'loading'" class="pr-status" role="status" data-pr-loading>{{ t('probe_loading') }}</p>
            <Alert v-else-if="treeState === 'failed'" variant="warning" data-pr-failed>{{ t('probe_failed') }}</Alert>
            <template v-else-if="tree">
                <p v-if="!tree.roots.length" class="pr-status" data-pr-empty>{{ t('probe_empty') }}</p>
                <ul v-else class="pr-tree" data-pr-tree>
                    <ProbeNode v-for="root in tree.roots" :key="root.id" :node="root" />
                </ul>
                <p v-if="tree.stats.unreached.length" class="pr-unreached" data-pr-unreached>
                    <strong>{{ t('probe_unreached') }}:</strong>
                    {{ tree.stats.unreached.map((u) => u.label).join(', ') }}
                </p>
            </template>
        </template>

        <ProbeStack
            v-model:open="stackOpen"
            :who="who"
            :persons-url="personsUrl"
            :copied-from="copiedFrom"
            :missing="missing"
            :tags="tags"
            :access="access"
            :ran="ran"
            :unsubscribed="unsubscribed"
            :options="options"
            :automations="automations"
            @who="chooseWho"
            @patch="onPatch"
            @copy="copyPerson"
            @reset="resetPerson"
        />
    </div>
</template>

<style>
.pr-page {
    min-height: 460px; padding: 1.25rem 1.25rem 1.5rem;
    border: 1px solid var(--color-gray-200); border-radius: 0.75rem; background: var(--color-white);
}
.dark .pr-page { border-color: var(--color-gray-700); background: var(--color-gray-900); }
.pr-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem 1rem; }
.pr-sentence { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 0.6rem; font-size: 0.95rem; color: var(--color-gray-800); }
.dark .pr-sentence { color: var(--color-gray-200); }
.pr-select { width: auto; min-width: 8.5rem; }
.pr-select--start { min-width: 12rem; max-width: 22rem; }
.pr-hint { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 0.25rem 1rem; margin: 0.5rem 0 1.1rem; font-size: 0.85rem; color: var(--color-gray-600); }
.dark .pr-hint { color: var(--color-gray-400); }
.pr-link { padding: 0; border: 0; background: transparent; font-size: 0.85rem; color: var(--color-gray-700); text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.pr-link:hover { color: var(--color-gray-900); }
.pr-link:focus-visible { outline: 2px solid var(--color-blue-400, var(--color-primary)); outline-offset: 2px; border-radius: 2px; }
.dark .pr-link { color: var(--color-gray-300); }
.dark .pr-link:hover { color: var(--color-gray-100); }
.pr-cols { display: grid; grid-template-columns: minmax(16rem, 20.5rem) minmax(0, 1fr); gap: 1.75rem; align-items: start; }
@media (max-width: 900px) { .pr-cols { grid-template-columns: 1fr; } }
.pr-foot { margin: 1.5rem 0 0; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-foot { color: var(--color-gray-400); }
.pr-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.25rem 1rem; }
.pr-summary { font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-summary { color: var(--color-gray-400); }
.pr-help { margin: 0.25rem 0 0.9rem; max-width: 70ch; }
.pr-status { font-size: 0.875rem; color: var(--color-gray-600); }
.dark .pr-status { color: var(--color-gray-400); }
.pr-tree { margin: 0; padding: 0; }
.pr-tree > .pr-node { padding-bottom: 0.4rem; margin-bottom: 0.4rem; border-bottom: 1px solid var(--color-gray-100); }
.dark .pr-tree > .pr-node { border-bottom-color: var(--color-gray-800); }
.pr-tree > .pr-node:last-child { border-bottom: 0; }
.pr-unreached { margin-top: 1rem; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-unreached { color: var(--color-gray-400); }
</style>
