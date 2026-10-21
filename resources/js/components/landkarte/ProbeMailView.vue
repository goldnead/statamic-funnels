<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Alert } from '@statamic/cms/ui';
import { postJson } from '../../support/landkarteRequest.js';
import ProbeActions from './ProbeActions.vue';

/**
 * One mail of the test person, as she would read it: who it is from, the subject, what she can do with it, and the mail.
 *
 * The mail is looked at, not delivered. The server writes it for her and makes it safe (no script, no remote image, no live
 * link, every image switched off and left as a quiet area with its alternative text). Here it sits in an iframe whose
 * `sandbox` has no `allow-scripts`: nothing in it runs, and the policy inside the document lets it fetch nothing.
 * `allow-same-origin` is given for one reason only, to read the height of the finished document so the frame is as tall as
 * the mail and not a box with a scroll bar; without scripts a same-origin document can read nothing and do nothing.
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
const frame = ref(null);
const height = ref(240);

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

// --- the frame is as tall as the mail ------------------------------------------------------------------------------------

function measure() {
    const body = frame.value?.contentDocument?.documentElement;

    if (body) height.value = Math.max(120, Math.ceil(body.scrollHeight));
}

let observer = null;

function watchSize() {
    observer?.disconnect();

    const doc = frame.value?.contentDocument?.documentElement;

    measure();

    if (doc && typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure);
        observer.observe(doc);
    }
}

onBeforeUnmount(() => observer?.disconnect());
watch(mail, () => nextTick(measure));

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

            <!-- No script, no popups, no forms, no navigation. Same-origin only to read the height (see above). -->
            <iframe
                ref="frame"
                class="pv-mail"
                sandbox="allow-same-origin"
                referrerpolicy="no-referrer"
                scrolling="no"
                :srcdoc="mail.html"
                :style="{ height: `${height}px` }"
                :title="probe.t('probe_mail_frame')"
                data-pm-frame
                @load="watchSize"
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
.pv-mail { display: block; width: 100%; overflow: hidden; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; background: #fff; }
.dark .pv-mail { border-color: var(--color-gray-700); }
.pv-foot { margin: 0.5rem 0 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pv-foot { color: var(--color-gray-400); }
</style>
