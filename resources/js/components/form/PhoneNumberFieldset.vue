<script setup lang="ts">
/**
 * Reusable repeatable phone-number editor: add/remove rows, each with an
 * international-prefix dropdown (SelectField, not a native <select>) and a
 * number input. Not specific to any one page's form shape.
 */
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatAsYouType, isValidPhone } from '@/lib/phone';
import { Plus, Trash2 } from 'lucide-vue-next';

export interface PhoneNumberValue {
    international_prefix: string;
    phone_number: string;
}

const props = defineProps<{ modelValue: PhoneNumberValue[] }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: PhoneNumberValue[]): void }>();

const prefixOptions: SelectFieldOption[] = [
    { label: '+49 (Deutschland)', value: '+49' },
    { label: '+43 (Österreich)', value: '+43' },
    { label: '+41 (Schweiz)', value: '+41' },
];

function updateRow(index: number, patch: Partial<PhoneNumberValue>) {
    const next = props.modelValue.map((row, i) => (i === index ? { ...row, ...patch } : row));
    emit('update:modelValue', next);
}

function addRow() {
    emit('update:modelValue', [...props.modelValue, { international_prefix: '+49', phone_number: '' }]);
}

function removeRow(index: number) {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, i) => i !== index),
    );
}

// The international prefix is its own dropdown, so the number itself is
// digits-only — matches the longest realistic national significant number
// (E.164 allows 15 digits total, minus the shortest prefix).
const PHONE_NUMBER_MAX_LENGTH = 14;

/**
 * Digits are what is stored and what the server validates
 * (`phones.*.phone_number` is `^\d{4,14}$`); the grouping below is display
 * only, so the mask can change without touching the API contract.
 */
function sanitizePhoneNumber(value: string | number): string {
    return String(value).replace(/\D+/g, '').slice(0, PHONE_NUMBER_MAX_LENGTH);
}

/** The same digits, grouped the way that country writes them. */
function displayValue(row: PhoneNumberValue): string {
    return formatAsYouType(row.phone_number, row.international_prefix);
}

/**
 * Only flagged once there is enough to judge — a half-typed number is not yet
 * wrong, and marking it red while someone types is noise.
 */
function looksWrong(row: PhoneNumberValue): boolean {
    return row.phone_number.length >= 4 && !isValidPhone(row.phone_number, row.international_prefix);
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="(row, index) in modelValue" :key="index" class="flex items-start gap-2">
            <div class="w-40 shrink-0">
                <SelectField
                    :model-value="row.international_prefix"
                    :options="prefixOptions"
                    placeholder="Vorwahl"
                    @update:model-value="(value) => updateRow(index, { international_prefix: value })"
                />
            </div>
            <Input
                :model-value="displayValue(row)"
                type="tel"
                inputmode="tel"
                :aria-invalid="looksWrong(row) || undefined"
                placeholder="z. B. 030 12345678"
                class="flex-1"
                @update:model-value="(value) => updateRow(index, { phone_number: sanitizePhoneNumber(value) })"
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="modelValue.length <= 1"
                aria-label="Telefonnummer entfernen"
                @click="removeRow(index)"
            >
                <Trash2 class="size-4" aria-hidden="true" />
            </Button>
        </div>

        <Button type="button" variant="outline" size="sm" @click="addRow">
            <Plus class="size-4" aria-hidden="true" />
            Telefonnummer hinzufügen
        </Button>
    </div>
</template>
