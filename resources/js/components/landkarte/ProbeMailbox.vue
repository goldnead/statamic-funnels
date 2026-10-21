<script setup>
import { computed, inject, ref, watch } from 'vue';
import { Alert, Icon } from '@statamic/cms/ui';
import { linkTarget } from '../../support/probelauf.js';

/**
 * The mailbox of the test person: every mail that goes out on her way, in order, and the one she opens.
 *
 * The mail is looked at, not delivered. The server writes it for her and makes it safe (no script, no
 * remote image, no live link); here it is shown in an iframe with an empty `sandbox`, so it can run
 * nothing, reach nothing and navigate nowhere. Its links are listed as data and lead nowhere.
 */
const props = defineProps({
    entries: { type: Array, default: () => [] },
    mailUrl: { type: String, required: true },
    start: { type: String, default: null },
});

const probe = inject('probelauf');

const mails = computed(() => props.entries.filter((e) => e.kind === 'mail'));
const selected = ref(null);
const state = ref('idle');
const mail = ref(null);

let ticket = 0;

async function open(entry) {
    selected.value = entry.id;
    const mine = ++ticket;
    state.value = 'loading';
    mail.value = null;

    try {
        const url = new URL(props.mailUrl, window.location.origin);
        url.searchParams.set('start', props.start ?? '');
        url.searchParams.set('entry', entry.id);

        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (response.status === 404) {
            // The owner of this mail cannot write it for the dry run: that is no failure, it is a limit.
            if (mine === ticket) state.value = 'none';

            return;
        }

        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();
        if (mine !== ticket) return;

        mail.value = data;
        state.value = 'ready';
    } catch {
        if (mine === ticket) state.value = 'failed';
    }
}

// A new way (another start) is another mailbox: nothing stays open that is not in it.
watch(() => props.entries, () => {
    ticket++;
    selected.value = null;
    mail.value = null;
    state.value = 'idle';
});

// Up and down move through the mails like a mailbox does; the focus follows, the mail opens on Enter or click.
function onKey(event) {
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;

    const items = [...event.currentTarget.querySelectorAll('.pm-item')];
    const at = items.indexOf(document.activeElement);
    if (at === -1) return;

    event.preventDefault();
    items[Math.min(items.length - 1, Math.max(0, at + (event.key === 'ArrowDown' ? 1 : -1)))].focus();
}

const imagesNote = computed(() => {
    const n = mail.value?.blocked_images ?? 0;

    return n === 0 ? null : probe.t(n === 1 ? 'probe_mail_images_one' : 'probe_mail_images_many').replace(':count', n);
});
</script>

<template>
    <div class="pm" data-pr-mailbox>
        <p v-if="!mails.length" class="pr-status" data-pr-empty>{{ probe.t('probe_mailbox_empty') }}</p>

        <template v-else>
            <ul class="pm-list" data-pm-list @keydown="onKey">
                <li v-for="entry in mails" :key="entry.id">
                    <button
                        type="button"
                        class="pm-item"
                        :class="selected === entry.id && 'pm-item--on'"
                        :aria-current="selected === entry.id ? 'true' : undefined"
                        :data-pm-entry="entry.id"
                        @click="open(entry)"
                    >
                        <Icon name="mail" class="size-4 shrink-0" />
                        <span class="pm-item__text">
                            <span class="pm-item__subject">{{ entry.label }}</span>
                            <span class="pm-item__meta">{{ probe.t('probe_day').replace(':day', entry.day) }}{{ probe.timeInDay(entry.at) ? ', ' + probe.timeInDay(entry.at) : '' }}</span>
                        </span>
                    </button>
                </li>
            </ul>

            <div class="pm-reader" data-pm-reader>
                <p v-if="state === 'idle'" class="pr-status" data-pm-hint>{{ probe.t('probe_mail_select') }}</p>
                <p v-else-if="state === 'loading'" class="pr-status" role="status">{{ probe.t('probe_mail_loading') }}</p>
                <p v-else-if="state === 'none'" class="pr-status" data-pm-none>{{ probe.t('probe_mail_none') }}</p>
                <Alert v-else-if="state === 'failed'" variant="warning" data-pm-failed>{{ probe.t('probe_mail_failed') }}</Alert>

                <template v-else-if="mail">
                    <dl class="pm-head">
                        <dt>{{ probe.t('probe_mail_subject') }}</dt>
                        <dd class="pm-head__subject" data-pm-subject>{{ mail.subject }}</dd>
                        <dt>{{ probe.t('probe_mail_from') }}</dt>
                        <dd :class="!mail.from && 'pm-head__none'" data-pm-from>{{ mail.from || probe.t('probe_mail_from_none') }}</dd>
                    </dl>

                    <p v-if="imagesNote" class="pm-note pm-note--top" data-pm-images>{{ imagesNote }}</p>

                    <!-- Empty sandbox: no script, no same-origin, no forms, no popups, no navigation. -->
                    <iframe
                        class="pm-frame"
                        sandbox=""
                        referrerpolicy="no-referrer"
                        :srcdoc="mail.html"
                        :title="probe.t('probe_mail_frame')"
                        data-pm-frame
                    />

                    <div v-if="mail.links.length" class="pm-links" data-pm-links>
                        <h4>{{ probe.t('probe_mail_links') }}</h4>
                        <p class="pm-note">{{ probe.t('probe_mail_links_help') }}</p>
                        <ul>
                            <li v-for="(link, i) in mail.links" :key="i">
                                <span class="pm-link__text">{{ link.text }}</span>
                                <span class="pm-link__url">{{ linkTarget(link.url).goal }}</span>
                                <span v-if="linkTarget(link.url).wrapped" class="pm-link__wrapped">{{ linkTarget(link.url).wrapped }}</span>
                            </li>
                        </ul>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>

