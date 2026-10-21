<script setup>
import { computed, inject, ref } from 'vue';
import { Button, Icon, Input, Select } from '@statamic/cms/ui';

/**
 * What the test person does after one mail: buys, clicks a link, unsubscribes.
 *
 * Only a choice in the simulation. Nothing is bought, clicked or unsubscribed: the choice goes back to the
 * planner, which lets her way continue from it (a purchase is handed to the product, an unsubscribe silences
 * the marketing mails after it).
 */
const props = defineProps({
    mailKey: { type: String, required: true },
    links: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
});

const emit = defineEmits(['add', 'remove']);
const probe = inject('probelauf');

const type = ref('buy');
const product = ref(null);
const link = ref(null);
const days = ref('0');

const mine = computed(() => props.actions.map((a, index) => ({ ...a, index })).filter((a) => a.mail === props.mailKey));

const typeOptions = computed(() => [
    { value: 'buy', label: probe.t('probe_action_buy') },
    { value: 'click', label: probe.t('probe_action_click') },
    { value: 'unsubscribe', label: probe.t('probe_action_unsub') },
]);
const productOptions = computed(() => props.products.map((p) => ({ value: p.key, label: p.label })));
const linkOptions = computed(() => props.links.map((l) => ({ value: l.url, label: l.text })));

const ready = computed(() => {
    if (type.value === 'buy') return Boolean(product.value ?? productOptions.value[0]);
    if (type.value === 'click') return Boolean(link.value ?? linkOptions.value[0]);
    return true;
});

function describe(action) {
    const after = action.after_seconds > 0 ? ` ${probe.t('probe_action_after')} ${Math.round(action.after_seconds / 86400)} ${probe.t('probe_action_days')}` : '';
    if (action.type === 'unsubscribe') return `${probe.t('probe_action_unsub')}${after}`;
    if (action.type === 'click') return `${probe.t('probe_action_click')}: ${action.url}${after}`;
    const known = props.products.find((p) => p.key === action.product);
    return `${probe.t('probe_action_buy')}: ${known ? known.label : action.product}${after}`;
}

function add() {
    if (!ready.value) return;

    emit('add', {
        type: type.value,
        mail: props.mailKey,
        product: type.value === 'buy' ? (product.value ?? productOptions.value[0]?.value ?? null) : null,
        url: type.value === 'click' ? (link.value ?? linkOptions.value[0]?.value ?? null) : null,
        after_seconds: Math.max(0, Math.min(365, parseInt(days.value, 10) || 0)) * 86400,
    });
}
</script>

<template>
    <section class="pa" data-pa>
        <h4 class="pa-title">{{ probe.t('probe_action_title') }}</h4>

        <ul v-if="mine.length" class="pa-list" data-pa-list>
            <li v-for="action in mine" :key="action.index" class="pa-item">
                <span>{{ describe(action) }}</span>
                <Button size="xs" variant="ghost" icon="trash" :aria-label="probe.t('probe_action_remove')" :title="probe.t('probe_action_remove')" @click="emit('remove', action.index)" />
            </li>
        </ul>

        <div class="pa-form">
            <Select v-model="type" size="sm" class="w-44" :options="typeOptions" :aria-label="probe.t('probe_action_title')" data-pa-type />
            <template v-if="type === 'buy'">
                <Select v-if="productOptions.length" :model-value="product ?? productOptions[0].value" size="sm" class="w-56" :options="productOptions" :aria-label="probe.t('probe_action_product')" data-pa-product @update:model-value="product = $event" />
                <span v-else class="pa-note">{{ probe.t('probe_action_no_products') }}</span>
            </template>
            <template v-else-if="type === 'click'">
                <Select v-if="linkOptions.length" :model-value="link ?? linkOptions[0].value" size="sm" class="w-56" :options="linkOptions" :aria-label="probe.t('probe_action_link')" data-pa-link @update:model-value="link = $event" />
                <span v-else class="pa-note">{{ probe.t('probe_action_no_links') }}</span>
            </template>
            <label class="pa-after">
                <span>{{ probe.t('probe_action_after') }}</span>
                <Input v-model="days" type="number" min="0" max="365" size="sm" class="w-16" :aria-label="probe.t('probe_action_days')" data-pa-days />
                <span>{{ probe.t('probe_action_days') }}</span>
            </label>
            <Button size="sm" :text="probe.t('probe_action_add')" :disabled="!ready" data-pa-add @click="add" />
        </div>
        <p class="pa-note"><Icon name="info" class="size-3.5" /> {{ probe.t('probe_action_help') }}</p>
    </section>
</template>

<style>
.pa { margin-top: 1.25rem; padding-top: 0.9rem; border-top: 1px solid var(--color-gray-200); }
.dark .pa { border-top-color: var(--color-gray-700); }
.pa-title { margin: 0 0 0.5rem; font-size: 0.8rem; font-weight: 600; color: var(--color-gray-900); }
.dark .pa-title { color: var(--color-gray-100); }
.pa-list { margin: 0 0 0.6rem; padding: 0; list-style: none; }
.pa-item { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.15rem 0; font-size: 0.85rem; color: var(--color-gray-900); }
.dark .pa-item { color: var(--color-gray-100); }
.pa-form { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem 0.75rem; }
.pa-after { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pa-after { color: var(--color-gray-400); }
.pa-note { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0.5rem 0 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pa-note { color: var(--color-gray-400); }
</style>
