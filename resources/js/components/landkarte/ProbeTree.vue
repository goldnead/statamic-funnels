<script setup>
import { computed, onMounted, provide, ref } from 'vue';
import { Alert, Description, Heading, Select, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import ProbeNode from './ProbeNode.vue';
import ProbeTimeline from './ProbeTimeline.vue';
import { timeInDay as timeInDayText, timeWords as timeText } from '../../support/probelauf.js';

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
    labels: { type: Object, default: () => ({}) },
});

const state = ref('loading');
const person = ref('unknown');
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

let ticket = 0;

async function load() {
    const mine = ++ticket;
    state.value = 'loading';

    try {
        const url = new URL(props.url, window.location.origin);
        url.searchParams.set('person', person.value);
        if (person.value === 'test' && start.value) url.searchParams.set('start', start.value);

        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();
        // A slower answer to an earlier choice must not overwrite the answer to the latest.
        if (mine !== ticket) return;

        starts.value = data.starts ?? [];

        if (data.person === 'test') {
            timeline.value = { entries: data.entries, stats: data.stats };
            start.value = data.start;
        } else {
            tree.value = { roots: data.roots, stats: data.stats };
        }

        state.value = 'ready';
    } catch {
        if (mine === ticket) state.value = 'failed';
    }
}

function choosePerson(value) {
    if (!value || value === person.value) return;
    person.value = value;
    load();
}

function chooseStart(value) {
    // A cleared select is no choice: the start stays what it was.
    if (!value || value === start.value) return;
    start.value = value;
    load();
}

onMounted(load);

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
        return t('probe_timeline_summary')
            .replace(':entries', timeline.value.stats.entries)
            .replace(':days', timeline.value.stats.days.length);
    }

    return t('probe_summary')
        .replace(':ways', tree.value.stats.leaves)
        .replace(':steps', tree.value.stats.nodes);
});

const hasContent = computed(() => (person.value === 'test' ? timeline.value.entries.length > 0 : tree.value.roots.length > 0));
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

        <p v-if="state === 'loading'" class="pr-status" role="status" data-pr-loading>{{ t('probe_loading') }}</p>
        <Alert v-else-if="state === 'failed'" variant="warning" data-pr-failed>{{ t('probe_failed') }}</Alert>
        <p v-else-if="person === 'test' && !startOptions.length" class="pr-status" data-pr-empty>{{ t('probe_start_none') }}</p>
        <p v-else-if="!hasContent" class="pr-status" data-pr-empty>{{ person === 'test' ? t('probe_timeline_empty') : t('probe_empty') }}</p>

        <ProbeTimeline v-else-if="person === 'test'" :entries="timeline.entries" />
        <ul v-else class="pr-tree" data-pr-tree>
            <ProbeNode v-for="root in tree.roots" :key="root.id" :node="root" />
        </ul>

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
.pr-status { font-size: 0.875rem; color: var(--color-gray-600); }
.pr-tree { margin: 0; padding: 0; }
.pr-tree > .pr-node { padding-bottom: 0.4rem; margin-bottom: 0.4rem; border-bottom: 1px solid var(--color-gray-100); }
.dark .pr-tree > .pr-node { border-bottom-color: var(--color-gray-800); }
.pr-tree > .pr-node:last-child { border-bottom: 0; }
.pr-unreached { margin-top: 1rem; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-unreached { color: var(--color-gray-400); }
</style>
