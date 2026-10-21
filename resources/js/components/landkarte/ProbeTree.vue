<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { Alert, Checkbox, Description, Heading, Input, Select, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import ProbeNode from './ProbeNode.vue';
import ProbeAccount from './ProbeAccount.vue';
import ProbeMailbox from './ProbeMailbox.vue';
import ProbePages from './ProbePages.vue';
import ProbeRealPerson from './ProbeRealPerson.vue';
import ProbeTimeline from './ProbeTimeline.vue';
import {
    standing, timeInDay as timeInDayText, timeWords as timeText, untilDay, words as plainWords,
} from '../../support/probelauf.js';
import { postJson } from '../../support/landkarteRequest.js';

/**
 * The dry run of a Landkarte ("Probelauf").
 *
 * Planned on the server from what the owning addons say they would do; nothing here sends, saves or
 * starts anything. Two ways to look: every way through the map (no person, a tree), or the one way a
 * test person walks from a chosen start, in order by day. Loaded when the tab opens and again when
 * the person or the start changes.
 */
const props = defineProps({
    url: { type: String, required: true },
    mailUrl: { type: String, default: null },
    // Where a real person can be found and copied from; null when no addon knows real people.
    personsUrl: { type: String, default: null },
    labels: { type: Object, default: () => ({}) },
});

const state = ref('loading');
// Once there is something to show, planning again does not take it away.
const loadedOnce = ref(false);
const person = ref('unknown');
// For the test person: her way as a timeline, or the mails of it as a mailbox.
const lens = ref('timeline');
// The test person: what the page lets the operator say about her. She lives in the query and nowhere else.
const tags = ref('');
const access = ref('');
const ran = ref([]);
const actions = ref([]);
// Fast forward: the day up to which her way is shown (null: all of it).
const until = ref(null);
// "As for a real person": what was copied from her beyond the form's fields, and who she is for the page.
const unsubscribed = ref(false);
const extraTraits = ref({});
const copiedFrom = ref(null);
const missing = ref([]);
const who = ref('test');
const products = ref([]);
const automations = ref([]);
const start = ref(null);
const starts = ref([]);
const tree = ref({ roots: [], stats: { nodes: 0, leaves: 0, cut: 0, unreached: [] } });
const timeline = ref({ entries: [], stats: { entries: 0, days: [], cut: 0 } });

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

provide('probelauf', {
    t,
    timeWords: (seconds) => timeText(seconds, t),
    timeInDay: (seconds) => timeInDayText(seconds, t),
});

// Everything that makes her this person, as the body of a request: the way is planned from it and so is her mail. A body, not a URL:
// what describes a real person must not land in an access log.
function personBody() {
    const body = {
        tags: plainWords(tags.value),
        access: plainWords(access.value),
        ran: ran.value,
        actions: actions.value.map((a) => ({
            type: a.type, mail: a.mail, product: a.product ?? null, url: a.url ?? null, after_seconds: a.after_seconds ?? 0,
        })),
    };

    if (unsubscribed.value) body.unsubscribed = true;
    if (Object.keys(extraTraits.value).length) body.traits = extraTraits.value;

    return body;
}

// What the planner takes of a person's actions; the page does not offer more.
const MAX_ACTIONS = 12;

let ticket = 0;

async function load() {
    const mine = ++ticket;
    state.value = 'loading';

    try {
        const body = { person: person.value };
        if (person.value === 'test') Object.assign(body, personBody(), start.value ? { start: start.value } : {});

        const response = await postJson(props.url, body);

        if (!response.ok) throw new Error(String(response.status));

        const data = response.data;
        // A slower answer to an earlier choice must not overwrite the answer to the latest.
        if (mine !== ticket) return;

        starts.value = data.starts ?? [];

        if (data.person === 'test') {
            timeline.value = { entries: data.entries, stats: data.stats };
            start.value = data.start;
            products.value = data.products ?? [];
            automations.value = data.automations ?? [];

            // An action tied to a mail that is no longer on her way (another start, a changed form) has nothing to act on.
            const keys = new Set(data.entries.map((e) => e.meta?.mail_key).filter(Boolean));
            if (actions.value.some((a) => !keys.has(a.mail))) actions.value = actions.value.filter((a) => keys.has(a.mail));
        } else {
            tree.value = { roots: data.roots, stats: data.stats };
        }

        state.value = 'ready';
        loadedOnce.value = true;
    } catch {
        if (mine === ticket) state.value = 'failed';
    }
}

function choosePerson(value) {
    if (!value || value === person.value) return;
    person.value = value;
    // The other way of looking has nothing of its own to keep on screen yet.
    loadedOnce.value = false;
    load();
}

function chooseStart(value) {
    // A cleared select is no choice: the start stays what it was.
    if (!value || value === start.value) return;
    start.value = value;
    load();
}

// What a real person said, into the form. Everything stays editable; the way is planned from the form.
function copyPerson(data) {
    copied = true;
    missing.value = data.missing ?? [];
    tags.value = (data.tags ?? []).join(', ');
    access.value = (data.access ?? []).join(', ');
    ran.value = data.ran ?? [];
    unsubscribed.value = Boolean(data.unsubscribed);
    extraTraits.value = data.traits ?? {};
    copiedFrom.value = data.label;
    actions.value = [];
    load();
}

function resetPerson() {
    copied = true;
    missing.value = [];
    tags.value = '';
    access.value = '';
    ran.value = [];
    unsubscribed.value = false;
    extraTraits.value = {};
    copiedFrom.value = null;
    load();
}

function chooseWho(value) {
    if (!value || value === who.value) return;
    who.value = value;
    if (value === 'test' && copiedFrom.value) resetPerson();
}

function toggleRan(handle, on) {
    ran.value = on ? [...ran.value, handle] : ran.value.filter((h) => h !== handle);
    load();
}

function addAction(action) {
    if (actions.value.length >= MAX_ACTIONS) return;
    actions.value = [...actions.value, action];
    load();
}

function removeAction(index) {
    actions.value = actions.value.filter((_, i) => i !== index);
    load();
}

// Typing in a field plans again once the typing pauses, not at every key.
let pause = null;
let copied = false;
watch([tags, access], () => {
    // A copy from a real person sets both at once and plans itself: the typing pause is for a hand on the keys.
    if (copied) {
        copied = false;

        return;
    }

    clearTimeout(pause);
    pause = setTimeout(load, 450);
});
onBeforeUnmount(() => clearTimeout(pause));

onMounted(load);

const lensOptions = computed(() => [
    { value: 'timeline', label: t('probe_lens_timeline') },
    { value: 'mailbox', label: t('probe_lens_mailbox') },
    { value: 'account', label: t('probe_lens_account') },
    { value: 'pages', label: t('probe_lens_pages') },
]);

const untilOptions = computed(() => [
    { value: 'all', label: t('probe_until_all') },
    ...timeline.value.stats.days.map((d) => ({ value: String(d), label: t('probe_until_day').replace(':day', d) })),
]);

const shown = computed(() => untilDay(timeline.value.entries, until.value));
const where = computed(() => standing(shown.value));

const personOptions = computed(() => [
    { value: 'unknown', label: t('probe_person_unknown') },
    { value: 'test', label: t('probe_person_test') },
]);

const startOptions = computed(() => starts.value.map((s) => ({
    value: s.key,
    label: `${s.type_label}: ${s.label}, ${s.event_label}`,
})));

const summary = computed(() => {
    if (person.value === 'test') {
        const days = new Set(shown.value.map((e) => e.day)).size;

        return t(days === 1 ? 'probe_timeline_summary_one' : 'probe_timeline_summary')
            .replace(':entries', shown.value.length)
            .replace(':days', days);
    }

    return t('probe_summary')
        .replace(':ways', tree.value.stats.leaves)
        .replace(':steps', tree.value.stats.nodes);
});

const hasContent = computed(() => (person.value === 'test' ? shown.value.length > 0 : tree.value.roots.length > 0));
</script>

<template>
    <div class="pr-page" data-landkarte-probelauf>
        <div class="pr-head">
            <Heading :text="person === 'test' ? t('probe_title_test') : t('probe_title')" size="base" />
            <span v-if="state === 'ready' && hasContent" class="pr-summary" data-pr-summary>{{ summary }}</span>
        </div>
        <Description :text="person === 'test' ? t('probe_help_test') : t('probe_help')" class="pr-help" />

        <div class="pr-controls">
            <span class="pr-start">
                <span class="pr-start__label">{{ t('probe_person_label') }}</span>
                <ToggleGroup :model-value="person" size="sm" :aria-label="t('probe_person_label')" data-pr-person @update:model-value="choosePerson">
                    <ToggleItem v-for="option in personOptions" :key="option.value" :value="option.value" :label="option.label" :data-pr-person-option="option.value" />
                </ToggleGroup>
            </span>
            <label v-if="person === 'test' && startOptions.length" class="pr-start">
                <span class="pr-start__label">{{ t('probe_start_label') }}</span>
                <Select class="w-96 max-w-full" size="sm" :model-value="start" :options="startOptions" :aria-label="t('probe_start_label')" data-pr-start @update:model-value="chooseStart" />
            </label>
        </div>

        <div v-if="person === 'test'" class="pr-start pr-lensrow">
            <span class="pr-start__label">{{ t('probe_lens_label') }}</span>
            <ToggleGroup v-model="lens" size="sm" :aria-label="t('probe_lens_label')" data-pr-lens>
                <ToggleItem v-for="option in lensOptions" :key="option.value" :value="option.value" :label="option.label" :data-pr-lens-option="option.value" />
            </ToggleGroup>
        </div>

        <div v-if="person === 'test' && personsUrl" class="pr-start pr-whowrap">
            <span class="pr-start__label">{{ t('probe_who_label') }}</span>
            <ToggleGroup :model-value="who" size="sm" :aria-label="t('probe_who_label')" data-pr-who-toggle @update:model-value="chooseWho">
                <ToggleItem value="test" :label="t('probe_who_test')" data-pr-who-option="test" />
                <ToggleItem value="real" :label="t('probe_who_real')" data-pr-who-option="real" />
            </ToggleGroup>
        </div>
        <ProbeRealPerson
            v-if="person === 'test' && personsUrl && who === 'real'"
            :url="personsUrl"
            :copied-from="copiedFrom"
            :missing="missing"
            @copy="copyPerson"
            @reset="resetPerson"
        />

        <div v-if="person === 'test'" class="pr-who" data-pr-who>
            <label class="pr-start">
                <span class="pr-start__label">{{ t('probe_tags_label') }}</span>
                <Input v-model="tags" size="sm" class="w-52" :placeholder="t('probe_tags_placeholder')" :aria-label="t('probe_tags_label')" data-pr-tags />
            </label>
            <label class="pr-start">
                <span class="pr-start__label">{{ t('probe_access_label') }}</span>
                <Input v-model="access" size="sm" class="w-52" :placeholder="t('probe_access_placeholder')" :aria-label="t('probe_access_label')" data-pr-access />
            </label>
            <Checkbox
                v-model="unsubscribed"
                :label="t('probe_unsubscribed_label')"
                size="sm"
                data-pr-unsubscribed
                @update:model-value="load"
            />
            <fieldset v-if="automations.length" class="pr-ran">
                <legend class="pr-start__label">{{ t('probe_ran_label') }}</legend>
                <Checkbox
                    v-for="a in automations"
                    :key="a.id"
                    :model-value="ran.includes(a.id)"
                    :label="a.label"
                    size="sm"
                    :data-pr-ran="a.id"
                    @update:model-value="toggleRan(a.id, $event)"
                />
            </fieldset>
            <label v-if="timeline.stats.days.length > 1" class="pr-start">
                <span class="pr-start__label">{{ t('probe_until_label') }}</span>
                <Select
                    :model-value="until === null ? 'all' : String(until)"
                    size="sm"
                    class="w-44"
                    :options="untilOptions"
                    :aria-label="t('probe_until_label')"
                    data-pr-until
                    @update:model-value="until = $event === 'all' ? null : Number($event)"
                />
            </label>
        </div>
        <p v-if="person === 'test' && state === 'ready' && until !== null" class="pr-standing" data-pr-standing>
            {{ t('probe_standing').replace(':day', until).replace(':mails', where.mails).replace(':purchases', where.purchases) }}
            <template v-if="where.access.length"> {{ t('probe_standing_access') }} {{ where.access.join(', ') }}.</template>
            <template v-if="where.unsubscribed"> {{ t('probe_standing_unsub') }}</template>
        </p>

        <p v-if="state === 'loading'" class="pr-status" role="status" data-pr-loading>{{ t('probe_loading') }}</p>
        <Alert v-if="state === 'failed'" variant="warning" data-pr-failed>{{ t('probe_failed') }}</Alert>

        <!-- The body stays where it is while the way is planned again: a mail that is open, a line whose reasons are open. -->
        <template v-if="state === 'ready' || (state === 'loading' && loadedOnce)">
        <p v-if="person === 'test' && !startOptions.length" class="pr-status" data-pr-empty>{{ t('probe_start_none') }}</p>
        <p v-else-if="!hasContent" class="pr-status" data-pr-empty>{{ person === 'test' ? t('probe_timeline_empty') : t('probe_empty') }}</p>

        <ProbeMailbox
            v-else-if="person === 'test' && lens === 'mailbox' && mailUrl"
            :entries="shown"
            :mail-url="mailUrl"
            :start="start"
            :person-body="personBody"
            :max-actions="MAX_ACTIONS"
            :products="products"
            :actions="actions"
            @add-action="addAction"
            @remove-action="removeAction"
        />
        <ProbeAccount v-else-if="person === 'test' && lens === 'account'" :entries="shown" />
        <ProbePages v-else-if="person === 'test' && lens === 'pages'" :entries="shown" />
        <ProbeTimeline v-else-if="person === 'test'" :entries="shown" />
        <ul v-else class="pr-tree" data-pr-tree>
            <ProbeNode v-for="root in tree.roots" :key="root.id" :node="root" />
        </ul>
        </template>

        <p v-if="state === 'ready' && person === 'unknown' && tree.stats.unreached.length" class="pr-unreached" data-pr-unreached>
            <strong>{{ t('probe_unreached') }}:</strong>
            {{ tree.stats.unreached.map((u) => u.label).join(', ') }}
        </p>
    </div>
</template>

<style>
.pr-page {
    height: calc(100vh - 13rem); min-height: 460px; overflow: auto; padding: 1rem 1.25rem 1.5rem;
    border: 1px solid var(--color-gray-200); border-radius: 0.75rem; background: var(--color-white);
}
.dark .pr-page { border-color: var(--color-gray-700); background: var(--color-gray-900); }
.pr-head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.25rem 1rem; }
.pr-summary { margin-left: auto; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-summary { color: var(--color-gray-400); }
.pr-help { margin: 0.25rem 0 0.9rem; max-width: 70ch; }
.pr-controls { display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem 1.25rem; margin: 0 0 1rem; }
.pr-start { display: inline-flex; align-items: center; gap: 0.5rem; }
.pr-start__label { font-size: 0.8rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pr-start__label { color: var(--color-gray-400); }
.pr-lensrow { display: flex; margin: -0.25rem 0 0.75rem; }
.pr-whowrap { display: flex; margin: 0 0 0.75rem; }
.pr-who { display: flex; align-items: center; flex-wrap: wrap; gap: 0.6rem 1.25rem; margin: -0.25rem 0 1rem; }
.pr-ran { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.25rem 0.9rem; margin: 0; padding: 0; border: 0; min-width: 0; }
.pr-standing { margin: -0.4rem 0 0.9rem; font-size: 0.8rem; color: var(--color-gray-700); }
.dark .pr-standing { color: var(--color-gray-300); }
.pr-status { font-size: 0.875rem; color: var(--color-gray-600); }
.pr-tree { margin: 0; padding: 0; }
.pr-tree > .pr-node { padding-bottom: 0.4rem; margin-bottom: 0.4rem; border-bottom: 1px solid var(--color-gray-100); }
.dark .pr-tree > .pr-node { border-bottom-color: var(--color-gray-800); }
.pr-tree > .pr-node:last-child { border-bottom: 0; }
.pr-unreached { margin-top: 1rem; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-unreached { color: var(--color-gray-400); }
</style>
