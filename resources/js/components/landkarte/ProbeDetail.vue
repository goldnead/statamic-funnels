<script setup>
import { computed, inject } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { entriesUntil, iconFor, VIEWS } from '../../support/probelauf.js';
import ProbeAccount from './ProbeAccount.vue';
import ProbeMailView from './ProbeMailView.vue';
import ProbePageView from './ProbePageView.vue';

/**
 * "Das sieht die Person": the right column. What the person sees at the step that is chosen on the left (the mail she gets,
 * the page she lands on, her account after she was given access), and below it why it comes: the reasons the planner
 * gives for this step, said once for the step that is open and not on every row.
 */
const props = defineProps({
    entry: { type: Object, default: null },
    entries: { type: Array, default: () => [] },
    mailUrl: { type: String, default: null },
    pageUrl: { type: String, default: null },
    start: { type: String, default: null },
    personSig: { type: String, default: '' },
    personBody: { type: Function, default: () => ({}) },
    maxActions: { type: Number, default: 12 },
    products: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
});

const emit = defineEmits(['toggle', 'clear', 'delay', 'none']);
const probe = inject('probelauf');

const view = computed(() => (props.entry ? VIEWS[props.entry.kind] ?? 'line' : null));
const account = computed(() => (view.value === 'account' ? entriesUntil(props.entries, props.entry.id) : []));
const reasons = computed(() => (props.entry?.why ?? []).filter(Boolean));
</script>

<template>
    <section class="pd" data-pd>
        <h3 class="pd-title">{{ probe.t('probe_sees') }}</h3>

        <p v-if="!entry" class="pr-status" data-pd-empty>{{ probe.t('probe_pick_step') }}</p>

        <ProbeMailView
            v-else-if="view === 'mail' && mailUrl"
            :entry="entry"
            :mail-url="mailUrl"
            :start="start"
            :person-sig="personSig"
            :person-body="personBody"
            :products="products"
            :actions="actions"
            :max-actions="maxActions"
            @toggle="emit('toggle', $event)"
            @clear="emit('clear', $event)"
            @delay="(mail, days) => emit('delay', mail, days)"
            @none="emit('none', $event)"
        />
        <ProbePageView
            v-else-if="view === 'page' && pageUrl"
            :entry="entry"
            :page-url="pageUrl"
            :start="start"
            :person-sig="personSig"
            :person-body="personBody"
        />
        <ProbeAccount v-else-if="view === 'account'" :entries="account" />
        <div v-else class="pd-line" data-pd-line>
            <Icon v-if="iconFor(entry)" :name="iconFor(entry)" class="size-4 shrink-0" />
            <div>
                <p class="pd-line__label">{{ entry.label }}</p>
                <p v-if="entry.detail" class="pd-line__detail">{{ entry.detail }}</p>
            </div>
        </div>

        <div v-if="entry && reasons.length" class="pd-why" data-pd-why>
            <h3 class="pd-title">{{ probe.t('probe_why_title') }}</h3>
            <ul>
                <li v-for="(reason, i) in reasons" :key="i">{{ reason }}</li>
            </ul>
        </div>
    </section>
</template>

<style>
.pd { min-width: 0; }
.pd-title { margin: 0 0 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--color-gray-900); }
.dark .pd-title { color: var(--color-gray-100); }
.pd-line { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.4rem 0 0.8rem; color: var(--color-gray-500); }
.pd-line__label { margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--color-gray-900); }
.dark .pd-line__label { color: var(--color-gray-100); }
.pd-line__detail { margin: 0.15rem 0 0; font-size: 0.85rem; color: var(--color-gray-700); max-width: 60ch; }
.dark .pd-line__detail { color: var(--color-gray-300); }
.pd-why { margin-top: 1.25rem; }
.pd-why ul { margin: 0; padding: 0; list-style: none; }
.pd-why li { padding: 0.15rem 0 0.15rem 0.75rem; border-left: 2px solid var(--color-gray-200); font-size: 0.85rem; color: var(--color-gray-700); }
.dark .pd-why li { border-left-color: var(--color-gray-700); color: var(--color-gray-300); }
.pd-why li + li { margin-top: 0.1rem; }
</style>
