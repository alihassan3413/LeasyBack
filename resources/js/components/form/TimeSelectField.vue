<script setup lang="ts">
/**
 * Appointment time as a real dropdown instead of `<input type="time">`. The
 * native picker is drawn by the browser, so inside a focus-trapped modal it
 * fought the dialog for focus and its list was clipped against the modal edge
 * — neither is reachable from our styles. Built on SelectField so it portals,
 * flips on collision and keeps the chosen slot visible like every other
 * dropdown in the app.
 */
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        placeholder?: string;
        disabled?: boolean;
        describedBy?: string;
        invalid?: boolean;
        startHour?: number;
        endHour?: number;
        stepMinutes?: number;
    }>(),
    {
        placeholder: 'Uhrzeit wählen',
        startHour: 7,
        endHour: 18,
        stepMinutes: 30,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

/** Stored times may carry seconds ("10:30:00"); the slot list never does. */
const selected = computed(() => props.modelValue.slice(0, 5));

function toTimeLabel(minutesFromMidnight: number): string {
    const hours = String(Math.floor(minutesFromMidnight / 60)).padStart(2, '0');
    const minutes = String(minutesFromMidnight % 60).padStart(2, '0');

    return `${hours}:${minutes}`;
}

const options = computed(() => {
    const slots: SelectFieldOption[] = [];

    for (let minutes = props.startHour * 60; minutes <= props.endHour * 60; minutes += props.stepMinutes) {
        const value = toTimeLabel(minutes);
        slots.push({ label: value, value });
    }

    // An appointment booked before these slots existed must still show its own
    // time rather than falling back to the placeholder.
    if (selected.value && !slots.some((slot) => slot.value === selected.value)) {
        slots.push({ label: selected.value, value: selected.value });
        slots.sort((a, b) => a.value.localeCompare(b.value));
    }

    return slots;
});
</script>

<template>
    <SelectField
        :id="id"
        :model-value="selected"
        :options="options"
        :placeholder="placeholder"
        :disabled="disabled"
        :described-by="describedBy"
        :invalid="invalid"
        @update:model-value="(value) => emit('update:modelValue', value)"
    />
</template>
