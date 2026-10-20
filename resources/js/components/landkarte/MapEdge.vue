<script setup>
import { computed, inject, ref } from 'vue';
import { BaseEdge, EdgeLabelRenderer } from '@vue-flow/core';
import { strokeOf } from '../../support/landkarteLayout.js';

/**
 * A connection between two cards: a thin orthogonal line with an arrow. The
 * route is worked out by the layout (`landkarteLayout.js`), which knows where
 * every card and every gutter is, so the line is drawn from `data.path` and
 * not from the canvas's own routing.
 *
 * Grey by default and solid: an addon holds this connection in its own data.
 * Beyond it, each look is a statement: the CP's primary colour for the main
 * path from the entry to the product that earned money, dotted for a wait (not
 * a hand-over of people), long dashes for a note (an arrow drawn by hand that no
 * addon stands behind, marked "Note"), red for a connection that ends at
 * something that is gone.
 *
 * The name of the connection is not on the line. It appears while the pointer
 * is on the line, or when the line is the selection: one label at a time, so
 * labels never lie on top of each other.
 *
 * There is no number on the line. How many people walked a connection needs
 * tracking nobody has built; the panel says so instead of showing a made-up
 * figure.
 */
const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const lk = inject('landkarte');
const hover = ref(false);

const lit = computed(() => lk.lit.value.size > 0);
const onWay = computed(() => lk.litEdges.value.has(props.id));
const picked = computed(() => lk.selectedEdge.value === props.id);
const dim = computed(() => (lit.value && !onWay.value && !picked.value) || (!lit.value && !!lk.selectedEdge.value && !picked.value));
const stroke = computed(() => strokeOf(props.data));
// A note arrow always carries its word: it is the one line no addon stands behind.
const noteWord = computed(() => lk.t('map_edge_note_label', 'Note'));
const labelText = computed(() => (stroke.value === 'note' && props.data.label !== noteWord.value ? `${noteWord.value}: ${props.data.label}` : props.data.label));
const showLabel = computed(() => !!props.data.label && (hover.value || picked.value || stroke.value === 'note'));

const classes = computed(() => ({
    'lk-edge--main': props.data.main,
    'lk-edge--wait': stroke.value === 'wait',
    'lk-edge--note': stroke.value === 'note',
    'lk-edge--bad': props.data.bad,
    'lk-edge--on': (onWay.value || picked.value || hover.value) && !props.data.main && !props.data.bad,
    'lk-edge--dim': dim.value,
}));

// The arrow's colour follows the line, so each state needs its own marker.
const marker = computed(() => {
    if (props.data.bad) return 'lk-arrow-bad';
    if (props.data.main) return 'lk-arrow-main';
    if (onWay.value || picked.value || hover.value) return 'lk-arrow-on';
    return 'lk-arrow';
});
</script>

<template>
    <g :class="classes" class="lk-edge" :data-lk-edge="id" :data-stroke="stroke">
        <BaseEdge :id="id" :path="data.path" :marker-end="`url(#${marker})`" class="lk-edge__line" />
        <path
            :d="data.path"
            class="lk-edge__hit"
            fill="none"
            @click.stop="lk.selectEdge(id)"
            @pointerenter="hover = true"
            @pointerleave="hover = false"
        />
        <EdgeLabelRenderer>
            <div
                v-if="showLabel"
                class="lk-edge__label nodrag nopan"
                :style="{ transform: `translate(-50%, -50%) translate(${data.label_at[0]}px, ${data.label_at[1]}px)` }"
                data-lk-edge-label
            >
                {{ labelText }}
            </div>
        </EdgeLabelRenderer>
    </g>
</template>

<style>
/* Not scoped: Vue Flow renders the path in its own tree. */
.lk-edge__line { stroke: var(--color-gray-400); stroke-width: 1.5; fill: none; transition: opacity 0.15s; }
.dark .lk-edge__line { stroke: var(--color-gray-600); }
.lk-edge--note .lk-edge__line { stroke-dasharray: 9 5; }
.lk-edge--wait .lk-edge__line { stroke-dasharray: 0.1 5; stroke-linecap: round; stroke-width: 2.25; }
.lk-edge--on .lk-edge__line { stroke: var(--color-gray-600); stroke-width: 2; }
.dark .lk-edge--on .lk-edge__line { stroke: var(--color-gray-300); }
.lk-edge--main .lk-edge__line { stroke: var(--color-primary); stroke-width: 2.25; }
.lk-edge--bad .lk-edge__line { stroke: var(--color-red-500); stroke-width: 1.75; stroke-dasharray: 5 4; }
.lk-edge--dim .lk-edge__line { opacity: 0.25; }
.lk-edge__hit { stroke: transparent; stroke-width: 14; cursor: pointer; pointer-events: stroke; }

.lk-edge__label {
    position: absolute;
    pointer-events: none;
    padding: 0.1rem 0.45rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-gray-200);
    background: var(--color-content-bg, #fff);
    color: var(--color-gray-700);
    font-size: 0.7rem;
    line-height: 1.3;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
    z-index: 4;
}
.dark .lk-edge__label { border-color: var(--color-gray-700); color: var(--color-gray-300); }
</style>
