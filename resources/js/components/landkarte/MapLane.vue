<script setup>
import { inject } from 'vue';
import { Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, Button } from '@statamic/cms/ui';

/**
 * One phase lane: a quiet neutral column the full height of the board, headed
 * by the name of the phase in plain text and, under it, what it is for.
 */
const props = defineProps({ data: { type: Object, required: true } });

const lk = inject('landkarte');
</script>

<template>
    <div
        class="lk-lane"
        :class="data.last && 'lk-lane--last'"
        :style="{ width: `${data.width}px`, height: `${data.height}px` }"
        data-lk-lane
    >
        <div class="lk-lane__head" :style="{ height: `${data.headHeight}px` }">
            <div class="lk-lane__text">
                <div class="lk-lane__name">{{ data.lane.name }}</div>
                <div v-if="data.lane.purpose" class="lk-lane__purpose">{{ data.lane.purpose }}</div>
            </div>
            <Dropdown align="end" class="nodrag nopan">
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
    position: relative;
    box-sizing: border-box;
    background: color-mix(in srgb, var(--color-gray-100) 55%, transparent);
    border-right: 1px solid var(--color-gray-200);
    pointer-events: none;
}
.dark .lk-lane { background: color-mix(in srgb, var(--color-gray-800) 45%, transparent); border-right-color: var(--color-gray-700); }
.lk-lane--last { border-right: 0; }

.lk-lane__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.85rem 1rem 0;
    border-bottom: 1px solid var(--color-gray-200);
    pointer-events: auto;
}
.dark .lk-lane__head { border-bottom-color: var(--color-gray-700); }
.lk-lane__text { min-width: 0; flex: 1; }
.lk-lane__name { font-size: 0.875rem; font-weight: 600; line-height: 1.25; color: var(--color-gray-900); }
.dark .lk-lane__name { color: var(--color-gray-100); }
.lk-lane__purpose { margin-top: 0.1rem; font-size: 0.75rem; line-height: 1.3; color: var(--color-gray-600); }
.dark .lk-lane__purpose { color: var(--color-gray-400); }
</style>
