<script setup>
import { computed } from 'vue';
import {
    Stack, Panel, Card, Field, Input, Textarea, Switch, Description, Button, Alert, Select,
} from '@statamic/cms/ui';

/**
 * Was ausser dem Graphen am Funnel haengt (F3, F4, F6, F7).
 *
 * Ein Stack von rechts, wie die Filter einer Liste: der Graph bleibt sichtbar,
 * und gespeichert wird mit demselben Knopf wie die Leinwand. Der Stack haelt
 * nichts selbst; er schreibt in `modelValue`, das Teil des Graphen ist.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    modelValue: { type: Object, required: true },
    labels: { type: Object, default: () => ({}) },
    embed: { type: Object, default: () => ({ script: '', url: '' }) },
    tracking: { type: Object, default: () => ({ mode: 'block', message: '', capi: false }) },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open', 'update:modelValue', 'save']);

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

function set(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

const scriptSnippet = computed(() => `<script src="${props.embed.script}" async><\/script>`);

const popupSnippet = computed(
    () => `<a href="${props.embed.url}" data-funnel-popup>${t('embed_button', 'Sign up now')}</a>`,
);

const inlineSnippet = computed(() => `<div data-funnel-embed="${props.embed.url}"></div>`);

const trackingVariant = computed(() => (props.tracking?.mode === 'block' ? 'warning' : 'default'));

// Tracking-Code ist rohes JavaScript; ohne das Recht bleibt alles lesbar,
// aber gesperrt. Der Server lehnt Aenderungen ohnehin ab.
const locked = computed(() => props.tracking?.can_edit === false);

const services = computed(() => props.tracking?.services ?? []);

// Ein Dienst, den die Consent-Config nicht kennt: der Code startet nie.
function unknownService(value) {
    if (!value || !services.value.length) return null;

    return services.value.some((s) => s.value === value)
        ? null
        : t('service_unknown', '').replace(':service', value);
}

const serviceHelp = computed(() => t('tracking_service_help', '').replace(':service', props.tracking?.service ?? ''));

const error = (key) => props.errors?.[`settings.${key}`] ?? null;
</script>

<template>
    <Stack
        :open="open"
        size="half"
        icon="cog"
        :title="t('title', 'Funnel settings')"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-6 p-4" data-funnel-settings>
            <Panel :heading="t('in_app', 'In-app browser')" :subheading="t('in_app_help')">
                <Card>
                    <Field :label="t('in_app_enabled', 'Show the note')" inline class="mb-4">
                        <Switch
                            :model-value="modelValue.in_app_enabled !== false"
                            @update:model-value="set('in_app_enabled', $event)"
                        />
                    </Field>
                    <Field
                        :label="t('in_app_text', 'Own wording')"
                        :instructions="t('in_app_text_help')"
                        :error="error('in_app_text')"
                    >
                        <Textarea
                            :model-value="modelValue.in_app_text ?? ''"
                            :rows="3"
                            :disabled="modelValue.in_app_enabled === false"
                            @update:model-value="set('in_app_text', $event)"
                        />
                    </Field>
                </Card>
            </Panel>

            <Panel :heading="t('embed', 'Embedding')" :subheading="t('embed_help')">
                <Card>
                    <Field
                        :label="t('embed_domains', 'Allowed domains')"
                        :instructions="t('embed_domains_help')"
                        :error="error('embed_domains')"
                        class="mb-4"
                    >
                        <Textarea
                            :model-value="modelValue.embed_domains ?? ''"
                            :rows="3"
                            class="font-mono"
                            @update:model-value="set('embed_domains', $event)"
                        />
                    </Field>
                    <Field :label="t('embed_script', '1. Include once')" class="mb-4">
                        <Input :model-value="scriptSnippet" read-only copyable class="font-mono" />
                    </Field>
                    <Field :label="t('embed_popup', '2a. As a popup')" class="mb-4">
                        <Input :model-value="popupSnippet" read-only copyable class="font-mono" />
                    </Field>
                    <Field :label="t('embed_inline', '2b. Inline')">
                        <Input :model-value="inlineSnippet" read-only copyable class="font-mono" />
                    </Field>
                </Card>
            </Panel>

            <Panel :heading="t('tracking', 'Tracking')">
                <Card>
                    <Alert :variant="trackingVariant" :text="tracking.message" class="mb-4" />
                    <Alert v-if="locked" variant="warning" :text="t('tracking_locked')" class="mb-4" />
                    <Alert
                        v-if="tracking.default_known === false"
                        variant="error"
                        :text="t('service_unknown', '').replace(':service', tracking.service)"
                        class="mb-4"
                    />

                    <template v-for="slot in [
                        { key: 'tracking_head', service: 'tracking_head_service', label: t('tracking_head', 'Code in the head of every page'), help: t('tracking_head_help'), code: true },
                        { key: 'tracking_thanks', service: 'tracking_thanks_service', label: t('tracking_thanks', 'Code after the purchase'), help: t('tracking_thanks_help'), code: true },
                        { key: 'meta_pixel_id', service: 'meta_pixel_service', label: t('meta_pixel_id', 'Meta pixel ID'), help: t('meta_pixel_id_help'), code: false },
                    ]" :key="slot.key">
                        <Field :label="slot.label" :instructions="slot.help" :error="error(slot.key)" class="mb-2">
                            <Textarea
                                v-if="slot.code"
                                :model-value="modelValue[slot.key] ?? ''"
                                :rows="4"
                                class="font-mono"
                                :read-only="locked"
                                @update:model-value="set(slot.key, $event)"
                            />
                            <Input
                                v-else
                                :model-value="modelValue[slot.key] ?? ''"
                                class="font-mono"
                                inputmode="numeric"
                                :read-only="locked"
                                @update:model-value="set(slot.key, $event)"
                            />
                        </Field>
                        <Field
                            :label="t('tracking_service', 'Consent')"
                            :instructions="serviceHelp"
                            :error="error(slot.service) || unknownService(modelValue[slot.service])"
                            class="mb-5"
                        >
                            <Select
                                v-if="services.length"
                                clearable
                                :model-value="modelValue[slot.service] ?? null"
                                :options="services"
                                :placeholder="tracking.service"
                                :read-only="locked"
                                @update:model-value="set(slot.service, $event || null)"
                            />
                            <Input
                                v-else
                                :model-value="modelValue[slot.service] ?? ''"
                                class="font-mono"
                                :placeholder="tracking.service"
                                :read-only="locked"
                                @update:model-value="set(slot.service, $event)"
                            />
                        </Field>
                    </template>
                    <Description
                        class="mt-2"
                        :text="tracking.capi ? t('capi_on') : t('capi_off')"
                    />
                </Card>
            </Panel>

            <div class="flex justify-end">
                <Button variant="primary" :text="t('save', 'Save')" @click="emit('save')" />
            </div>
        </div>
    </Stack>
</template>
