<script setup>
import { ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Button, Card, Heading, Input, Stack, Field, EmptyStateMenu, EmptyStateItem, Icon,
} from '@statamic/cms/ui';

/**
 * The Landkarten: strategic overviews, one per business or offer, each a map of
 * phases and the objects that live in them. The page lists them; a card opens
 * one.
 */
const props = defineProps({
    landkarten: { type: Array, default: () => [] },
    storeUrl: { type: String, required: true },
    labels: { type: Object, default: () => ({}) },
});

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

const open = ref(false);
const name = ref('');
const busy = ref(false);

function create() {
    if (!name.value.trim() || busy.value) return;
    busy.value = true;
    router.post(props.storeUrl, { name: name.value.trim() }, { onFinish: () => { busy.value = false; } });
}

function destroy(row) {
    if (window.confirm(t('landkarte_delete_confirm', 'Delete this map? The objects stay where they are.'))) {
        router.delete(row.destroy_url, { preserveScroll: true });
    }
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper data-landkarten>
        <Head :title="[t('landkarten_title', 'Maps')]" />

        <Header :title="t('landkarten_title', 'Maps')" icon="travel-map">
            <Button variant="primary" :text="t('landkarte_new', 'New map')" icon="plus" @click="open = true" />
        </Header>

        <p class="mb-6 max-w-prose text-sm text-gray-900 dark:text-gray-100">{{ t('landkarten_description') }}</p>

        <EmptyStateMenu v-if="!landkarten.length" :heading="t('landkarten_empty', 'No maps yet')">
            <EmptyStateItem :heading="t('landkarte_new', 'New map')" :description="t('landkarten_empty_help')" icon="travel-map" @click="open = true" />
        </EmptyStateMenu>

        <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="row in landkarten" :key="row.id">
                <Card class="flex h-full flex-col justify-between gap-4" data-landkarte-card>
                    <div class="flex items-start gap-3">
                        <span class="sa-icon-chip"><Icon name="travel-map" class="size-4" /></span>
                        <div class="min-w-0">
                            <a :href="row.url" class="block truncate font-semibold text-gray-900 hover:underline dark:text-gray-100">{{ row.name }}</a>
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ row.stages_count }} {{ t('landkarte_lanes_count', 'phases') }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button size="sm" variant="ghost" icon="trash" :aria-label="t('landkarte_delete', 'Delete')" @click="destroy(row)" />
                        <Button size="sm" :href="row.url" :text="t('landkarte_open', 'Open')" />
                    </div>
                </Card>
            </li>
        </ul>

        <Stack v-model:open="open" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="t('landkarte_new', 'New map')" size="lg" />
                </div>
                <div class="flex-1 px-6 py-5">
                    <Field :label="t('landkarte_name', 'Name')" required>
                        <Input v-model="name" :placeholder="t('landkarte_name_placeholder')" maxlength="191" @keydown.enter="create" />
                    </Field>
                </div>
                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t('map_cancel', 'Cancel')" @click="open = false" />
                        <Button variant="primary" :text="t('landkarte_create', 'Create')" :disabled="!name.trim() || busy" @click="create" />
                    </div>
                </div>
            </div>
        </Stack>
    </div>
</template>
