<script setup>
import { computed, inject } from 'vue';
import { Button, Icon } from '@statamic/cms/ui';
import { countsText, iconFor, stepsByDay } from '../../support/probelauf.js';

/**
 * The left column: what happens to the person, by day. One row for every mail she gets, page she lands on, access she is
 * given and decision on her way; the row that is open is marked, and a day can be "fast forwarded to": the way is shown up to
 * that day, which is where she stands then.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
    selected: { type: String, default: null },
    // The day the way is shown up to; null is all of it.
    until: { type: Number, default: null },
});

const emit = defineEmits(['select', 'skip', 'unskip']);
const probe = inject('probelauf');

const days = computed(() => stepsByDay(props.entries));
const firstDay = computed(() => days.value[0]?.day ?? 0);
const summary = (group) => countsText(group.counts, probe.t);

// Up and down move through the rows like a mailbox does; the focus follows, a row opens on Enter or click.
function onKey(event) {
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;

    const rows = [...event.currentTarget.querySelectorAll('.ps-row')];
    const at = rows.indexOf(document.activeElement);

    if (at === -1) return;

    event.preventDefault();
    rows[Math.min(rows.length - 1, Math.max(0, at + (event.key === 'ArrowDown' ? 1 : -1)))].focus();
}
</script>

<template>
    <div class="ps" data-ps @keydown="onKey">
        <section v-for="group in days" :key="group.day" class="ps-day" :data-ps-day="group.day">
            <header class="ps-day__head">
                <h3>{{ probe.t('probe_day').replace(':day', group.day) }}</h3>
                <Button
                    v-if="group.day !== firstDay && group.day !== until"
                    size="xs"
                    variant="ghost"
                    icon="arrow-right"
                    :text="probe.t('probe_skip_to')"
                    :data-ps-skip="group.day"
                    @click="emit('skip', group.day)"
                />
                <span v-else-if="summary(group)" class="ps-day__sum">{{ summary(group) }}</span>
            </header>

            <ul class="ps-list">
                <li v-for="entry in group.rows" :key="entry.id">
                    <button
                        type="button"
                        class="ps-row"
                        :class="[selected === entry.id && 'ps-row--on', `ps-row--${entry.kind}`]"
                        :aria-current="selected === entry.id ? 'true' : undefined"
                        :data-ps-row="entry.id"
                        :data-ps-kind="entry.kind"
                        @click="emit('select', entry)"
                    >
                        <Icon v-if="iconFor(entry)" :name="iconFor(entry)" class="ps-icon size-4" :title="probe.t(`probe_kind_${entry.kind}`, '')" />
                        <span class="ps-label">{{ entry.label }}</span>
                        <span v-if="probe.timeInDay(entry.at)" class="ps-time">{{ probe.timeInDay(entry.at) }}</span>
                    </button>
                </li>
            </ul>
        </section>

        <div v-if="until !== null" class="ps-more">
            <Button size="sm" variant="ghost" icon="arrow-right" :text="probe.t('probe_skip_reset')" data-ps-unskip @click="emit('unskip')" />
        </div>
    </div>
</template>

<style>
.ps { min-width: 0; }
.ps-day + .ps-day { margin-top: 1.1rem; }
.ps-day__head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; min-height: 1.5rem; margin: 0 0 0.35rem; }
.ps-day__head h3 { margin: 0; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--color-gray-900); }
.dark .ps-day__head h3 { color: var(--color-gray-100); }
.ps-day__sum { font-size: 0.78rem; color: var(--color-gray-600); }
.dark .ps-day__sum { color: var(--color-gray-400); }
.ps-day__head button { color: var(--color-gray-600); }
.dark .ps-day__head button { color: var(--color-gray-400); }
.ps-list { margin: 0; padding: 0; list-style: none; overflow: hidden; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; background: var(--color-white); }
.dark .ps-list { border-color: var(--color-gray-700); background: var(--color-gray-900); }
.ps-list > li + li { border-top: 1px solid var(--color-gray-200); }
.dark .ps-list > li + li { border-top-color: var(--color-gray-700); }
.ps-row { display: flex; align-items: center; gap: 0.6rem; width: 100%; padding: 0.55rem 0.75rem; background: transparent; border: 0; text-align: left; cursor: pointer; font-size: 0.875rem; color: var(--color-gray-900); }
.dark .ps-row { color: var(--color-gray-100); }
.ps-row:hover { background: var(--color-gray-50); }
.dark .ps-row:hover { background: color-mix(in srgb, var(--color-gray-800) 55%, transparent); }
.ps-row:focus-visible { outline: 2px solid var(--color-primary); outline-offset: -2px; }
.ps-row--on, .ps-row--on:hover { background: var(--color-gray-100); font-weight: 500; }
.dark .ps-row--on, .dark .ps-row--on:hover { background: var(--color-gray-800); }
.ps-icon { flex: none; color: var(--color-gray-500); }
.ps-label { min-width: 0; flex: 1; overflow-wrap: anywhere; }
.ps-time { flex: none; font-size: 0.75rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .ps-time { color: var(--color-gray-400); }
.ps-row--stop, .ps-row--denied { color: var(--color-gray-600); }
.dark .ps-row--stop, .dark .ps-row--denied { color: var(--color-gray-400); }
.ps-more { margin-top: 0.75rem; }
</style>
