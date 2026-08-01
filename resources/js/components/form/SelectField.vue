<script setup lang="ts">
/**
 * A reusable dropdown for form fields — built on the headless reka-ui Select
 * primitives (see @/components/ui/select), never a native <select>. Pairs
 * with FormField the same way Input/PasswordInput do: bind the scoped slot's
 * id/describedBy/invalid straight onto this component's matching props.
 */
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { computed, type HTMLAttributes } from 'vue';

export interface SelectFieldOption {
    label: string;
    value: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: SelectFieldOption[];
        placeholder?: string;
        disabled?: boolean;
        id?: string;
        describedBy?: string;
        invalid?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        placeholder: 'Bitte wählen',
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

// reka-ui's Select treats `undefined` as "nothing selected" (that's what
// makes the placeholder show) — the public API of this component still
// deals only in plain strings like every other form field.
const selectValue = computed(() => props.modelValue || undefined);
</script>

<template>
    <Select :model-value="selectValue" :disabled="disabled" @update:model-value="(value) => emit('update:modelValue', String(value ?? ''))">
        <SelectTrigger :id="id" :class="props.class" :aria-invalid="invalid" :aria-describedby="describedBy">
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-for="option in options" :key="option.value" :value="option.value">
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
