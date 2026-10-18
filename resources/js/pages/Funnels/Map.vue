<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { toast } from '@statamic/cms/api';
import {
    Header, Button, Badge, Panel, Card, Heading, Description, Select, Input, Stack, Field,
    Dropdown, DropdownMenu, DropdownItem, DropdownSeparator, EmptyStateMenu, EmptyStateItem,
} from '@statamic/cms/ui';

/**
 * The map of a funnel.
 *
 * Columns are phases, cards are references to objects that live in other
 * addons. The page stores a type and an id per card and nothing else: label,
 * link, switch and number come from the owning addon on every load. Edits are
 * held here and saved with one button, like the editor next door.
 *
 * Moving is done with buttons, not by dragging: every move is a labelled,
 * keyboard-reachable action.
 *
 * No `Statamic.$dirty` registration, like the editor next door: core's guard
 * challenges the save request itself and a cancelled visit never reports back
 * (measured here, see the leadhub note in `useDirtyGuard.js`). The unsaved badge
 * and the disabled period picker carry the warning instead.
 */
const props = defineProps({
    funnel: { type: Object, required: true },
    // Steps of the graph in walking order. Read-only: the graph owns them.
    path: { type: Array, default: () => [] },
    stages: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    period: { type: Object, required: true },
    saveUrl: { type: String, required: true },
    objectsUrl: { type: String, required: true },
    mapUrl: { type: String, required: true },
    editUrl: { type: String, required: true },
    indexUrl: { type: String, required: true },
    labels: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
});

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

const clone = (value) => JSON.parse(JSON.stringify(value));

const local = ref(clone(props.stages));
const saved = ref(JSON.stringify(props.stages.map(layout)));
const saving = ref(false);

function layout(stage) {
    return {
        id: stage.id ?? null,
        name: stage.name,
        refs: stage.refs.map((r) => ({ type: r.type, ref_id: r.ref_id })),
    };
}

const isDirty = computed(() => JSON.stringify(local.value.map(layout)) !== saved.value);

// After a save Inertia hands back the stored map, with the numbers.
watch(
    () => props.stages,
    (stages) => {
        // Edits made while the answer was on its way are not thrown away: the
        // fresh cards (with their numbers) replace the local ones only when
        // nothing was touched since the last save.
        const untouched = !isDirty.value;

        saved.value = JSON.stringify(stages.map(layout));
        if (untouched) local.value = clone(stages);
    },
);

const metricText = (metric) => (metric ? `${metric.value.toLocaleString()} ${metric.what}` : null);

function save() {
    saving.value = true;

    router.put(props.saveUrl, { stages: local.value.map(layout) }, {
        preserveScroll: true,
        onSuccess: () => { toast.success(t('saved', 'Saved.'), {}); },
        onError: (errors) => { toast.error(String(Object.values(errors ?? {})[0] ?? ''), {}); },
        onFinish: () => { saving.value = false; },
    });
}

function setPeriod(days) {
    router.get(props.mapUrl, { days }, { preserveScroll: true });
}

// --- phases ---------------------------------------------------------------

const stageDialog = ref({ open: false, index: null, name: '' });

function openStage(index = null) {
    stageDialog.value = { open: true, index, name: index === null ? '' : local.value[index].name };
}

function commitStage() {
    const { index, name } = stageDialog.value;
    const trimmed = name.trim();

    if (!trimmed) return;

    if (index === null) local.value.push({ id: null, name: trimmed, refs: [] });
    else local.value[index].name = trimmed;

    stageDialog.value.open = false;
}

function moveStage(index, by) {
    const target = index + by;
    if (target < 0 || target >= local.value.length) return;

    const [stage] = local.value.splice(index, 1);
    local.value.splice(target, 0, stage);
}

function removeStage(index) {
    if (window.confirm(t('remove_stage_confirm', 'Remove this phase?'))) local.value.splice(index, 1);
}

// --- cards ----------------------------------------------------------------

function moveCard(stageIndex, cardIndex, by) {
    const refs = local.value[stageIndex].refs;
    const target = cardIndex + by;
    if (target < 0 || target >= refs.length) return;

    const [card] = refs.splice(cardIndex, 1);
    refs.splice(target, 0, card);
}

