<script setup>
import { computed, inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Icon } from '@statamic/cms/ui';
import { kindOf } from '../../support/landkarteLayout.js';

/**
 * One object on the Landkarte: a card in the same form as the editors'
 * (`.sa-node`), neutral. The kind is an icon and an outlined word, the state a
 * dot, the number one line. The only colour on a card is red, and only when
 * the reference is broken.
 *
 * The card holds nothing of its own. Every word and number comes from the
 * addon that owns the object, through the page.
 */
const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
    selected: { type: Boolean, default: false },
});

const lk = inject('landkarte');

const kind = computed(() => kindOf(props.data.type));
const broken = computed(() => props.data.status === 'missing');
const unavailable = computed(() => props.data.status === 'unavailable');
const problems = computed(() => props.data.problems ?? []);
const flawed = computed(() => broken.value || problems.value.length > 0);
const dimmed = computed(() => lk.dimmed.value && !lk.lit.value.has(props.id));
const metric = computed(() => props.data.metric);
const drills = computed(() => !!props.data.drill_url);

const stateText = computed(() => {
    if (unavailable.value) return lk.t('map_unavailable');
    if (props.data.enabled === true) return lk.t('map_on');
    if (props.data.enabled === false) return lk.t('map_off');
    return lk.t('map_no_switch');
});

const flawText = computed(() => {
    if (broken.value) return props.data.ghost ? lk.t('map_dead_reference') : lk.t('map_broken');
    return problems.value.length === 1 ? lk.t('map_problem_one') : lk.t('map_problem_many').replace(':count', problems.value.length);
});
</script>

<template>
    <div
        class="sa-node lk-node"
        :class="[
            selected && 'sa-node--selected',
            flawed && 'lk-node--flawed',
            dimmed && 'lk-node--dim',
            broken && 'lk-node--dead',
        ]"
        :data-lk-node="id"
        :data-status="data.status"
        :tabindex="0"
        role="button"
        :aria-label="`${data.type_label}: ${data.label}`"
        @keydown.enter.prevent="lk.select(id)"
        @keydown.space.prevent="lk.select(id)"
    >
        <div class="lk-node__head">
            <span class="sa-icon-chip lk-chip"><Icon :name="broken ? 'warning-diamond' : kind.icon" class="size-4" /></span>
            <div class="lk-node__heading">
                <div class="lk-node__meta">
                    <span class="lk-pill">{{ data.type_label }}</span>
                </div>
                <div class="lk-node__title" :title="data.label" :class="{ 'lk-node__title--gone': broken }">{{ data.label }}</div>
            </div>
            <Icon v-if="drills" name="arrow-right" class="lk-node__drill size-3.5" :title="lk.t('map_double_click_hint')" />
        </div>

        <div class="lk-node__foot">
            <span v-if="flawed" class="lk-node__flaw" data-lk-alert>
                <Icon name="warning-diamond" class="size-3.5 shrink-0" />
                <span class="truncate">{{ flawText }}</span>
            </span>
            <template v-else>
                <span v-if="metric" class="lk-node__stat" data-lk-stat>
                    <strong>{{ Number(metric.value).toLocaleString() }}</strong> {{ metric.what }}
                </span>
                <span v-else class="lk-node__stat lk-node__stat--none">{{ lk.t('map_no_number') }}</span>
                <span v-if="data.enabled !== true" class="lk-node__state"><span class="lk-dot" />{{ stateText }}</span>
            </template>
        </div>

        <Handle id="tl" type="target" :position="Position.Left" />
        <Handle id="tr" type="target" :position="Position.Right" />
        <Handle id="tt" type="target" :position="Position.Top" />
        <Handle id="tb" type="target" :position="Position.Bottom" />
        <Handle id="sr" type="source" :position="Position.Right" />
        <Handle id="sl" type="source" :position="Position.Left" />
        <Handle id="sb" type="source" :position="Position.Bottom" />
        <Handle id="st" type="source" :position="Position.Top" />
    </div>
</template>

<style>
.lk-node {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-sizing: border-box;
    width: 204px;
    height: 112px;
    padding: 0.65rem 0.75rem 0.55rem;
    --sa-chip-color: var(--color-gray-500);
    transition: opacity 0.15s, box-shadow 0.15s;
    cursor: pointer;
}
.lk-node:focus-visible { outline: 2px solid var(--color-focus-outline, var(--color-blue-500)); outline-offset: 2px; }
.lk-chip { background-color: var(--color-gray-100); color: var(--color-gray-600); }
.dark .lk-chip { background-color: var(--color-gray-800); color: var(--color-gray-400); }

.lk-node__head { display: flex; gap: 0.6rem; align-items: flex-start; min-width: 0; }
.lk-node__heading { min-width: 0; flex: 1; }
.lk-node__meta { display: flex; align-items: center; gap: 0.4rem; min-width: 0; margin-bottom: 0.2rem; }
.lk-node__title {
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25;
    color: var(--color-gray-900);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
}
.dark .lk-node__title { color: var(--color-gray-100); }
.lk-node__title--gone { text-decoration: line-through; text-decoration-thickness: 1px; }
.lk-node__drill { flex-shrink: 0; margin-top: 0.15rem; color: var(--color-gray-400); }

.lk-node__foot {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    padding-top: 0.45rem;
    border-top: 1px solid var(--color-gray-100);
    font-size: 0.75rem;
    color: var(--color-gray-600);
}
.dark .lk-node__foot { border-top-color: var(--color-gray-800); color: var(--color-gray-400); }
.lk-pill {
    flex-shrink: 0;
    padding: 0 0.4rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-gray-300);
    font-size: 0.7rem;
    line-height: 1.2rem;
    color: var(--color-gray-600);
}
.dark .lk-pill { border-color: var(--color-gray-600); color: var(--color-gray-400); }
.lk-node__stat--none { color: var(--color-gray-500); }
.lk-node__stat { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lk-node__stat strong { color: var(--color-gray-900); font-variant-numeric: tabular-nums; }
.dark .lk-node__stat strong { color: var(--color-gray-100); }
.lk-node__state { display: inline-flex; align-items: center; gap: 0.3rem; flex-shrink: 0; margin-left: auto; }
.lk-node__flaw { display: inline-flex; align-items: center; gap: 0.3rem; min-width: 0; font-weight: 600; color: var(--color-red-600); }
.dark .lk-node__flaw { color: var(--color-red-400); }
.lk-dot { width: 0.4rem; height: 0.4rem; border-radius: 999px; background: var(--color-gray-300); }
.dark .lk-dot { background: var(--color-gray-600); }
.lk-dot--on { background: var(--color-success, var(--color-green-500)); }
.dark .lk-dot--on { background: var(--color-success, var(--color-green-500)); }

.lk-node--flawed { border-color: var(--color-red-400); }
.dark .lk-node--flawed { border-color: var(--color-red-500); }
/* A dead reference has one frame, whether the card is a ghost or a found-then-lost object. */
.lk-node--dead { border-style: dashed; }
.lk-node--dim { opacity: 0.35; }

/* Handles are anchors for the lines, not controls. */
.lk-node .vue-flow__handle { opacity: 0; pointer-events: none; width: 6px; height: 6px; min-width: 0; min-height: 0; }
</style>
