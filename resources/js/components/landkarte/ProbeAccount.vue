<script setup>
import { computed, inject } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { accountOf } from '../../support/probelauf.js';

/**
 * The account of the test person: what she has been given and when, and for each course she got into, which
 * lesson opens on which day. Read off her way; nothing is enrolled, opened or saved.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
});

const probe = inject('probelauf');
const account = computed(() => accountOf(props.entries));
const dayWords = (day) => (day === null ? probe.t('probe_account_open_unknown') : probe.t('probe_day').replace(':day', day));
// The day on the right says when a lesson opens; the words add something only when it is a date or not known.
const showDetail = (lesson) => lesson.day === null || /\d{1,2}\.\d{1,2}\.\d{2,4}/.test(lesson.detail ?? '');
const empty = computed(() => account.value.access.length === 0 && account.value.courses.length === 0);
</script>

<template>
    <div class="pa-account" data-pr-account>
        <p v-if="empty" class="pr-status" data-pr-empty>{{ probe.t('probe_account_empty') }}</p>

        <section v-if="account.access.length" class="pac-block" data-pac-access>
            <h3 class="pac-head">{{ probe.t('probe_account_access') }}</h3>
            <ul class="pac-list">
                <li v-for="a in account.access" :key="a.slug" class="pac-row">
                    <Icon name="key" class="size-4 pac-icon" />
                    <span class="pac-label">{{ a.slug }}</span>
                    <span class="pac-meta">{{ dayWords(a.day) }}</span>
                </li>
            </ul>
        </section>

        <section v-for="course in account.courses" :key="course.id" class="pac-block" :data-pac-course="course.id">
            <h3 class="pac-head">{{ course.title.replace(/^[^:]*:\s*/, '') }}</h3>
            <ul class="pac-list">
                <li v-for="lesson in course.lessons" :key="lesson.slug" class="pac-row" :class="lesson.day === null && 'pac-row--quiet'">
                    <Icon name="page" class="size-4 pac-icon" />
                    <span class="pac-label">{{ lesson.title }}</span>
                    <span v-if="showDetail(lesson)" class="pac-meta">{{ lesson.detail }}</span>
                    <span class="pac-day">{{ dayWords(lesson.day) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>

<style>
.pac-block { margin: 0 0 1.25rem; }
.pac-head { margin: 0 0 0.35rem; padding-bottom: 0.3rem; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--color-gray-600); border-bottom: 1px solid var(--color-gray-200); }
.dark .pac-head { color: var(--color-gray-400); border-bottom-color: var(--color-gray-700); }
.pac-list { margin: 0; padding: 0; list-style: none; }
.pac-row { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.1rem 0.6rem; padding: 0.25rem 0; font-size: 0.875rem; color: var(--color-gray-900); }
.dark .pac-row { color: var(--color-gray-100); }
.pac-row--quiet { color: var(--color-gray-600); }
.dark .pac-row--quiet { color: var(--color-gray-400); }
.pac-icon { flex: none; align-self: center; color: var(--color-gray-500); }
.pac-label { font-weight: 500; }
.pac-meta { font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pac-meta { color: var(--color-gray-400); }
.pac-day { margin-left: auto; font-size: 0.75rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pac-day { color: var(--color-gray-400); }
</style>
