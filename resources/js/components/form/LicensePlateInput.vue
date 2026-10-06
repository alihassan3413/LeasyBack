<script setup lang="ts">
import RequiredMark from '@/components/form/RequiredMark.vue';
import { normalizePlate, sanitizePlateNumber, splitPlate, toPlateUpperCase, validatePlateParts } from '@/lib/licensePlate';
import { computed, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        label?: string;
        required?: boolean;
        hint?: string;
        disabled?: boolean;
        serverError?: string;
    }>(),
    {
        label: 'Kennzeichen',
        required: true,
        // Names every segment's full width, and spells out that the trailing
        // "E" is optional rather than leaving it in the example — it is
        // reserved for electric vehicles, so a default ending in "E" would
        // read as though every plate had one.
        hint: '(Format: ABC AB 1234, optional E für Elektrofahrzeuge)',
        disabled: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const autoId = useId();
const fieldId = computed(() => props.id ?? autoId);

const [initialCity, initialLetters, initialNumber] = splitPlate(props.modelValue);
const city = ref(initialCity);
const letters = ref(initialLetters);
const number = ref(initialNumber);

watch(
    () => props.modelValue,
    (value) => {
        if (value === normalizePlate(city.value, letters.value, number.value)) {
            return;
        }

        [city.value, letters.value, number.value] = splitPlate(value);
    },
);

function emitCombined(): void {
    emit('update:modelValue', normalizePlate(city.value, letters.value, number.value));
}

function onCityInput(value: string): void {
    city.value = toPlateUpperCase(value).slice(0, 3);
    emitCombined();
}

function onLettersInput(value: string): void {
    letters.value = toPlateUpperCase(value).slice(0, 2);
    emitCombined();
}

function onNumberInput(value: string): void {
    number.value = sanitizePlateNumber(value).slice(0, 5);
    emitCombined();
}

const errors = computed(() => validatePlateParts(city.value, letters.value, number.value));

const segmentClass =
    'border-input focus:border-brand-green h-full w-full rounded-full border bg-white text-center text-base font-bold text-gray-800 uppercase outline-none placeholder:text-gray-400 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 md:text-sm';
</script>

<template>
    <div class="flex flex-col gap-1">
        <label v-if="label" :for="fieldId" class="text-sm font-semibold text-black">
            {{ label }}

            <RequiredMark v-if="required" />

            <span v-if="hint" class="ml-2 text-[10px] font-medium text-gray-500">
                {{ hint }}
            </span>
        </label>

        <div class="border-input flex h-10 items-center overflow-hidden rounded-full border bg-gray-100">
            <div class="ml-1 flex h-7 w-4 shrink-0 flex-col items-center justify-center rounded-[50px] bg-[#00339b]">
                <IconTablerCircleDotted class="size-3 text-[#FECD00]" />
                <span class="text-[10px] leading-none font-bold text-white">D</span>
            </div>

            <div class="flex h-full flex-1 items-center px-1.5 py-0.5">
                <input
                    :id="fieldId"
                    :value="city"
                    type="text"
                    :disabled="disabled"
                    placeholder="ABC"
                    maxlength="3"
                    aria-label="Unterscheidungszeichen"
                    :class="segmentClass"
                    @input="onCityInput(($event.target as HTMLInputElement).value)"
                />
            </div>

            <div class="flex flex-col items-center gap-0.5 px-1 text-gray-300">
                <IconCibCircle class="h-1.5 w-1.5" />
                <IconMdiBadgeOutline class="h-2 w-2" />
            </div>

            <div class="flex h-full flex-1 items-center px-1.5 py-0.5">
                <input
                    :value="letters"
                    type="text"
                    :disabled="disabled"
                    placeholder="AB"
                    maxlength="2"
                    aria-label="Erkennungszeichen"
                    :class="segmentClass"
                    @input="onLettersInput(($event.target as HTMLInputElement).value)"
                />
            </div>

            <div class="flex h-full flex-[1.4] items-center px-1.5 py-0.5">
                <input
                    :value="number"
                    type="text"
                    :disabled="disabled"
                    placeholder="1234"
                    maxlength="5"
                    aria-label="Erkennungsnummer"
                    :class="segmentClass"
                    @input="onNumberInput(($event.target as HTMLInputElement).value)"
                />
            </div>
        </div>

        <p v-for="message in errors" :key="message" data-testid="plate-error" class="text-xs text-red-500">
            {{ message }}
        </p>

        <p v-if="serverError" class="text-xs text-red-500">
            {{ serverError }}
        </p>
    </div>
</template>