<style>
.pm { display: grid; grid-template-columns: minmax(14rem, 20rem) 1fr; gap: 1.25rem; align-items: start; }
@media (max-width: 900px) { .pm { grid-template-columns: 1fr; } }
.pm-list { margin: 0; padding: 0; list-style: none; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; overflow: hidden; }
.dark .pm-list { border-color: var(--color-gray-700); }
.pm-list > li + li { border-top: 1px solid var(--color-gray-200); }
.dark .pm-list > li + li { border-top-color: var(--color-gray-700); }
.pm-item { display: flex; gap: 0.6rem; align-items: flex-start; width: 100%; padding: 0.6rem 0.75rem; background: transparent; border: 0; text-align: left; cursor: pointer; color: var(--color-gray-500); }
.pm-item:hover { background: var(--color-gray-50); }
.dark .pm-item:hover { background: var(--color-gray-800); }
.pm-item:focus-visible { outline: 2px solid var(--color-primary); outline-offset: -2px; }
.pm-item--on { background: var(--color-gray-100); }
.dark .pm-item--on { background: var(--color-gray-800); }
.pm-item__text { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
.pm-item__subject { font-size: 0.875rem; font-weight: 500; color: var(--color-gray-900); overflow-wrap: anywhere; }
.dark .pm-item__subject { color: var(--color-gray-100); }
.pm-item__meta { font-size: 0.75rem; color: var(--color-gray-600); }
.dark .pm-item__meta { color: var(--color-gray-400); }
.pm-reader { min-width: 0; }
.pm-head { display: grid; grid-template-columns: auto 1fr; gap: 0.15rem 0.9rem; margin: 0 0 0.75rem; font-size: 0.8rem; }
.pm-head dt { color: var(--color-gray-600); }
.dark .pm-head dt { color: var(--color-gray-400); }
.pm-head dd { margin: 0; color: var(--color-gray-900); overflow-wrap: anywhere; }
.dark .pm-head dd { color: var(--color-gray-100); }
.pm-head__subject { font-weight: 600; }
.pm-frame { display: block; width: 100%; height: 22rem; min-height: 10rem; resize: vertical; overflow: auto; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; background: #fff; }
.dark .pm-frame { border-color: var(--color-gray-700); }
.pm-note--top { margin: 0 0 0.5rem; }
.pm-note { margin: 0.5rem 0 0; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pm-note { color: var(--color-gray-400); }
.pm-links { margin-top: 1rem; }
.pm-links h4 { margin: 0; font-size: 0.8rem; font-weight: 600; }
.pm-links ul { margin: 0.4rem 0 0; padding: 0; list-style: none; }
.pm-links li { display: flex; flex-wrap: wrap; gap: 0.1rem 0.75rem; padding: 0.2rem 0; font-size: 0.8rem; }
.pm-link__url { color: var(--color-gray-900); overflow-wrap: anywhere; }
.dark .pm-link__url { color: var(--color-gray-100); }
.pm-link__wrapped { flex-basis: 100%; font-size: 0.7rem; color: var(--color-gray-500); overflow-wrap: anywhere; }
.dark .pm-link__wrapped { color: var(--color-gray-500); }
.pm-head__none { color: var(--color-gray-600) !important; font-style: italic; }
.dark .pm-head__none { color: var(--color-gray-400) !important; }
.pm-links h4 { color: var(--color-gray-900); }
.dark .pm-links h4 { color: var(--color-gray-100); }
</style>
