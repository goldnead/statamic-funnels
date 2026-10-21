<script setup>
import { computed, inject } from 'vue';
import { Button, Dropdown, DropdownItem, DropdownMenu, Select } from '@statamic/cms/ui';
import { DELAYS } from '../../support/probelauf.js';

/**
 * What the person does with a mail: opens it (and nothing more), clicks a link, buys, unsubscribes.
 *
 * Only a choice in the simulation. Nothing is clicked, bought or unsubscribed: the choice goes back to the planner, which
 * lets her way go on from it. A button that is on says what she does; pressing it again takes it back, and "Öffnet" is on
 * as long as she does nothing else.
 */
const props = defineProps({
    mailKey: { type: String, required: true },
    links: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
    maxActions: { type: Number, default: 12 },
});

const emit = defineEmits(['toggle', 'clear', 'delay']);
const probe = inject('probelauf');

// Up to this many links are a button each; more are a menu, so the row never grows into a wall of buttons.
const LINK_BUTTONS = 3;

const mine = computed(() => props.actions.filter((a) => a.mail === props.mailKey));
const opensOnly = computed(() => mine.value.length === 0);
const full = computed(() => props.actions.length >= props.maxActions);

const clicked = (url) => mine.value.some((a) => a.type === 'click' && a.url === url);
const bought = computed(() => mine.value.filter((a) => a.type === 'buy'));
const boughtLabel = (key) => props.products.find((p) => p.key === key)?.label ?? key;
const unsubscribed = computed(() => mine.value.some((a) => a.type === 'unsubscribe'));

// What the actions of this mail wait for, as the one choice that is shown (they share it).
const delay = computed(() => String(Math.round((mine.value[0]?.after_seconds ?? 0) / 86400)));
const delayOptions = computed(() => DELAYS.map((d) => ({
    value: String(d),
    label: d === 0 ? probe.t('probe_do_now') : probe.t(d === 1 ? 'probe_do_after_one' : 'probe_do_after_many').replace(':days', d),
})));

const toggle = (action) => emit('toggle', { ...action, mail: props.mailKey });

const buyText = computed(() => (bought.value.length === 1
    ? probe.t('probe_do_buys_named').replace(':name', () => boughtLabel(bought.value[0].product))
    : bought.value.length > 1
        ? probe.t('probe_do_buys_many').replace(':count', bought.value.length)
        : probe.t('probe_do_buys')));
const linkText = (link) => probe.t('probe_do_clicks').replace(':link', () => link.text);
</script>

<template>
    <div class="pa" data-pa>
        <div class="pa-row" role="group" :aria-label="probe.t('probe_do_label')">
            <Button size="sm" :class="opensOnly && 'pa-on'" :aria-pressed="opensOnly" :text="probe.t('probe_do_opens')" data-pa-opens @click="emit('clear')" />

            <template v-if="links.length && links.length <= LINK_BUTTONS">
                <Button
                    v-for="(link, i) in links"
                    :key="i"
                    size="sm"
                    :class="clicked(link.url) && 'pa-on'"
                    :aria-pressed="clicked(link.url)"
                    :text="linkText(link)"
                    :disabled="full && !clicked(link.url)"
                    :data-pa-click="link.url"
                    @click="toggle({ type: 'click', url: link.url })"
                />
            </template>
            <Dropdown v-else-if="links.length" align="start">
                <template #trigger>
                    <Button size="sm" icon-append="chevron-down" :class="mine.some((a) => a.type === 'click') && 'pa-on'" :text="probe.t('probe_do_clicks_menu')" data-pa-click-menu />
                </template>
                <DropdownMenu>
                    <DropdownItem
                        v-for="(link, i) in links"
                        :key="i"
                        :icon="clicked(link.url) ? 'checkmark' : undefined"
                        :text="link.text"
                        :disabled="full && !clicked(link.url)"
                        :data-pa-click="link.url"
                        @click="toggle({ type: 'click', url: link.url })"
                    />
                </DropdownMenu>
            </Dropdown>

            <Dropdown v-if="products.length" align="start">
                <template #trigger>
                    <Button size="sm" icon-append="chevron-down" :class="bought.length && 'pa-on'" :aria-pressed="bought.length > 0" :text="buyText" data-pa-buy />
                </template>
                <DropdownMenu>
                    <DropdownItem
                        v-for="product in products"
                        :key="product.key"
                        :icon="bought.some((a) => a.product === product.key) ? 'checkmark' : undefined"
                        :text="product.label"
                        :disabled="full && !bought.some((a) => a.product === product.key)"
                        :data-pa-product="product.key"
                        @click="toggle({ type: 'buy', product: product.key })"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button v-else size="sm" :text="probe.t('probe_do_buys')" disabled :title="probe.t('probe_do_no_products')" data-pa-buy />

            <Button size="sm" :class="unsubscribed && 'pa-on'" :aria-pressed="unsubscribed" :text="probe.t('probe_do_unsub')" data-pa-unsub @click="toggle({ type: 'unsubscribe' })" />

            <label v-if="!opensOnly" class="pa-when">
                <span>{{ probe.t('probe_do_when') }}</span>
                <Select
                    size="sm"
                    class="w-36"
                    :model-value="delay"
                    :options="delayOptions"
                    :aria-label="probe.t('probe_do_when')"
                    data-pa-delay
                    @update:model-value="emit('delay', Number($event))"
                />
            </label>
        </div>
        <p v-if="full" class="pa-note" data-pa-full>{{ probe.t('probe_action_full').replace(':max', () => maxActions) }}</p>
    </div>
</template>

<style>
.pa { margin: 0 0 0.9rem; }
.pa-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem 0.5rem; }
/* The one that is on: the same neutral button, one step heavier, so it reads as chosen in both themes. */
.pa-row .pa-on { background: var(--color-gray-100); border-color: var(--color-gray-400); }
.dark .pa-row .pa-on { background: var(--color-gray-700); border-color: var(--color-gray-500); }
.pa-when { display: inline-flex; align-items: center; gap: 0.4rem; margin-left: auto; font-size: 0.8rem; color: var(--color-gray-600); }
.dark .pa-when { color: var(--color-gray-400); }
.pa-note { margin: 0.5rem 0 0; font-size: 0.78rem; color: var(--color-gray-600); }
.dark .pa-note { color: var(--color-gray-400); }
</style>
