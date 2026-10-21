<script setup>
import { computed, onMounted, provide, ref } from 'vue';
import { Alert, Description, Heading } from '@statamic/cms/ui';
import ProbeNode from './ProbeNode.vue';
import { timeWords as timeText } from '../../support/probelauf.js';

/**
 * The dry run of a Landkarte ("Probelauf"): the tree of every way through the map.
 *
 * Planned on the server from what the owning addons say they would do; nothing here sends, saves
 * or starts anything. Loaded once when the tab is opened. The page only reads: there is no person
 * yet, so every way is shown.
 */
const props = defineProps({
    url: { type: String, required: true },
    labels: { type: Object, default: () => ({}) },
});

const state = ref('loading');
const tree = ref({ roots: [], stats: { nodes: 0, leaves: 0, cut: 0, unreached: [] } });

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

const timeWords = (seconds) => timeText(seconds, t);

provide('probelauf', { t, timeWords });

async function load() {
    state.value = 'loading';

    try {
        const response = await fetch(props.url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(String(response.status));

        tree.value = await response.json();
        state.value = 'ready';
    } catch {
        state.value = 'failed';
    }
}

onMounted(load);

const summary = computed(() => t('probe_summary')
    .replace(':ways', tree.value.stats.leaves)
    .replace(':steps', tree.value.stats.nodes));
</script>

<template>
    <div class="pr-page" data-landkarte-probelauf>
        <div class="pr-head">
            <Heading :text="t('probe_title')" size="base" />
            <span class="pr-person">{{ t('probe_person_unknown') }}</span>
            <span v-if="state === 'ready' && tree.roots.length" class="pr-summary" data-pr-summary>{{ summary }}</span>
        </div>
        <Description :text="t('probe_help')" class="pr-help" />

        <p v-if="state === 'loading'" class="pr-status" role="status" data-pr-loading>{{ t('probe_loading') }}</p>
        <Alert v-else-if="state === 'failed'" variant="warning" data-pr-failed>{{ t('probe_failed') }}</Alert>
        <p v-else-if="!tree.roots.length" class="pr-status" data-pr-empty>{{ t('probe_empty') }}</p>

        <ul v-else class="pr-tree" data-pr-tree>
            <ProbeNode v-for="root in tree.roots" :key="root.id" :node="root" />
        </ul>

        <p v-if="state === 'ready' && tree.stats.unreached.length" class="pr-unreached" data-pr-unreached>
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
.pr-person, .pr-summary { font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-person, .dark .pr-summary { color: var(--color-gray-400); }
.pr-summary { margin-left: auto; }
.pr-help { margin: 0.25rem 0 1rem; max-width: 70ch; }
.pr-status { font-size: 0.875rem; color: var(--color-gray-600); }
.pr-tree { margin: 0; padding: 0; }
.pr-tree > .pr-node { padding-bottom: 0.4rem; margin-bottom: 0.4rem; border-bottom: 1px solid var(--color-gray-100); }
.dark .pr-tree > .pr-node { border-bottom-color: var(--color-gray-800); }
.pr-tree > .pr-node:last-child { border-bottom: 0; }
.pr-unreached { margin-top: 1rem; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-unreached { color: var(--color-gray-400); }
</style>
