<script setup>
import { computed, inject } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { pagesOf } from '../../support/probelauf.js';

/**
 * The pages on the test person's way: which page, at which address, on which day.
 *
 * Listed, not rendered. Showing the page itself would mean a request to the site (counted by whatever counts
 * visits there) or a preview pass written to the token store: both are something the dry run does not do.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
});

const probe = inject('probelauf');
const pages = computed(() => pagesOf(props.entries));
</script>

<template>
    <div class="pg" data-pr-pages>
        <p v-if="!pages.length" class="pr-status" data-pr-empty>{{ probe.t('probe_pages_empty') }}</p>
        <template v-else>
            <p class="pg-note">{{ probe.t('probe_pages_note') }}</p>
            <ul class="pac-list">
                <li v-for="page in pages" :key="page.id" class="pac-row" data-pg-row>
                    <Icon name="page" class="size-4 pac-icon" />
                    <span class="pac-label">{{ page.label }}</span>
                    <span v-if="page.detail" class="pac-meta">{{ page.detail }}</span>
                    <span class="pac-day">{{ probe.t('probe_day').replace(':day', page.day) }}</span>
                </li>
            </ul>
        </template>
    </div>
</template>

<style>
.pg-note { margin: 0 0 0.75rem; max-width: 70ch; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pg-note { color: var(--color-gray-400); }
</style>
