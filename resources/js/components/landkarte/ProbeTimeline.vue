<script setup>
import { computed, inject, ref } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { groupByDay, iconFor } from '../../support/probelauf.js';

/**
 * The way of one known person, as a timeline: every step in the order it happens, under the day it
 * falls on. The page only reads what the planner returned; nothing is sent or started.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
});

const probe = inject('probelauf');
const days = computed(() => groupByDay(props.entries));
// Which lines have their reasons open.
const open = ref({});
const toggle = (id) => { open.value = { ...open.value, [id]: !open.value[id] }; };
const QUIET = ['wait', 'decision', 'unknown', 'denied', 'stop', 'rejoin', 'more'];
// Only what the person did or what was done for her has reasons worth asking for.
const asksWhy = (entry) => entry.why?.length > 0 && !['enter', 'wait', 'more'].includes(entry.kind);
</script>

<template>
    <ol class="pt-days" data-pr-timeline>
        <li v-for="group in days" :key="group.day" class="pt-day" :data-pt-day="group.day">
            <h3 class="pt-day__head">{{ probe.t('probe_day').replace(':day', group.day) }}</h3>
            <ul class="pt-list">
                <li
                    v-for="entry in group.entries"
                    :key="entry.id"
                    class="pt-row"
                    :class="[`pt-row--${entry.kind}`, QUIET.includes(entry.kind) && 'pt-row--quiet']"
                    :data-pt-kind="entry.kind"
                >
                    <Icon v-if="iconFor(entry)" :name="iconFor(entry)" class="pt-icon size-4" :title="probe.t(`probe_kind_${entry.kind}`, '')" />
                    <span v-if="entry.kind === 'enter'" class="pt-type">{{ entry.detail }}</span>
                    <span class="pt-label">{{ entry.label }}</span>
                    <span v-if="entry.kind !== 'enter' && entry.detail" class="pt-detail">{{ entry.detail }}</span>
                    <span v-if="entry.note" class="pt-detail">{{ entry.note }}</span>
                    <button
                        v-if="asksWhy(entry)"
                        type="button"
                        class="pt-why"
                        :aria-expanded="Boolean(open[entry.id])"
                        :data-pt-why="entry.id"
                        @click="toggle(entry.id)"
                    >{{ probe.t('probe_why') }}</button>
                    <span v-if="probe.timeInDay(entry.at)" class="pt-time">{{ probe.timeInDay(entry.at) }}</span>
                    <ol v-if="open[entry.id]" class="pt-reasons" data-pt-reasons>
                        <li v-for="(reason, i) in entry.why" :key="i">{{ reason }}</li>
                    </ol>
                </li>
            </ul>
        </li>
    </ol>
</template>

<style>
.pt-days { margin: 0; padding: 0; list-style: none; }
.pt-day { padding: 0 0 0.9rem; }
.pt-day__head {
    margin: 0 0 0.25rem; padding: 0.35rem 0; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--color-gray-600); border-bottom: 1px solid var(--color-gray-200);
}
.dark .pt-day__head { color: var(--color-gray-400); border-bottom-color: var(--color-gray-700); }
.pt-list { margin: 0; padding: 0; list-style: none; }
.pt-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.1rem 0.5rem; padding: 0.25rem 0; font-size: 0.875rem; line-height: 1.35; color: var(--color-gray-900); }
.dark .pt-row { color: var(--color-gray-100); }
.pt-icon { flex: none; align-self: center; color: var(--color-gray-500); }
.pt-type { font-size: 0.7rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--color-gray-600); }
.dark .pt-type { color: var(--color-gray-400); }
.pt-label { font-weight: 500; }
.pt-detail { color: var(--color-gray-600); font-size: 0.8rem; }
.dark .pt-detail { color: var(--color-gray-400); }
.pt-time { margin-left: auto; padding-left: 1rem; font-size: 0.75rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pt-time { color: var(--color-gray-400); }
.pt-row { padding-left: 1.4rem; }
.pt-why { padding: 0 0.2rem; background: transparent; border: 0; font-size: 0.75rem; color: var(--color-gray-600); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
.pt-why:hover { color: var(--color-gray-900); }
.dark .pt-why { color: var(--color-gray-400); }
.dark .pt-why:hover { color: var(--color-gray-100); }
.pt-why:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 1px; border-radius: 2px; }
.pt-reasons { flex-basis: 100%; margin: 0.2rem 0 0.2rem 1.5rem; padding: 0 0 0 1rem; font-size: 0.78rem; color: var(--color-gray-600); border-left: 1px solid var(--color-gray-200); }
.dark .pt-reasons { color: var(--color-gray-400); border-left-color: var(--color-gray-700); }
.pt-row--enter { padding-top: 0.55rem; padding-left: 0; }
.pt-row--enter .pt-label { font-weight: 600; }
.pt-row--quiet { color: var(--color-gray-600); }
.dark .pt-row--quiet { color: var(--color-gray-400); }
.pt-row--quiet .pt-label { font-weight: 400; }
</style>
