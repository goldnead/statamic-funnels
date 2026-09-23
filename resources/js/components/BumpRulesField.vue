<script setup>
import { computed } from 'vue';
import { Combobox, Select, Switch, Field, Description } from '@statamic/cms/ui';

/**
 * Die Regeln der Bumps eines Kassenschritts (F1).
 *
 * Je Bump des gewaehlten Angebots vier Angaben: zu welchen Zahlweisen, mit
 * welchem anderen Bump, vorausgewaehlt, und ob wiederkehrende Kaeufer:innen ihn
 * sehen. Gespeichert als `{ <bump>: { options, requires, preselected,
 * returning } }` in `config.bump_rules`; ein Bump ohne Angaben faellt heraus,
 * damit die Konfiguration nur traegt, was jemand eingestellt hat.
 */
const props = defineProps({
    modelValue: { type: [Object, Array], default: null },
    // Das Angebot des Schritts aus der Seitenliste: `{ bumps, options }`.
    offer: { type: Object, default: null },
    labels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const t = (key, fallback = '') => props.labels?.[key] ?? fallback;

const bumps = computed(() => props.offer?.bumps ?? []);
const options = computed(() => props.offer?.options ?? []);

const returningOptions = computed(() => [
    { value: 'show', label: t('returning_show', 'Show to everyone') },
    { value: 'hide', label: t('returning_hide', 'Hide from returning customers') },
    { value: 'only', label: t('returning_only', 'Only returning customers') },
]);

function rule(handle) {
    const all = props.modelValue && !Array.isArray(props.modelValue) ? props.modelValue : {};

    return { options: [], requires: null, preselected: false, returning: 'show', ...(all[handle] ?? {}) };
}

function isEmpty(r) {
    return (!r.options || r.options.length === 0) && !r.requires && !r.preselected && (!r.returning || r.returning === 'show');
}

function set(handle, key, value) {
    const all = { ...(props.modelValue && !Array.isArray(props.modelValue) ? props.modelValue : {}) };
    const next = { ...rule(handle), [key]: value };

    if (isEmpty(next)) {
        delete all[handle];
    } else {
        all[handle] = next;
    }

    emit('update:modelValue', Object.keys(all).length ? all : null);
}

function others(handle) {
    return bumps.value.filter((b) => b.value !== handle);
}
</script>

<template>
    <div>
        <Description v-if="!offer" :text="t('no_offer', 'Choose an offer first.')" />
        <Description v-else-if="!bumps.length" :text="t('no_bumps', 'This offer has no bumps.')" />

        <div
            v-for="bump in bumps"
            :key="bump.value"
            class="mb-3 rounded-lg border border-content-border p-3 last:mb-0"
            :data-bump-rule="bump.value"
        >
            <p class="mb-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ bump.label }}</p>

            <Field v-if="options.length" :label="t('options', 'Only with these pricing options')" class="mb-3">
                <Combobox
                    multiple
                    :model-value="rule(bump.value).options"
                    :options="options"
                    :placeholder="t('options_all', 'All pricing options')"
                    @update:model-value="set(bump.value, 'options', $event ?? [])"
                />
            </Field>

            <Field v-if="others(bump.value).length" :label="t('requires', 'Only together with')" class="mb-3">
                <Select
                    clearable
                    :model-value="rule(bump.value).requires"
                    :options="others(bump.value)"
                    :placeholder="t('requires_none', 'No other bump needed')"
                    @update:model-value="set(bump.value, 'requires', $event || null)"
                />
            </Field>

            <Field :label="t('returning', 'Returning customers')" class="mb-3">
                <Select
                    :model-value="rule(bump.value).returning"
                    :options="returningOptions"
                    @update:model-value="set(bump.value, 'returning', $event || 'show')"
                />
            </Field>

            <Field :label="t('preselected', 'Preselected')" inline>
                <Switch
                    :model-value="!!rule(bump.value).preselected"
                    @update:model-value="set(bump.value, 'preselected', $event)"
                />
            </Field>
        </div>
    </div>
</template>