function moveCardToStage(stageIndex, cardIndex, by) {
    const next = local.value[stageIndex + by];
    if (!next) return;

    const [card] = local.value[stageIndex].refs.splice(cardIndex, 1);

    // The same object twice in one phase is one card.
    if (!next.refs.some((r) => r.type === card.type && r.ref_id === card.ref_id)) next.refs.push(card);
}

function removeCard(stageIndex, cardIndex) {
    local.value[stageIndex].refs.splice(cardIndex, 1);
}

// --- picker ---------------------------------------------------------------

const picker = ref({ open: false, stageIndex: null, type: null, q: '', objects: [], loading: false, failed: false });

function openPicker(stageIndex) {
    picker.value = { open: true, stageIndex, type: props.types[0]?.value ?? null, q: '', objects: [], loading: false, failed: false };
    if (picker.value.type) search();
}

let timer = null;

function searchSoon() {
    clearTimeout(timer);
    timer = setTimeout(search, 250);
}

async function search() {
    const { type, q } = picker.value;
    if (!type) return;

    picker.value.loading = true;
    picker.value.failed = false;

    try {
        const url = new URL(props.objectsUrl, window.location.origin);
        url.searchParams.set('type', type);
        if (q) url.searchParams.set('q', q);

        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error(String(response.status));

        // A slower answer for an earlier choice must not overwrite a later one.
        if (picker.value.type === type && picker.value.q === q) picker.value.objects = (await response.json()).objects;
    } catch {
        picker.value.failed = true;
        picker.value.objects = [];
    } finally {
        picker.value.loading = false;
    }
}

function pickType(type) {
    picker.value.type = type;
    picker.value.objects = [];
    search();
}

const typeLabel = (type) => props.types.find((x) => x.value === type)?.label ?? type;

function alreadyPinned(object) {
    const refs = local.value[picker.value.stageIndex]?.refs ?? [];

    return refs.some((r) => r.type === object.type && r.ref_id === object.id);
}

function addObject(object) {
    local.value[picker.value.stageIndex].refs.push({
        type: object.type,
        type_label: typeLabel(object.type),
        ref_id: object.id,
        status: 'found',
        label: object.label,
        cp_url: object.cp_url,
        enabled: object.enabled,
        detail: object.detail,
        // The number arrives with the next load; an invented zero would be a claim.
        metric: null,
        fresh: true,
    });
}

