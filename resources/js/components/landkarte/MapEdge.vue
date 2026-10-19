<script setup>
import { computed, inject } from 'vue';
import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath } from '@vue-flow/core';

/**
 * A connection between two cards: an orthogonal line with an arrow and the
 * name of the relation on it.
 *
 * Three looks beyond the plain grey, each one a statement. Thick and green: the
 * main path from the entry to the product that earned money. Dashed: a wait,
 * not a hand-over of people. Red: it ends at something that is gone.
 *
 * There is no number on the line. How many people walked a connection needs
 * tracking nobody has built; the panel says so instead of showing a made-up
 * figure.
 */
const props = defineProps({
    id: { type: String, required: true },
    sourceX: { type: Number, required: true },
    sourceY: { type: Number, required: true },
    targetX: { type: Number, required: true },
    targetY: { type: Number, required: true },
    sourcePosition: { type: String, required: true },
    targetPosition: { type: String, required: true },
    data: { type: Object, required: true },
});

const lk = inject('landkarte');

const path = computed(() =>
    getSmoothStepPath({
        sourceX: props.sourceX,
        sourceY: props.sourceY,
        sourcePosition: props.sourcePosition,
        targetX: props.targetX,
        targetY: props.targetY,
        targetPosition: props.targetPosition,
        borderRadius: 14,
        offset: 26,
    }),
);

const lit = computed(() => lk.lit.value.size > 0);
const onWay = computed(() => lk.litEdges.value.has(props.id));
const picked = computed(() => lk.selectedEdge.value === props.id);
const dim = computed(() => (lit.value && !onWay.value && !picked.value) || (!lit.value && lk.selectedEdge.value && !picked.value));

const classes = computed(() => ({
    'lk-edge--main': props.data.main,
    'lk-edge--dashed': props.data.dashed,
    'lk-edge--bad': props.data.bad,
    'lk-edge--hand': props.data.hand,
    'lk-edge--on': onWay.value || picked.value,
    'lk-edge--dim': dim.value,
}));

// The arrow's colour follows the line, so each state needs its own marker.
const marker = computed(() => {
    if (props.data.bad) return 'lk-arrow-bad';
    if (onWay.value || picked.value) return 'lk-arrow-on';
    if (props.data.main) return 'lk-arrow-main';
    return 'lk-arrow';
});
</script>

<template>
    <g :class="classes" class="lk-edge" :data-lk-edge="id">
        <BaseEdge :id="id" :path="path[0]" :marker-end="`url(#${marker})`" class="lk-edge__line" />
        <path :d="path[0]" class="lk-edge__hit" fill="none" @click.stop="lk.selectEdge(id)" />
        <EdgeLabelRenderer>
            <div
                v-if="data.label"
                class="lk-edge__label nodrag nopan"
                :class="classes"
                :style="{ transform: `translate(-50%, -50%) translate(${path[1]}px, ${path[2]}px)` }"
                role="button"
                tabindex="0"
                :aria-label="data.label"
                @click.stop="lk.selectEdge(id)"
                @keydown.enter.prevent="lk.selectEdge(id)"
            >
                {{ data.label }}
            </div>
        </EdgeLabelRenderer>
    </g>
</template>

<style>
/* Not scoped: Vue Flow renders the path in its own tree. */
.lk-edge__line { stroke: var(--color-gray-400); stroke-width: 1.6; fill: none; }
.dark .lk-edge__line { stroke: var(--color-gray-500); }
.lk-edge--dashed .lk-edge__line { stroke-dasharray: 6 5; }
.lk-edge--main .lk-edge__line { stroke: var(--color-green-700); stroke-width: 5; }
.dark .lk-edge--main .lk-edge__line { stroke: var(--color-green-400); }
.lk-edge--on .lk-edge__line { stroke: var(--color-blue-500); stroke-width: 2.6; }
.lk-edge--main.lk-edge--on .lk-edge__line { stroke-width: 4.4; }
.lk-edge--bad .lk-edge__line { stroke: var(--color-red-500); stroke-width: 2.4; stroke-dasharray: 6 5; }
.lk-edge--dim .lk-edge__line { opacity: 0.2; }
.lk-edge__hit { stroke: transparent; stroke-width: 18; cursor: pointer; pointer-events: stroke; }

.lk-edge__label {
    position: absolute;
    pointer-events: all;
    cursor: pointer;
    padding: 0.12rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--color-gray-300);
    background: var(--color-white, #fff);
    color: var(--color-gray-800);
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
    z-index: 4;
}
.dark .lk-edge__label { background: var(--color-gray-800); border-color: var(--color-gray-600); color: var(--color-gray-200); }
.lk-edge__label.lk-edge--main { border-color: var(--color-green-600); color: var(--color-green-800); background: var(--color-green-50); }
.dark .lk-edge__label.lk-edge--main { border-color: var(--color-green-500); color: var(--color-green-200); background: color-mix(in srgb, var(--color-green-500) 16%, var(--color-gray-900)); }
.lk-edge__label.lk-edge--on { border-color: var(--color-blue-500); color: var(--color-blue-700); }
.dark .lk-edge__label.lk-edge--on { color: var(--color-blue-300); }
.lk-edge__label.lk-edge--bad { border-color: var(--color-red-400); color: var(--color-red-700); background: var(--color-red-50); }
.dark .lk-edge__label.lk-edge--bad { color: var(--color-red-300); background: color-mix(in srgb, var(--color-red-500) 14%, var(--color-gray-900)); }
.lk-edge__label.lk-edge--dim { opacity: 0.3; }
.lk-edge__label:focus-visible { outline: 2px solid var(--color-blue-500); outline-offset: 2px; }
</style>
