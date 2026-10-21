<script setup>
import { computed, inject } from 'vue';
import { Button, Card, Checkbox, Combobox, Panel, Radio, RadioGroup, Stack, StackContent, StackFooter, Switch } from '@statamic/cms/ui';
import { freeTags } from '../../support/probelauf.js';
import ProbeRealPerson from './ProbeRealPerson.vue';

/**
 * "Person anpassen": everything that makes the test person this person, in a stack from the right, so the way stays in view
 * behind it. Tags and access are chosen from what the map knows (by name, not by identifier), and a tag may still be typed:
 * a condition of an addon that cannot name its own is still a tag a person may have. Nothing here is saved anywhere; the way
 * is planned again from what it says.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    who: { type: String, default: 'test' },
    personsUrl: { type: String, default: null },
    copiedFrom: { type: String, default: null },
    missing: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
    access: { type: Array, default: () => [] },
    ran: { type: Array, default: () => [] },
    unsubscribed: { type: Boolean, default: false },
    // What the map offers: access by name and kind, the tags its conditions ask for.
    options: { type: Object, default: () => ({ access: [], tags: [] }) },
    automations: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'patch', 'who', 'copy', 'reset']);
const probe = inject('probelauf');

const tagOptions = computed(() => {
    // What she has is always an option, so a copied tag that the map does not mention is not lost from the field.
    const names = [...new Set([...props.tags, ...(props.options.tags ?? [])])];

    return names.map((name) => ({ value: name, label: name }));
});
const free = computed(() => freeTags(props.options.tags ?? [], props.tags));

// Access she holds that the map does not list (copied from a real person) stays in the list, by its identifier.
const accessRows = computed(() => {
    const known = new Set((props.options.access ?? []).map((a) => a.value));

    return [
        ...(props.options.access ?? []),
        ...props.access.filter((slug) => !known.has(slug)).map((slug) => ({ value: slug, label: slug, kind: '' })),
    ];
});

const toggle = (key, list, value, on) => emit('patch', { [key]: on ? [...new Set([...list, value])] : list.filter((v) => v !== value) });
const addTag = (name) => emit('patch', { tags: [...new Set([...props.tags, name])] });
</script>

<template>
    <Stack :open="open" size="narrow" icon="user-avatar" :title="probe.t('probe_stack_title')" @update:open="emit('update:open', $event)">
        <StackContent>
        <div class="pk" data-pk>
            <p class="pk-lead">{{ probe.t('probe_stack_help') }}</p>

            <Panel :heading="probe.t('probe_who_title')">
                <Card>
                    <RadioGroup :model-value="who" data-pk-who @update:model-value="emit('who', $event)">
                        <Radio value="test" :label="probe.t('probe_who_test_option')" :description="probe.t('probe_who_test_desc')" data-pk-who-option="test" />
                        <Radio v-if="personsUrl" value="real" :label="probe.t('probe_who_real_option')" :description="probe.t('probe_who_real_desc')" data-pk-who-option="real" />
                    </RadioGroup>
                    <ProbeRealPerson
                        v-if="who === 'real' && personsUrl"
                        :url="personsUrl"
                        :copied-from="copiedFrom"
                        :missing="missing"
                        @copy="emit('copy', $event)"
                        @reset="emit('reset')"
                    />
                </Card>
            </Panel>

            <Panel :heading="probe.t('probe_traits_title')" :subheading="options.tags?.length ? probe.t('probe_traits_from_map') : ''">
                <Card>
                    <Combobox
                        multiple
                        taggable
                        searchable
                        :model-value="tags"
                        :options="tagOptions"
                        :placeholder="probe.t('probe_traits_add')"
                        data-pk-tags
                        @update:model-value="emit('patch', { tags: $event ?? [] })"
                    />
                    <p v-if="free.length" class="pk-free" data-pk-free>
                        <span>{{ probe.t('probe_traits_free') }}</span>
                        <button v-for="name in free" :key="name" type="button" class="pk-chip" :data-pk-free-tag="name" @click="addTag(name)">{{ name }}</button>
                    </p>
                </Card>
            </Panel>

            <Panel :heading="probe.t('probe_access_title')">
                <Card>
                    <p v-if="!accessRows.length" class="pk-none" data-pk-access-none>{{ probe.t('probe_access_none') }}</p>
                    <ul v-else class="pk-list">
                        <li v-for="row in accessRows" :key="row.value" class="pk-row">
                            <Checkbox
                                :model-value="access.includes(row.value)"
                                :label="row.label"
                                :data-pk-access="row.value"
                                @update:model-value="toggle('access', access, row.value, $event)"
                            />
                            <span v-if="row.kind" class="pk-kind">{{ row.kind }}</span>
                        </li>
                    </ul>
                </Card>
            </Panel>

            <Panel :heading="probe.t('probe_path_title')">
                <Card>
                    <div class="pk-switch">
                        <div>
                            <p class="pk-switch__label">{{ probe.t('probe_unsubscribed_label') }}</p>
                            <p class="pk-switch__help">{{ probe.t('probe_unsub_help') }}</p>
                        </div>
                        <Switch :model-value="unsubscribed" :aria-label="probe.t('probe_unsubscribed_label')" data-pk-unsubscribed @update:model-value="emit('patch', { unsubscribed: $event })" />
                    </div>
                    <template v-if="automations.length">
                        <p class="pk-sub">{{ probe.t('probe_ran_label') }}</p>
                        <ul class="pk-list">
                            <li v-for="a in automations" :key="a.id" class="pk-row">
                                <Checkbox
                                    :model-value="ran.includes(a.id)"
                                    :label="a.label"
                                    :data-pk-ran="a.id"
                                    @update:model-value="toggle('ran', ran, a.id, $event)"
                                />
                            </li>
                        </ul>
                    </template>
                </Card>
            </Panel>

        </div>
        </StackContent>
        <StackFooter>
            <template #start>
                <Button :text="probe.t('probe_reset')" data-pk-reset @click="emit('reset')" />
            </template>
            <template #end>
                <Button :text="probe.t('probe_done')" data-pk-done @click="emit('update:open', false)" />
            </template>
        </StackFooter>
    </Stack>
</template>

<style>
/* The stack brings its own padding; the groups sit close together like a form. */
.pk { padding: 0; }
/* Statamic's panel brings its own space below; the stack is one column of groups, close together like a form. */
.pk [data-ui-panel] { margin-bottom: 0.75rem; }
.pk-lead { margin: 0 0 1rem; font-size: 0.85rem; color: var(--color-gray-700); }
.dark .pk-lead { color: var(--color-gray-300); }
.pk-free { display: flex; align-items: center; flex-wrap: wrap; gap: 0.35rem 0.4rem; margin: 0.6rem 0 0; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pk-free { color: var(--color-gray-400); }
.pk-chip { padding: 0.1rem 0.55rem; border: 1px solid var(--color-gray-300); border-radius: 0.375rem; background: var(--color-white); font-size: 0.8rem; color: var(--color-gray-900); cursor: pointer; }
.pk-chip:hover { background: var(--color-gray-50); }
.pk-chip:focus-visible { outline: 2px solid var(--color-blue-400, var(--color-primary)); outline-offset: 1px; }
.dark .pk-chip { border-color: var(--color-gray-600); background: var(--color-gray-800); color: var(--color-gray-100); }
.dark .pk-chip:hover { background: var(--color-gray-700); }
.pk-list { margin: 0; padding: 0; list-style: none; }
.pk-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.4rem 0; }
.pk-row + .pk-row { border-top: 1px solid var(--color-gray-200); }
.dark .pk-row + .pk-row { border-top-color: var(--color-gray-700); }
.pk-kind { flex: none; font-size: 0.78rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pk-kind { color: var(--color-gray-400); }
.pk-none { margin: 0; font-size: 0.85rem; color: var(--color-gray-600); }
.dark .pk-none { color: var(--color-gray-400); }
.pk-switch { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--color-gray-200); }
.dark .pk-switch { border-bottom-color: var(--color-gray-700); }
.pk-switch__label { margin: 0; font-size: 0.875rem; color: var(--color-gray-900); }
.dark .pk-switch__label { color: var(--color-gray-100); }
.pk-switch__help { margin: 0.1rem 0 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pk-switch__help { color: var(--color-gray-400); }
.pk-sub { margin: 0.7rem 0 0.1rem; font-size: 0.875rem; color: var(--color-gray-900); }
.dark .pk-sub { color: var(--color-gray-100); }
</style>