const periodOptions = computed(() => props.period.options);
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper data-funnel-map>
        <Head :title="[t('title', 'Map'), funnel.title]" />

        <Header :title="funnel.title" icon="hierarchy">
            <Badge v-if="!funnel.published" color="amber" :text="__('statamic-funnels::messages.draft')" pill />
            <Badge v-else color="green" :text="__('statamic-funnels::messages.live')" pill />
            <Badge v-if="isDirty" color="default" :text="t('unsaved', 'Unsaved changes')" pill />
            <Select
                class="w-44"
                :model-value="period.days"
                :options="periodOptions"
                :disabled="isDirty"
                :aria-label="t('period', 'Period')"
                @update:model-value="setPeriod"
            />
            <Button :text="t('editor', 'Editor')" icon="hierarchy" :href="editUrl" />
            <Button :text="t('add_stage', 'Add phase')" icon="plus" @click="openStage()" />
            <Button variant="primary" :text="t('save', 'Save')" :disabled="saving || !isDirty" @click="save" />
        </Header>

        <p v-if="errors && Object.keys(errors).length" class="mb-4 text-sm text-red-600" role="alert">
            {{ Object.values(errors)[0] }}
        </p>

        <!-- The visitor path: what the graph already says, shown without being asked. -->
        <Panel :heading="t('path', 'Visitor path')" :subheading="t('path_help')" class="mb-6">
            <Card>
                <p v-if="!path.length" class="text-sm text-gray-600 dark:text-gray-400">{{ t('path_empty') }}</p>
                <ol v-else class="flex flex-wrap gap-3" data-funnel-map-path>
                    <li
                        v-for="(step, i) in path"
                        :key="step.ref_id"
                        class="min-w-48 max-w-64 flex-1 rounded-lg border border-content-border bg-content-bg p-3"
                        :class="{ 'opacity-60': step.enabled === false }"
                    >
                        <div class="flex items-center justify-between gap-2 text-2xs uppercase tracking-wide text-gray-600 dark:text-gray-400">
                            <span class="tabular-nums">{{ i + 1 }}</span>
                            <span class="truncate">{{ step.detail }}</span>
                        </div>
                        <a :href="step.cp_url" class="mt-1 block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ step.label }}</a>
                        <p v-if="step.metric" class="mt-1 text-sm tabular-nums text-gray-800 dark:text-gray-200">
                            <strong>{{ metricText(step.metric) }}</strong>
                            <span class="text-gray-600 dark:text-gray-400"> · {{ step.metric.period }}</span>
                        </p>
                        <p v-if="step.target" class="mt-2 border-t border-content-border pt-2 text-sm">
                            <span class="text-gray-600 dark:text-gray-400">{{ t('points_to', 'Points to') }} · {{ step.target.type_label }}</span><br>
                            <a v-if="step.target.cp_url" :href="step.target.cp_url" class="hover:underline">{{ step.target.label }}</a>
                            <span v-else>{{ step.target.label }}</span>
                            <Badge
                                v-if="step.target.status !== 'found'"
                                class="ml-1"
                                color="red"
                                pill
                                :text="step.target.status === 'missing' ? t('broken') : t('unavailable')"
                            />
                        </p>
                    </li>
                </ol>
            </Card>
        </Panel>

        <EmptyStateMenu v-if="!local.length" :heading="t('empty', 'No phases yet')">
            <EmptyStateItem :heading="t('add_stage', 'Add phase')" :description="t('empty_help')" icon="plus" @click="openStage()" />
        </EmptyStateMenu>

        <!-- The board: one column per phase. -->
        <div v-else class="flex items-start gap-4 overflow-x-auto pb-4" data-funnel-map-board>
            <section
                v-for="(stage, si) in local"
                :key="stage.id ?? `new-${si}`"
                class="w-80 shrink-0"
                :aria-label="stage.name"
                data-funnel-map-stage
            >
                <Panel :heading="stage.name">
                    <template #header-actions>
                        <Dropdown>
                            <DropdownMenu>
                                <DropdownItem icon="edit" :text="t('rename', 'Rename')" @click="openStage(si)" />
                                <DropdownItem v-if="si > 0" icon="arrow-left" :text="t('stage_left')" @click="moveStage(si, -1)" />
                                <DropdownItem v-if="si < local.length - 1" icon="arrow-right" :text="t('stage_right')" @click="moveStage(si, 1)" />
                                <DropdownSeparator />
                                <DropdownItem variant="destructive" icon="trash" :text="t('remove_stage')" @click="removeStage(si)" />
                            </DropdownMenu>
                        </Dropdown>
                    </template>

                    <div class="space-y-3">
                        <Card
                            v-for="(card, ci) in stage.refs"
                            :key="`${card.type}:${card.ref_id}`"
                            class="space-y-2"
                            data-funnel-map-card
                            :data-status="card.status"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-2xs uppercase tracking-wide text-gray-600 dark:text-gray-400">{{ card.type_label }}</span>
                                <Badge v-if="card.status === 'found' && card.enabled === true" color="green" pill :text="t('on', 'On')" />
                                <Badge v-else-if="card.status === 'found' && card.enabled === false" color="default" pill :text="t('off', 'Off')" />
                                <Badge v-else-if="card.status === 'missing'" color="red" pill icon="warning-diamond" :text="t('broken')" />
                                <Badge v-else-if="card.status === 'unavailable'" color="amber" pill :text="t('unavailable')" />
                                <span v-else class="text-2xs text-gray-600 dark:text-gray-400">{{ t('no_switch', 'No switch') }}</span>
                            </div>

                            <div>
                                <a v-if="card.cp_url" :href="card.cp_url" class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ card.label }}</a>
                                <span v-else class="font-medium text-gray-900 dark:text-gray-100" :class="{ 'line-through': card.status === 'missing' }">{{ card.label }}</span>
                                <p v-if="card.detail" class="truncate text-sm text-gray-600 dark:text-gray-400">{{ card.detail }}</p>
                                <Description v-if="card.status === 'missing'" :text="t('broken_help')" />
                                <Description v-else-if="card.status === 'unavailable'" :text="t('unavailable_help')" />
                            </div>

                            <p v-if="card.status === 'found'" class="text-sm tabular-nums">
                                <template v-if="card.metric">
                                    <strong class="text-gray-900 dark:text-gray-100">{{ metricText(card.metric) }}</strong>
                                    <span class="text-gray-600 dark:text-gray-400"> · {{ card.metric.period }}</span>
                                </template>
                                <span v-else class="text-gray-500">{{ t('no_number', 'No number') }}</span>
                            </p>

                            <div class="flex items-center gap-1 border-t border-content-border pt-2">
                                <Button size="xs" variant="ghost" icon="arrow-left" :disabled="si === 0" :aria-label="t('move_left')" @click="moveCardToStage(si, ci, -1)" />
                                <Button size="xs" variant="ghost" icon="arrow-up" :disabled="ci === 0" :aria-label="t('move_up')" @click="moveCard(si, ci, -1)" />
                                <Button size="xs" variant="ghost" icon="arrow-down" :disabled="ci === stage.refs.length - 1" :aria-label="t('move_down')" @click="moveCard(si, ci, 1)" />
                                <Button size="xs" variant="ghost" icon="arrow-right" :disabled="si === local.length - 1" :aria-label="t('move_right')" @click="moveCardToStage(si, ci, 1)" />
                                <span class="flex-1" />
                                <Button size="xs" variant="ghost" icon="trash" :aria-label="t('remove_ref')" @click="removeCard(si, ci)" />
                            </div>
                        </Card>

                        <Button class="w-full" icon="plus" :text="t('add_ref', 'Add reference')" :disabled="!types.length" @click="openPicker(si)" />
                    </div>
                </Panel>
            </section>
        </div>

        <p v-if="local.length" class="mt-2 max-w-prose text-sm text-gray-600 dark:text-gray-400">{{ t('hint_numbers') }}</p>

        <!-- Name a phase. -->
        <Stack v-model:open="stageDialog.open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="stageDialog.index === null ? t('add_stage') : t('rename')" size="lg" />
                </div>
                <div class="flex-1 px-6 py-5">
                    <Field :label="t('stage_name')" required>
                        <Input v-model="stageDialog.name" :placeholder="t('stage_name_placeholder')" maxlength="191" @keydown.enter="commitStage" />
                    </Field>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t('cancel', 'Cancel')" @click="stageDialog.open = false" />
                        <Button variant="primary" :text="stageDialog.index === null ? t('add') : t('rename')" :disabled="!stageDialog.name.trim()" @click="commitStage" />
                    </div>
                </div>
            </div>
        </Stack>

        <!-- Pin an object. -->
        <Stack v-model:open="picker.open" size="half">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="t('add_ref')" size="lg" />
                </div>
                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <Field :label="t('pick_type')">
                        <Select :model-value="picker.type" :options="types" @update:model-value="pickType" />
                    </Field>
                    <Field :label="t('search')">
                        <Input v-model="picker.q" type="search" @update:model-value="searchSoon" />
                    </Field>

                    <p v-if="picker.failed" class="text-sm text-red-600" role="alert">{{ t('unavailable_help') }}</p>
                    <p v-else-if="!picker.loading && !picker.objects.length" class="text-sm text-gray-600 dark:text-gray-400">{{ t('no_objects') }}</p>

                    <ul class="divide-y divide-content-border rounded-lg border border-content-border">
                        <li v-for="object in picker.objects" :key="object.id" class="flex items-center justify-between gap-3 px-3 py-2">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ object.label }}</div>
                                <div v-if="object.detail" class="truncate text-sm text-gray-600 dark:text-gray-400">{{ object.detail }}</div>
                            </div>
                            <Button size="sm" icon="plus" :text="t('add')" :disabled="alreadyPinned(object)" @click="addObject(object)" />
                        </li>
                    </ul>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end">
                        <Button variant="primary" :text="__('Done')" @click="picker.open = false" />
                    </div>
                </div>
            </div>
        </Stack>
    </div>
</template>
