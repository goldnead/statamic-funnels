<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Alert, Button, Icon, ToggleGroup, ToggleItem } from '@statamic/cms/ui';
import { postJson } from '../../support/landkarteRequest.js';

/**
 * One page of the test person's way, as she would see it.
 *
 * The page is rendered on the server (from the Statamic entry the way names, on a request that is only a look: no visit, no
 * step event, no split test, no tracking code), made safe, and shown here in an iframe with an EMPTY `sandbox`: no script, no
 * same-origin, no forms, no navigation. Its links lead nowhere; they are listed with their target, and "Klickt …" only shows
 * the target. A page that cannot be rendered this way says why and shows what there is instead (its address, the picture
 * of the funnel step that shows it).
 */
const props = defineProps({
    entry: { type: Object, required: true },
    pageUrl: { type: String, required: true },
    start: { type: String, default: null },
    personSig: { type: String, default: '' },
    personBody: { type: Function, default: () => ({}) },
});

const probe = inject('probelauf');

// The stage is a window onto the page, and the page scrolls inside it. A phone page is laid out at 390 and shown a fifth smaller, so
// that its first screen (head, headline, the button) fits; a desktop page is laid out at 1280 and scaled to the width of the stage.
const STAGE = { phone: 380, desktop: 460 };
const WIDTHS = { desktop: 1280, phone: 390 };
const PHONE_SCALE = 0.8;

const state = ref('loading');
const page = ref(null);
const device = ref('phone');
const clicked = ref(null);
const stage = ref(null);
const stageWidth = ref(0);

let ticket = 0;

// Which entry the page on screen belongs to: another page does not stay on screen while its own is read.
let shownFor = null;

async function load() {
    const mine = ++ticket;
    state.value = 'loading';
    clicked.value = null;

    if (shownFor !== props.entry.id) page.value = null;

    try {
        const response = await postJson(props.pageUrl, {
            ...props.personBody(), start: props.start ?? '', entry: props.entry.id, label: props.entry.label,
        });

        if (!response.ok) throw new Error(String(response.status));

        if (mine !== ticket) return;

        page.value = response.data;
        shownFor = props.entry.id;
        state.value = response.data.state === 'rendered' ? 'ready' : 'unavailable';
    } catch {
        if (mine === ticket) {
            page.value = null;
            state.value = 'failed';
        }
    }
}

watch(() => `${props.entry.id}|${props.entry.label}|${props.start}|${props.personSig}`, load, { immediate: true });

// --- the window onto the page --------------------------------------------------------------------------------------------

let observer = null;

onMounted(() => {
    if (typeof ResizeObserver === 'undefined') return;

    observer = new ResizeObserver((entries) => { stageWidth.value = entries[0]?.contentRect.width ?? 0; });
    watch(stage, (el, old) => {
        if (old) observer.unobserve(old);
        if (el) observer.observe(el);
    }, { immediate: true });
});
onBeforeUnmount(() => observer?.disconnect());

// A desktop page is laid out at 1280 and scaled to the stage, so the layout is the real one and only smaller.
const scale = computed(() => {
    if (device.value === 'phone') return PHONE_SCALE;

    return stageWidth.value > 0 ? Math.min(1, stageWidth.value / WIDTHS.desktop) : 1;
});
const frameStyle = computed(() => ({
    width: `${WIDTHS[device.value]}px`,
    height: `${Math.ceil(STAGE[device.value] / scale.value)}px`,
    transform: scale.value === 1 ? undefined : `scale(${scale.value})`,
}));
const deviceStyle = computed(() => ({
    height: `${STAGE[device.value]}px`,
    width: device.value === 'desktop' ? '100%' : `${Math.round(WIDTHS.phone * PHONE_SCALE)}px`,
}));

