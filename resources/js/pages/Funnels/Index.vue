<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import {
    Header, Button, Badge, EmptyStateMenu, EmptyStateItem, DocsCallout,
    CommandPaletteItem, Stack, Heading, Field, Input, Listing, DropdownItem,
} from '@statamic/cms/ui';

/**
 * The list of funnels.
 *
 * Core's `<Listing>` in its client-side mode: the rows arrive whole with the
 * page, and the component supplies the column headings, the sorting, the
 * checkbox column and the row menu. The hand-rolled stack of cards this
 * replaced had none of those — Adrian on 03.09.2026: "sollte auch eine
 * typische Statamic-Tabelle sein und nicht so."
 *
 * `:items` rather than `:url`: a site has a handful of funnels, not a
 * thousand, so there is nothing to page through and no second endpoint to
 * keep in step with this one. Sorting and searching then happen in the
 * browser, over the rows already on the page.
 */
const props = defineProps({
    funnels: { type: Array, default: () => [] },
    // Field names have to match the keys on a row: client-side sorting reads
    // `row[column.field]`.
    columns: { type: Array, required: true },
    // No action URL, no checkboxes and no bulk toolbar. It is also what the
    // row menu posts to when someone deletes a funnel.
    actionUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    // Built with `cp_route()` on the server. A hard-coded `/cp/...` breaks on
    // every site that has moved its Control Panel, which Statamic invites.
    indexUrl: { type: String, required: true },
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

/**
 * After an action ran, fetch the rows again.
 *
 * The listing owns its own copy of them, and in client-side mode its refresh
 * has nothing to fetch from — so a deleted funnel would sit in the table until
 * the next full page load. The Inertia reload is what actually replaces the
 * `funnels` prop the listing is watching.
 */
function reload() {
    router.reload({ only: ['funnels'] });
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
            :url="indexUrl"
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

        <!-- Search, presets and column customising are off: with six rows a
             search field is furniture, and presets and saved columns need
             server-side scopes and a preferences prefix this screen has no
             use for. What stays on is what the feedback asked for — headings,
             sorting, selection, the row menu. -->
        <Listing
            v-else
            :items="funnels"
            :columns="columns"
            :action-url="actionUrl"
            :allow-search="false"
            :allow-presets="false"
            :allow-customizing-columns="false"
            class="mt-4"
            @refreshing="reload"
        >
            <template #cell-title="{ row: funnel }">
                <Link :href="funnel.edit_url" class="font-semibold">{{ funnel.title }}</Link>
                <span class="block font-mono text-2xs text-gray-600 dark:text-gray-400">{{ funnel.handle }}</span>
            </template>

            <template #cell-published="{ row: funnel }">
                <Badge
                    :color="funnel.published ? 'green' : 'default'"
                    :text="funnel.published ? __('statamic-funnels::messages.live') : __('statamic-funnels::messages.draft')"
                    pill
                />
            </template>

            <template #cell-steps_count="{ value }">
                <span class="tabular-nums">{{ value }}</span>
            </template>

            <template #cell-visits_count="{ value }">
                <span class="tabular-nums">{{ value }}</span>
            </template>

            <!-- Deleting is not here: it is a server action, so it arrives in
                 the same menu underneath the separator, with the Control
                 Panel's own confirmation in front of it and the same wording
                 whether one row or twenty are selected. -->
            <template #prepended-row-actions="{ row: funnel }">
                <DropdownItem icon="edit" :text="__('Edit')" :href="funnel.edit_url" />
                <DropdownItem
                    icon="external-link"
                    :text="__('statamic-funnels::messages.open_public')"
                    :href="funnel.public_url"
                    target="_blank"
                />
            </template>
        </Listing>

        <Stack v-model:open="open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
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

                <div class="border-t border-content-border px-6 py-4">
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
