<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button, Select, Icon } from '@statamic/cms/ui';

/**
 * Walking the funnel inside the editor.
 *
 * Statamic's own `LivePreview` component was the obvious candidate and does not
 * fit: it has to live inside a `PublishContainer` and previews exactly one
 * target. A funnel is a path, and what somebody needs to see is the path — one
 * step, then the next, in the order a visitor meets them.
 *
 * So this borrows the parts that should be shared and builds only what is
 * genuinely different: the pass is a real `Statamic\Facades\Token`, the device
 * sizes are the ones from `config/live_preview.php`, and the iframe is a plain
 * iframe pointed at the real front-end URL. What is new is the stepper.
 *
 * The graph it previews is the one on screen, not the one in the table. Editing
 * a headline and seeing the old one is worse than no preview at all.
 */
const props = defineProps({
    previewUrl: { type: String, required: true },
    nodes: { type: Array, required: true },
    edges: { type: Array, required: true },
    devices: { type: Array, default: () => [] },
    kinds: { type: Object, default: () => ({}) },
    labels: { type: Object, default: () => ({}) },
    selectedKey: { type: String, default: null },
});

const emit = defineEmits(['close', 'select']);

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

const order = ref([]);
const current = ref(props.selectedKey);
const src = ref(null);
const token = ref(null);
const loading = ref(false);
const failed = ref(false);
const device = ref('responsive');

const nodeByKey = computed(() => Object.fromEntries(props.nodes.map((n) => [n.node_key, n])));

/** The stepper's stops, in walking order, skipping anything switched off. */
const stops = computed(() =>
    order.value
        .map((key) => nodeByKey.value[key])
        .filter((node) => node && !node.disabled),
);

const index = computed(() => stops.value.findIndex((n) => n.node_key === current.value));

const deviceOptions = computed(() => [
    { value: 'responsive', label: t('responsive', 'Responsive') },
    ...props.devices.map((d) => ({ value: d.name, label: `${d.name} · ${d.width}×${d.height}` })),
]);

const frameStyle = computed(() => {
    if (device.value === 'responsive') return { width: '100%', height: '100%' };

    const found = props.devices.find((d) => d.name === device.value);
    if (!found) return { width: '100%', height: '100%' };

    return { width: `${found.width}px`, height: `${found.height}px` };
});

function labelFor(node) {
    return node.label || props.kinds?.[node.type]?.label || node.type;
}

let timer = null;
let inflight = null;

/**
 * Ask for a fresh pass and point the iframe at it.
 *
 * Debounced, and the previous request is abandoned rather than raced: typing a
 * headline fires one of these per keystroke, and the answer that arrives last
 * is not necessarily the answer to the last question.
 */
function refresh(immediate = false) {
    clearTimeout(timer);
    timer = setTimeout(
        async () => {
            if (!current.value) return;

            inflight?.abort();
            const mine = (inflight = new AbortController());
            loading.value = true;

            try {
                const response = await fetch(props.previewUrl, {
                    method: 'POST',
                    signal: mine.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        // Statamic 6's CP head renders no `<meta name="csrf-token">`
                        // — the token lives in the JS config the layout writes
                        // out. Reading the meta tag returned an empty string,
                        // and every preview request came back 419.
                        'X-CSRF-TOKEN':
                            window.Statamic?.$config?.get('csrfToken') ??
                            window.StatamicConfig?.csrfToken ??
                            document.querySelector('meta[name="csrf-token"]')?.content ??
                            '',
                    },
                    body: JSON.stringify({
                        node_key: current.value,
                        nodes: props.nodes,
                        edges: props.edges,
                        // The pass from last time, so the server overwrites it
                        // rather than leaving another copy of the graph behind.
                        token: token.value,
                    }),
                });

                if (!response.ok) throw new Error(String(response.status));

                const body = await response.json();
                order.value = body.order ?? [];
                token.value = body.token ?? token.value;
                src.value = body.url;
                failed.value = false;
            } catch (error) {
                if (error.name === 'AbortError') return;
                failed.value = true;
            } finally {
                // Only the request that is still the current one may put the
                // spinner away. An abandoned one finishing later would turn it
                // off while its replacement is still in flight.
                if (inflight === mine) loading.value = false;
            }
        },
        immediate ? 0 : 400,
    );
}

const frame = ref(null);

/**
 * Point the iframe at a URL without throwing away what is in it.
 *
 * A `:key="src"` would rebuild the element on every keystroke, and the preview
 * would flash white and jump to the top four hundred milliseconds after every
 * letter. Statamic's own Live Preview does not do that, and it is the bar here:
 * the element stays, only `src` changes, and the scroll position comes back
 * when it is the same step being re-rendered.
 */
