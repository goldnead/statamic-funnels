<script setup>
import { inject } from 'vue';
import { Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Button } from '@statamic/cms/ui';

/**
 * One phase lane: a pale stripe the full height of the board, with a coloured
 * header that names the phase and says in one line what it is for.
 */
const props = defineProps({ data: { type: Object, required: true } });

const lk = inject('landkarte');
</script>

<template>
    <div
        class="lk-lane"
        :class="[`lk-tone--${data.lane.tone}`, data.last && 'lk-lane--last']"
        :style="{ width: `${data.width}px`, height: `${data.height}px` }"
        data-lk-lane
    >
        <div class="lk-lane__head" :style="{ height: `${data.headHeight - 24}px` }">
            <div class="lk-lane__text">
                <div class="lk-lane__name">{{ data.lane.name }}</div>
                <div v-if="data.lane.purpose" class="lk-lane__purpose">{{ data.lane.purpose }}</div>
            </div>
            <Dropdown v-if="lk.editing.value" align="end" class="nodrag nopan">
                <template #trigger>
                    <Button icon="dots" variant="ghost" size="sm" inset :aria-label="lk.t('map_rename')" @pointerdown.stop @click.stop />
                </template>
                <DropdownMenu>
                    <DropdownItem icon="edit" :text="lk.t('map_rename')" @click="lk.openLane(data.index)" />
                    <DropdownItem v-if="data.index > 0" icon="arrow-left" :text="lk.t('map_stage_left')" @click="lk.moveLane(data.index, -1)" />
                    <DropdownItem v-if="!data.last" icon="arrow-right" :text="lk.t('map_stage_right')" @click="lk.moveLane(data.index, 1)" />
                    <DropdownSeparator />
                    <DropdownItem variant="destructive" icon="trash" :text="lk.t('map_remove_stage')" @click="lk.removeLane(data.index)" />
                </DropdownMenu>
            </Dropdown>
        </div>
    </div>
</template>

<style>
.lk-lane {
    --lk-tone: var(--color-violet-500);
    position: relative;
    box-sizing: border-box;
    padding: 12px 12px 0;
    background: color-mix(in srgb, var(--lk-tone) 5.5%, transparent);
    border-right: 1px solid color-mix(in srgb, var(--lk-tone) 22%, transparent);
    pointer-events: none;
}
.lk-lane--last { border-right: 0; }
.lk-tone--0 { --lk-tone: var(--color-violet-500); }
.lk-tone--1 { --lk-tone: var(--color-emerald-500); }
.lk-tone--2 { --lk-tone: var(--color-sky-500); }
.lk-tone--3 { --lk-tone: var(--color-orange-500); }
.lk-tone--4 { --lk-tone: var(--color-fuchsia-500); }

.lk-lane__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.6rem 0.8rem;
    border-radius: 0.6rem;
    background: color-mix(in srgb, var(--lk-tone) 16%, transparent);
    pointer-events: auto;
}
.lk-lane__text { min-width: 0; flex: 1; text-align: center; }
.lk-lane__name { font-size: 1.05rem; font-weight: 700; line-height: 1.2; color: color-mix(in srgb, var(--lk-tone) 72%, var(--color-gray-900)); }
.dark .lk-lane__name { color: color-mix(in srgb, var(--lk-tone) 60%, var(--color-gray-100)); }
.lk-lane__purpose { margin-top: 0.15rem; font-size: 0.78rem; line-height: 1.3; color: var(--color-gray-700); }
.dark .lk-lane__purpose { color: var(--color-gray-300); }
</style>
