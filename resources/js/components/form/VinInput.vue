<script setup lang="ts">
import FormField from '@/components/form/FormField.vue';
import { cn } from '@/lib/utils';
import { VIN_LENGTH, sanitizeVin } from '@/lib/vin';
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        label?: string;
        required?: boolean;
        labelHint?: string;
        placeholder?: string;
        disabled?: boolean;
        error?: string;
        /** Extra classes for the text field itself, e.g. a smaller font. */
        inputClass?: string;
    }>(),
    {
        label: 'FIN',
        required: true,
        labelHint: '(siehe Fahrzeugschein – Feld E)',
        placeholder: 'FIN eingeben',
        disabled: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const counterId = `${useId()}-vin-counter`;

const typed = computed(() => props.modelValue.length);
const remaining = computed(() => VIN_LENGTH - typed.value);
const isComplete = computed(() => remaining.value === 0);

const statusText = computed(() => {
    if (isComplete.value) {
        return 'Vollständig';
    }

    return remaining.value === 1 ? 'noch 1 Zeichen' : `noch ${remaining.value} Zeichen`;
});

/**
 * The field rejects characters a FIN cannot contain, so the typed value and
 * the model can differ for a keystroke. Writing the sanitised value straight
 * back onto the element keeps what the user sees identical to what is
 * submitted — Vue alone would not repaint it when the model came out unchanged.
 */
function onInput(event: Event): void {
    const element = event.target as HTMLInputElement;
    const sanitized = sanitizeVin(element.value);

    if (element.value !== sanitized) {
        element.value = sanitized;
    }

    emit('update:modelValue', sanitized);
}
</script>

<template>
    <FormField v-slot="{ id, describedBy, invalid }" :id="props.id" :label="label" :required="required" :label-hint="labelHint" :error="error">
        <div class="grid gap-1.5">
            <div class="relative">
                <input
                    :id="id"
                    :value="modelValue"
                    type="text"
                    inputmode="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    :maxlength="VIN_LENGTH"
                    :placeholder="placeholder"
                    :disabled="disabled"
                    :aria-invalid="invalid"
                    :aria-describedby="[describedBy, counterId].filter(Boolean).join(' ')"
                    :class="
                        cn(
                            'border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring/30 aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:border-destructive aria-[invalid=true]:focus-visible:ring-destructive/30 focus-visible:border-brand-green flex h-10 w-full rounded-full border py-2 pr-[4.75rem] pl-4 text-base tracking-[0.06em] uppercase transition-colors focus-visible:ring-2 focus-visible:ring-offset-0 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                            isComplete && !invalid ? 'border-brand-green' : '',
                            inputClass,
                        )
                    "
                    @input="onInput"
                />

                <div class="pointer-events-none absolute inset-y-0 right-1.5 flex items-center">
                    <span
                        class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums transition-colors duration-200"
                        :class="isComplete ? 'bg-brand-green/10 text-brand-green' : 'bg-gray-100 text-gray-500'"
                    >
                        <IconMdiCheck v-if="isComplete" class="size-3" />
                        {{ typed }}<span class="font-medium opacity-60">/{{ VIN_LENGTH }}</span>
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <div class="grid flex-1 grid-cols-[repeat(17,minmax(0,1fr))] gap-[2px]" aria-hidden="true">
                    <span
                        v-for="slot in VIN_LENGTH"
                        :key="slot"
                        class="h-[3px] rounded-full transition-colors duration-200"
                        :class="slot > typed ? 'bg-gray-200' : isComplete ? 'bg-brand-green' : 'bg-brand-green/45'"
                    />
                </div>

                <p
                    :id="counterId"
                    class="shrink-0 text-[11px] font-medium whitespace-nowrap"
                    :class="isComplete ? 'text-brand-green' : 'text-gray-500'"
                >
                    {{ statusText }}
                </p>
            </div>

            <span class="sr-only" role="status">{{ isComplete ? 'FIN vollständig, 17 von 17 Zeichen.' : '' }}</span>
        </div>
    </FormField>
</template>