watch([src, frame], async ([url, el]) => {
    if (!el || !url) return;

    const sameStep = el.dataset.step === current.value;
    let scroll = 0;

    if (sameStep) {
        try {
            scroll = el.contentWindow?.scrollY ?? 0;
        } catch {
            // A preview on another origin will not let us look. Nothing to
            // restore, which is no worse than before.
        }
    }

    el.dataset.step = current.value ?? '';
    el.src = url;

    if (!scroll) return;

    el.addEventListener(
        'load',
        () => {
            try {
                el.contentWindow?.scrollTo(0, scroll);
            } catch {
                /* other origin */
            }
        },
        { once: true },
    );
});

function go(key) {
    current.value = key;
    emit('select', key);
    refresh(true);
}

function step(by) {
    const next = stops.value[index.value + by];
    if (next) go(next.node_key);
}

// A step that is gone from the canvas cannot stay in the frame.
watch(
    () => props.nodes.map((n) => n.node_key).join(','),
    () => {
        if (current.value && !nodeByKey.value[current.value]) {
            current.value = stops.value[0]?.node_key ?? null;
        }
    },
);

// Follow the selection: clicking a node on the canvas moves the preview to it,
// which is what somebody expects and saves a second click.
watch(
    () => props.selectedKey,
    (key) => {
        if (key && key !== current.value) go(key);
    },
);

// Any change to the graph is a change to the page.
watch(
    () => JSON.stringify([props.nodes, props.edges]),
    () => refresh(),
    { immediate: false },
);

// Something has to be on screen when it opens.
if (!current.value) {
    const entry = props.nodes.find((n) => n.type === 'entry');
    current.value = entry?.node_key ?? props.nodes[0]?.node_key ?? null;
}
refresh(true);

onBeforeUnmount(() => {
    clearTimeout(timer);
    inflight?.abort();
});
</script>

<template>
    <div class="flex min-h-0 flex-col rounded-lg border border-content-border bg-content-bg">
        <div class="flex flex-wrap items-center gap-2 border-b border-content-border px-3 py-2">
            <Button
                icon="arrow-left"
                size="sm"
                variant="ghost"
                :aria-label="t('previous', 'Previous step')"
                :disabled="index <= 0"
                @click="step(-1)"
            />
            <Button
                icon="arrow-right"
                size="sm"
                variant="ghost"
                :aria-label="t('next', 'Next step')"
                :disabled="index < 0 || index >= stops.length - 1"
                @click="step(1)"
            />

            <div class="mx-1 h-5 w-px bg-content-border" />

            <nav class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto" :aria-label="t('steps', 'Steps')">
                <button
                    v-for="stop in stops"
                    :key="stop.node_key"
                    type="button"
                    class="sa-stop"
                    :class="[`sa-stop--${stop.type}`, { 'sa-stop--on': stop.node_key === current }]"
                    :aria-current="stop.node_key === current ? 'step' : undefined"
                    @click="go(stop.node_key)"
                >
                    {{ labelFor(stop) }}
                </button>
            </nav>

            <Select v-model="device" :options="deviceOptions" size="sm" class="w-44 shrink-0" />

            <Button
                icon="x"
                size="sm"
                variant="ghost"
                :aria-label="t('close', 'Close preview')"
                @click="emit('close')"
            />
        </div>

        <div class="relative flex min-h-0 flex-1 items-start justify-center overflow-auto bg-gray-100 p-4 dark:bg-gray-900">
            <div
                v-if="loading"
                class="absolute end-3 top-3 z-10 rounded-full bg-content-bg px-2 py-1 text-2xs text-gray-500 shadow-sm"
            >
                {{ t('loading', 'Loading…') }}
            </div>

            <div v-if="failed" class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <Icon name="warning-diamond" class="size-6 text-gray-400" />
                <p class="text-sm text-gray-500">{{ t('failed', 'The preview could not be loaded.') }}</p>
                <Button size="sm" :text="t('retry', 'Try again')" @click="refresh(true)" />
            </div>

            <div v-else-if="!stops.length" class="flex h-full items-center justify-center">
                <p class="text-sm text-gray-500">{{ t('empty', 'Nothing to preview yet.') }}</p>
            </div>

            <!-- Sandboxed, with the three permissions a real page needs handed
                 back. `allow-same-origin` plus `allow-scripts` is famously not
                 much of a sandbox, and that is accepted here on purpose: the
                 content *is* this site, and the scroll position cannot be
                 restored across an opaque origin. What the attribute actually
                 buys is the two things missing from the list — a previewed page
                 cannot navigate the whole Control Panel away from under the
                 editor, and it cannot open windows. -->
            <iframe
                v-else-if="src"
                ref="frame"
                :style="frameStyle"
                sandbox="allow-same-origin allow-scripts allow-forms"
                class="max-w-full rounded border border-content-border bg-content-bg shadow-sm"
                :title="t('frame', 'Funnel step preview')"
            />
        </div>
    </div>
</template>
