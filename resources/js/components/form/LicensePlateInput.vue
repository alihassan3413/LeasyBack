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
import { Badge, Circle, CircleDashed } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        disabled?: boolean;
        serverError?: string;
        /** 'eu' renders the EU-style badge plate used on the b2c onboarding/registration flow. */
        variant?: 'default' | 'eu';
    }>(),
    {
        variant: 'default',
    },
);

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
        <div v-if="variant === 'eu'" class="border-brand-green-gray flex items-stretch rounded-[5px] border">
            <div class="flex h-11 w-9 shrink-0 flex-col items-center justify-center gap-0.5 rounded-l-[5px] bg-[#001f6a] py-1">
                <CircleDashed class="size-6 text-[#FECD00]" aria-hidden="true" />
                <span class="text-[12px] leading-none font-bold text-white">D</span>
            </div>

            <input
                :value="city"
                type="text"
                :disabled="disabled"
                maxlength="3"
                placeholder="ABC"
                aria-label="Unterscheidungszeichen"
                class="border-brand-green-gray text-brand-black h-11 w-0 flex-1 border-x bg-transparent px-2 text-center text-base font-extrabold uppercase outline-none disabled:opacity-50"
                @input="(event) => onCityChange((event.target as HTMLInputElement).value)"
            />

            <div class="border-brand-green-gray flex flex-col gap-1 p-1">
                <Circle class="text-brand-black size-3" aria-hidden="true" />
                <Badge class="text-brand-black size-3.5" aria-hidden="true" />
            </div>

            <input
                :value="letters"
                type="text"
                :disabled="disabled"
                maxlength="2"
                placeholder="DE"
                aria-label="Erkennungszeichen"
                class="border-brand-green-gray text-brand-black h-11 w-0 flex-1 border-r bg-transparent px-2 text-center text-base font-extrabold uppercase outline-none disabled:opacity-50"
                @input="(event) => onLettersChange((event.target as HTMLInputElement).value)"
            />

            <input
                :value="number"
                type="text"
                :disabled="disabled"
                maxlength="5"
                placeholder="1234E"
                aria-label="Erkennungsnummer"
                class="border-brand-green-gray text-brand-black h-11 w-0 flex-1 border-r bg-transparent px-2 text-center text-base font-extrabold uppercase outline-none disabled:opacity-50"
                @input="(event) => onNumberChange((event.target as HTMLInputElement).value)"
            />
        </div>

        <div v-else class="flex items-center gap-2">
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
