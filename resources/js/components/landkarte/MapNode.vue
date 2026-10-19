<script setup>
import { computed, inject } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { Icon } from '@statamic/cms/ui';
import { kindOf } from '../../support/landkarteLayout.js';

/**
 * One object on the Landkarte: a card in the same form as the editors'
 * (`.sa-node`: chip, title, mono subline, figure band, footer), with a pill for
 * the kind and a red edge for a broken reference.
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
const dimmed = computed(() => lk.dimmed.value && !lk.lit.value.has(props.id));
const metric = computed(() => props.data.metric);
const drills = computed(() => !!props.data.drill_url);

const stateText = computed(() => {
    if (broken.value) return lk.t('map_broken');
    if (unavailable.value) return lk.t('map_unavailable');
    if (props.data.enabled === true) return lk.t('map_on');
    if (props.data.enabled === false) return lk.t('map_off');
    return lk.t('map_no_switch');
});

const stateTone = computed(() => {
    if (broken.value) return 'bad';
    if (unavailable.value) return 'warn';
    if (props.data.enabled === true) return 'on';
    return 'idle';
});
</script>

<template>
    <div
        class="sa-node lk-node"
        :class="[
            `lk-accent--${kind.accent}`,
            selected && 'sa-node--selected',
            (broken || problems.length) && 'lk-node--problem',
            dimmed && 'lk-node--dim',
            data.main && 'lk-node--main',
            data.ghost && 'lk-node--ghost',
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
                <span class="lk-pill">{{ data.type_label }}</span>
                <div class="lk-node__title" :title="data.label" :class="{ 'lk-node__title--gone': broken }">{{ data.label }}</div>
                <div v-if="data.detail" class="lk-node__sub" :title="data.detail">{{ data.detail }}</div>
            </div>
        </div>

        <div v-if="broken" class="lk-node__alert" data-lk-alert>
            <Icon name="warning-diamond" class="size-3.5" />
            <span>{{ data.ghost ? lk.t('map_dead_reference') : lk.t('map_broken') }}</span>
        </div>
        <div v-else-if="problems.length" class="lk-node__alert" data-lk-alert>
            <Icon name="warning-diamond" class="size-3.5" />
            <span>{{ problems.length === 1 ? lk.t('map_problem_one') : lk.t('map_problem_many').replace(':count', problems.length) }}</span>
        </div>
        <div v-else-if="metric" class="lk-node__stat" data-lk-stat>
            <strong>{{ Number(metric.value).toLocaleString() }}</strong>
            <span>{{ metric.what }}</span>
        </div>
        <div v-else-if="!unavailable" class="lk-node__stat lk-node__stat--none">{{ lk.t('map_no_number') }}</div>

        <div class="lk-node__foot">
            <span class="lk-dot" :class="`lk-dot--${stateTone}`" />
            <span>{{ stateText }}</span>
            <span v-if="drills" class="lk-node__drill" :title="lk.t('map_double_click_hint')">
                <Icon name="arrow-right" class="size-3" />
            </span>
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
    width: 250px;
    padding: 0.7rem 0.8rem 0.55rem;
    --lk-accent: var(--color-gray-500);
    transition: opacity 0.15s, box-shadow 0.15s;
    cursor: pointer;
}
.lk-node:focus-visible { outline: 2px solid var(--color-blue-500); outline-offset: 2px; }
.lk-accent--blue { --lk-accent: var(--color-blue-600); }
.lk-accent--green { --lk-accent: var(--color-emerald-600); }
.lk-accent--sky { --lk-accent: var(--color-sky-600); }
.lk-accent--indigo { --lk-accent: var(--color-indigo-600); }
.lk-accent--fuchsia { --lk-accent: var(--color-fuchsia-600); }
.lk-accent--purple { --lk-accent: var(--color-purple-600); }
.lk-accent--amber { --lk-accent: var(--color-amber-600); }
.dark .lk-node { --lk-accent: var(--color-gray-400); }
.dark .lk-accent--blue { --lk-accent: var(--color-blue-400); }
.dark .lk-accent--green { --lk-accent: var(--color-emerald-400); }
.dark .lk-accent--sky { --lk-accent: var(--color-sky-400); }
.dark .lk-accent--indigo { --lk-accent: var(--color-indigo-400); }
.dark .lk-accent--fuchsia { --lk-accent: var(--color-fuchsia-400); }
.dark .lk-accent--purple { --lk-accent: var(--color-purple-400); }
.dark .lk-accent--amber { --lk-accent: var(--color-amber-400); }

.lk-chip { --sa-chip-color: var(--lk-accent); }

.lk-node__head { display: flex; gap: 0.6rem; align-items: flex-start; }
.lk-node__heading { min-width: 0; flex: 1; }
.lk-pill {
    display: inline-block;
    padding: 0.05rem 0.5rem;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--lk-accent);
    background: color-mix(in srgb, var(--lk-accent) 13%, transparent);
}
.lk-node__title {
    margin-top: 0.25rem;
    font-size: 0.88rem;
    font-weight: 650;
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
.lk-node__sub {
    margin-top: 0.1rem;
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 0.68rem;
    color: var(--color-gray-600);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dark .lk-node__sub { color: var(--color-gray-400); }

.lk-node__stat {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
    margin-top: 0.55rem;
    padding: 0.35rem 0.55rem;
    border-radius: 0.5rem;
    background: var(--color-gray-100);
    font-size: 0.72rem;
    color: var(--color-gray-700);
}
.dark .lk-node__stat { background: color-mix(in srgb, var(--color-gray-700) 55%, transparent); color: var(--color-gray-300); }
.lk-node__stat strong { font-size: 0.95rem; color: var(--color-gray-900); font-variant-numeric: tabular-nums; }
.dark .lk-node__stat strong { color: var(--color-gray-100); }
.lk-node__stat--none { color: var(--color-gray-600); }
.dark .lk-node__stat--none { color: var(--color-gray-400); }

.lk-node__alert {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.55rem;
    padding: 0.35rem 0.55rem;
    border-radius: 0.5rem;
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--color-red-700);
    background: color-mix(in srgb, var(--color-red-500) 11%, transparent);
}
.dark .lk-node__alert { color: var(--color-red-300); }

.lk-node__foot {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.5rem;
    font-size: 0.7rem;
    color: var(--color-gray-600);
}
.dark .lk-node__foot { color: var(--color-gray-400); }
.lk-node__drill { margin-left: auto; opacity: 0.7; }
.lk-dot { width: 0.5rem; height: 0.5rem; border-radius: 999px; background: var(--color-gray-400); }
.lk-dot--on { background: var(--color-green-500); }
.lk-dot--bad { background: var(--color-red-500); }
.lk-dot--warn { background: var(--color-amber-500); }

.lk-node--problem { border-color: var(--color-red-500); box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-red-500) 45%, transparent); background: color-mix(in srgb, var(--color-red-500) 4%, var(--color-white, #fff)); }
.dark .lk-node--problem { background: color-mix(in srgb, var(--color-red-500) 9%, var(--color-gray-900)); }
.lk-node--ghost { border-style: dashed; }
.lk-node--dim { opacity: 0.32; }

/* Handles are anchors for the lines, not controls. */
.lk-node .vue-flow__handle { opacity: 0; pointer-events: none; width: 6px; height: 6px; min-width: 0; min-height: 0; }
</style>
