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
const dead = computed(() => !!props.data.bad && !!props.data.stub_end);
const showLabel = computed(() => !!props.data.label && !dead.value && (hover.value || picked.value || stroke.value === 'note'));

// A note's word sits beside its line on a chip, never on it: above a sideways piece,
// right of an upright one. The other labels (shown while hovering) stay centred on the line.
const labelStyle = computed(() => {
    const [x, y] = props.data.label_at;

    if (stroke.value !== 'note') return { transform: `translate(-50%, -50%) translate(${x}px, ${y}px)` };

    return props.data.label_sideways
        ? { transform: `translate(-50%, -100%) translate(${x}px, ${y - 3}px)` }
        : { transform: `translate(0, -50%) translate(${x + 8}px, ${y}px)` };
});

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
        <BaseEdge :id="id" :path="data.path" :marker-end="dead ? undefined : `url(#${marker})`" class="lk-edge__line" />
        <!-- The end of a line to something that is gone: a cross in a ring, where an arrowhead would be. -->
        <g v-if="dead" class="lk-edge__stub" :transform="`translate(${data.stub_end[0]} ${data.stub_end[1]})`" data-lk-edge-stub @click.stop="lk.selectEdge(id)" @pointerenter="hover = true" @pointerleave="hover = false">
            <circle r="8" />
            <path d="M -3 -3 L 3 3 M 3 -3 L -3 3" />
        </g>
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
                :class="{ 'lk-edge__label--note': stroke === 'note' }"
                :style="labelStyle"
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
.lk-edge--bad .lk-edge__line { stroke: var(--color-red-500); stroke-width: 1.75; }
.lk-edge__stub { cursor: pointer; }
.lk-edge__stub circle { fill: var(--color-white); stroke: var(--color-red-500); stroke-width: 1.5; }
.dark .lk-edge__stub circle { fill: var(--color-gray-900); }
.lk-edge__stub path { stroke: var(--color-red-500); stroke-width: 1.75; stroke-linecap: round; fill: none; }
.lk-edge--dim .lk-edge__stub { opacity: 0.25; }
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
/* The chip of a note wears its line's dashes, so the word and the line read as one thing. */
.lk-edge__label--note { border-style: dashed; border-color: var(--color-gray-500); }
</style>
