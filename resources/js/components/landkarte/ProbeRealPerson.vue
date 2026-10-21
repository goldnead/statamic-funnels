<script setup>
import { inject, onBeforeUnmount, ref, watch } from 'vue';
import { Button, Input } from '@statamic/cms/ui';
import { postJson } from '../../support/landkarteRequest.js';

/**
 * "As for a real person": find her in the CRM and copy what the addons that know her say into the form of the test
 * person (tags, traits, access, the automations she has been through, whether she asked not to be written to).
 *
 * Read only. The search and the copy only read; the answer fills the form and is then planned like any other person.
 * Nothing about her is saved, and the form stays editable: the way is what the form says.
 */
const props = defineProps({
    url: { type: String, required: true },
    copiedFrom: { type: String, default: null },
    // Sources that did not speak when she was copied (no right, or a failure): said, so a missing part is not read as "none".
    missing: { type: Array, default: () => [] },
});

const emit = defineEmits(['copy', 'reset']);
const probe = inject('probelauf');

const query = ref('');
const results = ref([]);
const state = ref('idle');

// POST, so a name or an address typed here is in a body and not in a URL that every access log keeps.
async function get(params) {
    const response = await postJson(props.url, params);

    if (!response.ok) throw new Error(String(response.status));

    return response.data;
}

let pause = null;
let ticket = 0;

watch(query, (value) => {
    clearTimeout(pause);

    if (value.trim().length < 2) {
        ticket++;
        results.value = [];
        state.value = 'idle';

        return;
    }

    pause = setTimeout(async () => {
        const mine = ++ticket;
        state.value = 'loading';

        try {
            const data = await get({ q: value.trim() });
            if (mine !== ticket) return;
            results.value = data.persons ?? [];
            state.value = results.value.length ? 'ready' : 'none';
        } catch {
            if (mine === ticket) state.value = 'failed';
        }
    }, 350);
});

onBeforeUnmount(() => clearTimeout(pause));

async function pick(person) {
    ticket++;

    try {
        const data = await get({ like: person.key });
        emit('copy', { label: person.label, ...data });
        results.value = [];
        query.value = '';
        state.value = 'idle';
    } catch {
        state.value = 'failed';
    }
}
</script>

<template>
    <div class="rp" data-pr-real>
        <div v-if="copiedFrom" class="rp-copied" data-pr-copied>
            <span>{{ probe.t('probe_who_copied').replace(':name', () => copiedFrom) }}</span>
            <Button size="xs" variant="ghost" :text="probe.t('probe_who_reset')" data-pr-reset @click="emit('reset')" />
        </div>
        <label v-else class="rp-search">
            <span class="pr-start__label">{{ probe.t('probe_who_search') }}</span>
            <Input v-model="query" size="sm" class="w-64" :aria-label="probe.t('probe_who_search')" autocomplete="off" data-pr-real-search />
        </label>

        <ul v-if="state === 'ready'" class="rp-results" data-pr-real-results>
            <li v-for="person in results" :key="person.key">
                <button type="button" class="rp-pick" :data-pr-pick="person.key" @click="pick(person)">{{ person.label }}</button>
            </li>
        </ul>
        <p v-else-if="state === 'none'" class="rp-note" data-pr-real-none>{{ probe.t('probe_who_none') }}</p>
        <p v-else-if="state === 'failed'" class="rp-note" data-pr-real-failed>{{ probe.t('probe_failed') }}</p>
        <p v-if="copiedFrom && missing.length" class="rp-note rp-note--warn" data-pr-missing>{{ probe.t('probe_who_missing').replace(':sources', () => missing.join(', ')) }}</p>
        <p class="rp-note">{{ probe.t('probe_who_read_only') }}</p>
    </div>
</template>

<style>
.rp { display: flex; flex-direction: column; gap: 0.35rem; margin: -0.25rem 0 0.9rem; }
.rp-search { display: inline-flex; align-items: center; gap: 0.5rem; }
.rp-copied { display: inline-flex; align-items: center; gap: 0.6rem; font-size: 0.85rem; font-weight: 500; color: var(--color-gray-900); }
.dark .rp-copied { color: var(--color-gray-100); }
.rp-results { margin: 0; padding: 0; list-style: none; max-width: 28rem; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; overflow: hidden; }
.dark .rp-results { border-color: var(--color-gray-700); }
.rp-results > li + li { border-top: 1px solid var(--color-gray-200); }
.dark .rp-results > li + li { border-top-color: var(--color-gray-700); }
.rp-pick { display: block; width: 100%; padding: 0.4rem 0.7rem; background: transparent; border: 0; text-align: left; font-size: 0.85rem; color: var(--color-gray-900); cursor: pointer; }
.dark .rp-pick { color: var(--color-gray-100); }
.rp-pick:hover { background: var(--color-gray-50); }
.dark .rp-pick:hover { background: var(--color-gray-800); }
.rp-pick:focus-visible { outline: 2px solid var(--color-primary); outline-offset: -2px; }
.rp-note--warn { color: var(--color-gray-900) !important; font-weight: 500; }
.dark .rp-note--warn { color: var(--color-gray-100) !important; }
.rp-note { margin: 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .rp-note { color: var(--color-gray-400); }
</style>
