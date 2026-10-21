<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Alert } from '@statamic/cms/ui';
import { postJson } from '../../support/landkarteRequest.js';
import ProbeActions from './ProbeActions.vue';

/**
 * One mail of the test person, as she would read it: who it is from, the subject, what she can do with it, and the mail.
 *
 * The mail is looked at, not delivered. The server writes it for her and makes it safe (no script, no remote image, no live
 * link, every image switched off and left as a quiet area with its alternative text). Here it sits in an iframe whose
 * `sandbox` is EMPTY: no script, no same-origin, no forms, no popups, no navigation; and the policy inside the document lets it
 * fetch nothing. Because the frame has no origin it cannot be asked how tall the mail is, so it is a window of a fixed, good
 * height and the mail scrolls inside it.
 */
const props = defineProps({
    entry: { type: Object, required: true },
    mailUrl: { type: String, required: true },
    start: { type: String, default: null },
    // What the person is, as one string: another person is another mail, an action after it is not.
    personSig: { type: String, default: '' },
    // The person as the page describes her: the query that plans her way is also the one that writes her mail.
    personBody: { type: Function, default: () => ({}) },
    maxActions: { type: Number, default: 12 },
    products: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
});

const emit = defineEmits(['toggle', 'clear', 'delay', 'none']);
const probe = inject('probelauf');

const state = ref('loading');
const mail = ref(null);

let ticket = 0;

async function load() {
    const mine = ++ticket;
    // What is on screen stays while the next one is read: the mail does not blink at every small change.
    state.value = 'loading';

    try {
        const response = await postJson(props.mailUrl, {
            ...props.personBody(), start: props.start ?? '', entry: props.entry.id, label: props.entry.label,
        });

        // The owner of this mail cannot write it for the dry run: that is no failure, it is a limit.
        if (response.status === 404) {
            if (mine === ticket) {
                mail.value = null;
                state.value = 'none';
                emit('none', props.entry);
            }

            return;
        }

        if (!response.ok) throw new Error(String(response.status));

        if (mine !== ticket) return;

        mail.value = response.data;
        state.value = 'ready';
    } catch {
        if (mine === ticket) {
            mail.value = null;
            state.value = 'failed';
        }
    }
}

// The same mail again (the person changed a little, an action was added) is read again only when it is another moment.
watch(() => `${props.entry.id}|${props.entry.at}|${props.entry.label}|${props.start}|${props.personSig}`, load, { immediate: true });

const imagesNote = computed(() => {
    const n = mail.value?.blocked_images ?? 0;

    return n === 0 ? null : probe.t(n === 1 ? 'probe_mail_images_one' : 'probe_mail_images_many').replace(':count', n);
});

const mailKey = computed(() => props.entry.meta?.mail_key ?? null);
</script>

<template>
    <div class="pv" data-pv-mail>
        <p v-if="state === 'loading' && !mail" class="pr-status" role="status">{{ probe.t('probe_mail_loading') }}</p>
        <p v-else-if="state === 'none'" class="pr-status" data-pm-none>{{ probe.t('probe_mail_none') }}</p>
        <Alert v-else-if="state === 'failed'" variant="warning" data-pm-failed>{{ probe.t('probe_mail_failed') }}</Alert>

        <template v-else-if="mail">
            <dl class="pv-head">
                <dt>{{ probe.t('probe_mail_from') }}</dt>
                <dd :class="!mail.from && 'pv-head__none'" data-pm-from>{{ mail.from || probe.t('probe_mail_from_none') }}</dd>
                <dt>{{ probe.t('probe_mail_subject') }}</dt>
                <dd class="pv-head__subject" data-pm-subject>{{ mail.subject }}</dd>
            </dl>

            <ProbeActions
                v-if="mailKey"
                :mail-key="mailKey"
                :links="mail.links"
                :products="products"
                :actions="actions"
                :max-actions="maxActions"
                @toggle="emit('toggle', $event)"
                @clear="emit('clear', mailKey)"
                @delay="emit('delay', mailKey, $event)"
            />

            <!-- Empty sandbox: no script, no same-origin, no forms, no popups, no navigation. A fixed window; the mail scrolls in it. -->
            <iframe
                class="pv-mail"
                sandbox=""
                referrerpolicy="no-referrer"
                :srcdoc="mail.html"
                :title="probe.t('probe_mail_frame')"
                data-pm-frame
            />

            <p v-if="imagesNote" class="pv-foot" data-pm-images>{{ imagesNote }}</p>
        </template>
    </div>
</template>

<style>
.pv { min-width: 0; }
.pv-head { display: grid; grid-template-columns: auto 1fr; gap: 0.15rem 1rem; margin: 0 0 0.75rem; font-size: 0.875rem; }
.pv-head dt { color: var(--color-gray-600); }
.dark .pv-head dt { color: var(--color-gray-400); }
.pv-head dd { margin: 0; color: var(--color-gray-900); overflow-wrap: anywhere; }
.dark .pv-head dd { color: var(--color-gray-100); }
.pv-head__subject { font-weight: 600; }
.pv-head__none { color: var(--color-gray-600) !important; font-style: italic; }
.dark .pv-head__none { color: var(--color-gray-400) !important; }
.pv-mail { display: block; width: 100%; height: 40rem; max-height: 70vh; min-height: 18rem; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; background: #fff; }
.dark .pv-mail { border-color: var(--color-gray-700); }
.pv-foot { margin: 0.5rem 0 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pv-foot { color: var(--color-gray-400); }
</style>
