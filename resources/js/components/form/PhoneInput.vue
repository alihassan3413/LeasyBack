<script setup lang="ts">
/**
 * A single phone field, grouped as the country writes it while it is typed.
 *
 * For the places that take one free-text contact number and have no dialling
 * prefix beside them. The value it emits is the formatted string, because
 * these fields are stored as plain strings and the grouping is what a person
 * reads back. Where a number is stored digits-only against a prefix dropdown,
 * PhoneNumberFieldset is the component to use instead.
 */
import { Input } from '@/components/ui/input';
import { formatAsYouType, isValidPhone } from '@/lib/phone';
import { computed, type HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        country?: string;
        placeholder?: string;
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        country: '+49',
        placeholder: 'z. B. 030 12345678',
        disabled: false,
        class: undefined,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

/** Half a number is not yet wrong, so nothing is flagged until there is enough to judge. */
const looksWrong = computed(() => props.modelValue.replace(/\D/g, '').length >= 4 && !isValidPhone(props.modelValue, props.country));

function onInput(value: string | number) {
    emit('update:modelValue', formatAsYouType(String(value), props.country));
}
</script>

<template>
    <Input
        :model-value="props.modelValue"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        :disabled="disabled"
        :placeholder="placeholder"
        :aria-invalid="looksWrong || undefined"
        :class="props.class"
        @update:model-value="onInput"
    />
</template>