const address = computed(() => page.value?.address ?? '');
const deviceOptions = computed(() => [
    { value: 'desktop', label: probe.t('probe_page_desktop'), icon: 'computer-desktop' },
    { value: 'phone', label: probe.t('probe_page_phone'), icon: 'phone-telephone-call' },
]);
const reason = computed(() => probe.t(`probe_page_unavailable_${page.value?.reason}`, probe.t('probe_page_unavailable_failed')));
const goal = (url) => url.replace(/^https?:\/\//i, '');
const hostOf = (url) => goal(url).split('/')[0];
const isOwn = (url) => url.startsWith('/');

// The page's own links come first; an embedded service's (a privacy notice of a video host) after them. Two buttons with the same
// words are told apart by where they lead.
const links = computed(() => {
    const all = [...(page.value?.links ?? [])].sort((a, b) => Number(isOwn(b.url)) - Number(isOwn(a.url)));
    const same = (text) => all.filter((l) => l.text === text).length > 1;

    return all.map((l) => ({ ...l, button: probe.t('probe_do_clicks').replace(':link', () => (same(l.text) && !isOwn(l.url) ? `${l.text} (${hostOf(l.url)})` : l.text)) }));
});
</script>

<template>
    <div class="pg" data-pv-page>
        <div class="pg-bar">
            <div class="pg-url" data-pg-url>
                <Icon name="padlock-locked" class="size-3.5 shrink-0" />
                <span>{{ address }}</span>
            </div>
            <span class="pg-size">{{ probe.t('probe_page_width').replace(':px', WIDTHS[device]) }}</span>
            <ToggleGroup v-model="device" size="sm" :aria-label="probe.t('probe_page_device')" data-pg-device>
                <ToggleItem v-for="option in deviceOptions" :key="option.value" :value="option.value" :icon="option.icon" :label="option.label" :data-pg-device-option="option.value" />
            </ToggleGroup>
        </div>

        <p v-if="state === 'loading' && !page" class="pr-status" role="status" data-pg-loading>{{ probe.t('probe_page_loading') }}</p>
        <Alert v-else-if="state === 'failed'" variant="warning" data-pg-failed>{{ probe.t('probe_page_failed') }}</Alert>

        <template v-else-if="state === 'unavailable'">
            <p class="pg-reason" data-pg-unavailable>{{ reason }}</p>
            <figure v-if="page.thumbnail" class="pg-thumb">
                <img :src="page.thumbnail" :alt="probe.t('probe_page_thumb_alt')" />
                <figcaption>{{ probe.t('probe_page_thumb_note') }}</figcaption>
            </figure>
        </template>

        <template v-else-if="page">
            <div ref="stage" class="pg-stage" :class="`pg-stage--${device}`">
                <div class="pg-device" :class="`pg-device--${device}`" :style="deviceStyle">
                    <!-- Empty sandbox: no script, no same-origin, no forms, no popups, no navigation. -->
                    <iframe
                        class="pg-frame"
                        sandbox=""
                        referrerpolicy="no-referrer"
                        :srcdoc="page.html"
                        :style="frameStyle"
                        :title="probe.t('probe_page_frame')"
                        data-pg-frame
                    />
                </div>
                <span class="pg-fade" aria-hidden="true" />
            </div>

            <section v-if="links.length" class="pg-links" data-pg-links>
                <h4>{{ probe.t('probe_page_links') }}</h4>
                <ul>
                    <li v-for="(link, i) in links" :key="i" class="pg-link">
                        <div class="pg-link__text">
                            <span>{{ link.text }}</span>
                            <span class="pg-link__url">{{ goal(link.url) }}</span>
                            <span v-if="clicked === i" class="pg-link__goal" data-pg-goal>{{ probe.t('probe_page_link_goal').replace(':target', () => goal(link.url)) }}</span>
                        </div>
                        <Button size="sm" :class="clicked === i && 'pa-on'" :aria-pressed="clicked === i" :text="link.button" data-pg-click @click="clicked = clicked === i ? null : i" />
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>

<style>
.pg { min-width: 0; }
.pg-bar { display: flex; align-items: center; gap: 0.75rem; margin: 0 0 0.6rem; }
.pg-url {
    display: flex; align-items: center; gap: 0.45rem; flex: 1; min-width: 0; padding: 0.3rem 0.65rem; border: 1px solid var(--color-gray-200);
    border-radius: 0.375rem; background: var(--color-gray-50); font-size: 0.875rem; color: var(--color-gray-800);
}
.pg-url span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dark .pg-url { border-color: var(--color-gray-700); background: var(--color-gray-800); color: var(--color-gray-200); }
.pg-size { font-size: 0.8rem; color: var(--color-gray-600); white-space: nowrap; }
.dark .pg-size { color: var(--color-gray-400); }
.pg-reason { margin: 0 0 0.75rem; font-size: 0.875rem; color: var(--color-gray-700); max-width: 60ch; }
.dark .pg-reason { color: var(--color-gray-300); }
.pg-thumb { margin: 0; }
.pg-thumb img { display: block; width: 100%; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; }
.dark .pg-thumb img { border-color: var(--color-gray-700); }
.pg-thumb figcaption { margin-top: 0.4rem; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pg-thumb figcaption { color: var(--color-gray-400); }

.pg-stage { position: relative; display: flex; justify-content: center; padding: 1rem; overflow: hidden; border: 1px solid var(--color-gray-200); border-radius: 0.5rem; background: var(--color-gray-100); }
.dark .pg-stage { border-color: var(--color-gray-700); background: var(--color-gray-950, var(--color-gray-900)); }
.pg-stage--desktop { padding: 0; }
.pg-device { position: relative; overflow: hidden; background: #fff; }
.pg-device--phone { border: 1px solid var(--color-gray-300); border-radius: 0.75rem; box-shadow: 0 1px 2px rgb(0 0 0 / 0.06); }
.dark .pg-device--phone { border-color: var(--color-gray-600); }
.pg-frame { display: block; border: 0; background: #fff; transform-origin: top left; }
.pg-fade { position: absolute; left: 0; right: 0; bottom: 0; height: 3rem; pointer-events: none; background: linear-gradient(to bottom, transparent, var(--color-gray-100)); }
.dark .pg-fade { background: linear-gradient(to bottom, transparent, var(--color-gray-950, var(--color-gray-900))); }

.pg-links { margin-top: 1rem; }
.pg-links h4 { margin: 0 0 0.25rem; font-size: 0.8rem; font-weight: 600; color: var(--color-gray-900); }
.dark .pg-links h4 { color: var(--color-gray-100); }
.pg-links ul { margin: 0; padding: 0; list-style: none; }
.pg-link { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.5rem 0; font-size: 0.875rem; color: var(--color-gray-900); }
.pg-link + .pg-link { border-top: 1px solid var(--color-gray-200); }
.dark .pg-link { color: var(--color-gray-100); }
.dark .pg-link + .pg-link { border-top-color: var(--color-gray-700); }
.pg-link__text { display: flex; flex-direction: column; min-width: 0; }
.pg-link__url { font-size: 0.78rem; color: var(--color-gray-600); overflow-wrap: anywhere; }
.dark .pg-link__url { color: var(--color-gray-400); }
.pg-link__goal { margin-top: 0.2rem; font-size: 0.78rem; color: var(--color-gray-800); }
.dark .pg-link__goal { color: var(--color-gray-200); }
</style>
