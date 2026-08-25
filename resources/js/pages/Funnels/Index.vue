<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, EmptyStateMenu, EmptyStateItem, DocsCallout,
    CommandPaletteItem, Stack, Heading, Field, Input,
} from '@statamic/cms/ui';

/**
 * The list of funnels.
 *
 * A plain list rather than a `Listing`: a site has a handful of funnels, not a
 * thousand, and the machinery a Listing brings — server paging, saved views,
 * column preferences — would be scaffolding around six rows.
 */
const props = defineProps({
    funnels: { type: Array, default: () => [] },
    createUrl: { type: String, required: true },
});

const open = ref(false);
const title = ref('');
const saving = ref(false);
const errors = ref({});

function create() {
    saving.value = true;
    router.post(props.createUrl, { title: title.value }, {
        onError: (e) => { errors.value = e || {}; },
        onFinish: () => { saving.value = false; },
    });
}

function remove(funnel) {
    router.delete(funnel.delete_url, { preserveScroll: true });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[__('statamic-funnels::messages.utility_title')]" />

        <Header :title="__('statamic-funnels::messages.utility_title')" icon="hierarchy">
            <Button variant="primary" :text="__('statamic-funnels::messages.new_funnel')" @click="open = true" />
        </Header>

        <CommandPaletteItem
            :text="[__('Utilities'), __('statamic-funnels::messages.utility_title')]"
            url="/cp/utilities/funnels"
            icon="hierarchy"
            prioritize
        />

        <EmptyStateMenu v-if="!funnels.length" :heading="__('statamic-funnels::messages.empty_heading')">
            <EmptyStateItem
                :heading="__('statamic-funnels::messages.empty_title')"
                :description="__('statamic-funnels::messages.empty_description')"
                icon="hierarchy"
                @click="open = true"
            />
        </EmptyStateMenu>

        <div v-else class="grid gap-3">
            <div
                v-for="funnel in funnels"
                :key="funnel.id"
                class="flex items-center gap-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"
            >
                <div class="min-w-0 flex-1">
                    <a :href="funnel.edit_url" class="font-medium hover:text-primary">{{ funnel.title }}</a>
                    <span class="block font-mono text-2xs text-gray-500 dark:text-gray-400">{{ funnel.handle }}</span>
                </div>

                <Badge
                    :color="funnel.published ? 'green' : 'amber'"
                    :text="funnel.published ? __('statamic-funnels::messages.live') : __('statamic-funnels::messages.draft')"
                />

                <span class="tabular-nums text-sm text-gray-600 dark:text-gray-400">
                    {{ funnel.steps_count }} {{ __('statamic-funnels::messages.steps') }}
                </span>
                <span class="tabular-nums text-sm text-gray-600 dark:text-gray-400">
                    {{ funnel.visits_count }} {{ __('statamic-funnels::messages.visits') }}
                </span>

                <Button icon="edit" :text="__('Edit')" :href="funnel.edit_url" />
                <Button icon="trash" variant="ghost" :text="__('Delete')" @click="remove(funnel)" />
            </div>
        </div>

        <Stack v-model:open="open" size="narrow">
            <div class="flex h-full flex-col bg-white dark:bg-gray-900">
                <div class="border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                    <Heading :text="__('statamic-funnels::messages.new_funnel')" size="lg" />
                </div>

                <div class="flex-1 px-6 py-5">
                    <Field
                        :label="__('statamic-funnels::messages.field_title')"
                        :instructions="__('statamic-funnels::messages.field_title_help')"
                        :error="errors.title"
                        required
                    >
                        <Input v-model="title" />
                    </Field>
                </div>

                <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-800">
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Cancel')" @click="open = false" />
                        <Button variant="primary" :text="__('Create')" :disabled="saving || !title" @click="create" />
                    </div>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="__('statamic-funnels::messages.utility_title')"
            url="https://github.com/goldnead/statamic-funnels#readme"
        />
    </div>
</template>
