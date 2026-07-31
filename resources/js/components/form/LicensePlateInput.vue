<script setup lang="ts">
/**
 * Reusable German 3-segment license plate ("Kennzeichen") input:
 * Unterscheidungszeichen / Erkennungszeichen / Erkennungsnummer. Emits the
 * combined, normalized plate string (e.g. "K LB 2026") via
 * update:modelValue — the public API is a single string, like any other
 * text-ish form field, even though it's rendered as three inputs.
 */
import { Input } from '@/components/ui/input';
import { normalizePlate, sanitizePlateNumber, toPlateUpperCase, validatePlateParts } from '@/lib/licensePlate';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
    disabled?: boolean;
    serverError?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

function splitPlate(plate: string): [string, string, string] {
    const parts = plate.trim().split(/\s+/).filter(Boolean);

    return [parts[0] ?? '', parts[1] ?? '', parts[2] ?? ''];
}

const [initialCity, initialLetters, initialNumber] = splitPlate(props.modelValue);
const city = ref(initialCity);
const letters = ref(initialLetters);
const number = ref(initialNumber);

watch(
    () => props.modelValue,
    (value) => {
        if (value === normalizePlate(city.value, letters.value, number.value)) {
            return; // this is just our own emit echoing back — avoid a feedback loop
        }
        [city.value, letters.value, number.value] = splitPlate(value);
    },
);

function emitCombined() {
    emit('update:modelValue', normalizePlate(city.value, letters.value, number.value));
}

function onCityChange(value: string) {
    city.value = toPlateUpperCase(value).slice(0, 3);
    emitCombined();
}

function onLettersChange(value: string) {
    letters.value = toPlateUpperCase(value).slice(0, 2);
    emitCombined();
}

function onNumberChange(value: string) {
    number.value = sanitizePlateNumber(value).slice(0, 5);
    emitCombined();
}

const errors = computed(() => validatePlateParts(city.value, letters.value, number.value));
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center gap-2">
            <Input
                :model-value="city"
                :disabled="disabled"
                maxlength="3"
                placeholder="K"
                class="w-16 text-center uppercase"
                aria-label="Unterscheidungszeichen"
                @update:model-value="(value) => onCityChange(String(value))"
            />
            <span class="text-muted-foreground" aria-hidden="true">–</span>
            <Input
                :model-value="letters"
                :disabled="disabled"
                maxlength="2"
                placeholder="LB"
                class="w-14 text-center uppercase"
                aria-label="Erkennungszeichen"
                @update:model-value="(value) => onLettersChange(String(value))"
            />
            <span class="text-muted-foreground" aria-hidden="true">–</span>
            <Input
                :model-value="number"
                :disabled="disabled"
                maxlength="5"
                placeholder="2026"
                class="w-20 text-center uppercase"
                aria-label="Erkennungsnummer"
                @update:model-value="(value) => onNumberChange(String(value))"
            />
        </div>
        <p v-for="message in errors" :key="message" class="text-destructive text-sm">{{ message }}</p>
        <p v-if="serverError" class="text-destructive text-sm">{{ serverError }}</p>
    </div>
</template>
