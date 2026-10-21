<script setup>
import { computed, inject, ref } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { ICONS, splitNode, startsOpen } from '../../support/probelauf.js';

/**
 * One line of the dry run and everything below it.
 *
 * The tree comes planned from the server: a card that is entered ("enter"), what it does (mail, wait,
 * access, page, stop), forks with their alternatives, and markers for what could not be said (unknown,
 * denied, "on as above", "n more ways"). This component only draws it, quietly: a line of text with a
 * small icon, children indented behind a thin rule. Cards and alternatives fold.
 */
defineOptions({ name: 'ProbeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    depth: { type: Number, default: 0 },
});

const probe = inject('probelauf');


// What follows on this level and what hangs below: see support/probelauf.js.
const split = computed(() => splitNode(props.node));
const flow = computed(() => split.value.flow);
const nested = computed(() => split.value.nested);
const note = computed(() => split.value.note);
const bought = computed(() => props.node.kind === 'alt' && props.node.meta?.merged?.includes('bought'));

const foldable = computed(() => ['enter', 'alt'].includes(props.node.kind) && nested.value.length > 0);
const open = ref(startsOpen(props.node));
const icon = computed(() => ICONS[props.node.kind] ?? null);
const timed = computed(() => ['mail', 'access', 'page', 'trait'].includes(props.node.kind));
const quiet = computed(() => ['wait', 'rejoin', 'more', 'unknown', 'denied', 'stop'].includes(props.node.kind));
const kindWord = computed(() => probe.t(`probe_kind_${props.node.kind}`, ''));
</script>

<template>
    <li class="pr-node" :class="[`pr-node--${node.kind}`, quiet && 'pr-node--quiet']" :data-pr-kind="node.kind">
        <div class="pr-row">
            <button
                v-if="foldable"
                type="button"
                class="pr-fold"
                :aria-expanded="open"
                :aria-label="open ? probe.t('probe_collapse') : probe.t('probe_expand')"
                @click="open = !open"
            >
                <Icon :name="open ? 'chevron-down' : 'chevron-right'" class="size-3.5" />
            </button>
            <span v-else class="pr-fold pr-fold--none" aria-hidden="true" />

            <span v-if="node.kind === 'alt'" class="pr-alt" :class="bought ? 'pr-alt--side' : 'pr-alt--when'">{{ node.label }}</span>
            <template v-else>
                <Icon v-if="icon" :name="icon" class="pr-icon size-4" :title="kindWord" />
                <span v-if="node.kind === 'enter'" class="pr-type">{{ node.detail }}</span>
                <span class="pr-label">{{ node.label }}</span>
                <span v-if="node.kind !== 'enter' && node.detail" class="pr-detail">{{ node.detail }}</span>
                <span v-if="note" class="pr-detail pr-note">{{ note }}</span>
            </template>

            <span v-if="node.kind === 'alt' && node.count > 1" class="pr-detail">{{ probe.t('probe_merged').replace(':count', node.count) }}</span>
            <span v-if="timed" class="pr-time">{{ probe.timeWords(node.at) }}</span>
        </div>

        <ul v-if="nested.length && (!foldable || open)" class="pr-children">
            <ProbeNode v-for="child in nested" :key="child.id" :node="child" :depth="depth + 1" />
        </ul>
    </li>
    <ProbeNode v-for="child in flow" :key="child.id" :node="child" :depth="depth" />
</template>

<style>
.pr-node { list-style: none; position: relative; }
.pr-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.1rem 0.5rem; padding: 0.2rem 0; font-size: 0.875rem; line-height: 1.35; color: var(--color-gray-900); }
.dark .pr-row { color: var(--color-gray-100); }

.pr-fold { display: inline-flex; align-items: center; justify-content: center; width: 1.25rem; height: 1.25rem; flex: none; align-self: center; border-radius: 0.25rem; background: transparent; border: 0; padding: 0; color: var(--color-gray-500); cursor: pointer; }
.pr-fold:hover { color: var(--color-gray-900); background: var(--color-gray-100); }
.dark .pr-fold:hover { color: var(--color-gray-100); background: var(--color-gray-700); }
.pr-fold:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 1px; }
.pr-fold--none { cursor: default; pointer-events: none; }

.pr-icon { flex: none; align-self: center; color: var(--color-gray-500); }
.pr-type { font-size: 0.7rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--color-gray-600); }
.dark .pr-type { color: var(--color-gray-400); }
.pr-label { font-weight: 500; }
.pr-detail { color: var(--color-gray-600); font-size: 0.8rem; }
.dark .pr-detail { color: var(--color-gray-400); }
.pr-time { margin-left: auto; padding-left: 1rem; font-size: 0.75rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pr-time { color: var(--color-gray-400); }

.pr-node--enter > .pr-row { padding-top: 0.45rem; }
.pr-node--enter > .pr-row .pr-label { font-weight: 600; }
.pr-node--quiet > .pr-row { color: var(--color-gray-600); }
.dark .pr-node--quiet > .pr-row { color: var(--color-gray-400); }
.pr-node--quiet .pr-label { font-weight: 400; }
.pr-node--stop > .pr-row, .pr-node--unknown > .pr-row { color: var(--color-gray-700); }
.pr-alt { display: inline-block; font-size: 0.875rem; }
.pr-alt--when { font-weight: 500; color: var(--color-gray-800); }
.dark .pr-alt--when { color: var(--color-gray-200); }
.pr-alt--side { padding: 0.05rem 0.55rem; border: 1px solid var(--color-gray-200); border-radius: 999px; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pr-alt--side { border-color: var(--color-gray-700); color: var(--color-gray-400); }
.pr-node--fork > .pr-row .pr-label { font-weight: 500; }

.pr-children { margin: 0 0 0 0.6rem; padding: 0 0 0 0.9rem; border-left: 1px solid var(--color-gray-200); }
.dark .pr-children { border-left-color: var(--color-gray-700); }
</style>
