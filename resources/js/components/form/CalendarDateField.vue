<script setup lang="ts">
/**
 * Popover date picker matching leasyback_web's CalendarDateField.vue design
 * exactly (month/year steppers, 7-column day grid) — adapted to this app's
 * plain v-model + FormField contract instead of vee-validate's useField.
 */
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useAppointmentCalendar } from '@/composables/useAppointmentCalendar';
import { cn } from '@/lib/utils';
import { CalendarDays, ChevronDown, ChevronRight, ChevronUp } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        describedBy?: string;
        invalid?: boolean;
        placeholder?: string;
        minDaysAhead?: number;
        allowPast?: boolean;
        blockWeekends?: boolean;
    }>(),
    {
        placeholder: 'TT.MM.JJJJ',
        minDaysAhead: 0,
        allowPast: false,
        blockWeekends: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const selectedDate = computed({
    get: () => props.modelValue,
    set: (value: string) => emit('update:modelValue', value),
});

const {
    datePopoverOpen: isOpen,
    calendarYear,
    calendarMonth,
    monthNamesShort,
    dayHeaders,
    calendarDays,
    selectedDateDisplay,
    previousMonth,
    nextMonth,
    previousYear,
    nextYear,
    selectDay,
    isSelectedDay,
    isSelectableDay,
} = useAppointmentCalendar(selectedDate, {
    minDaysAhead: props.minDaysAhead,
    allowPast: props.allowPast,
    blockWeekends: props.blockWeekends,
});
</script>

<template>
    <Popover v-model:open="isOpen">
        <PopoverTrigger as-child>
            <button
                :id="id"
                type="button"
                :aria-invalid="invalid"
                :aria-describedby="describedBy"
                :class="
                    cn(
                        'border-brand-green-gray text-brand-black focus:border-brand-green flex h-10 w-full items-center justify-between rounded-[5px] border bg-white px-3 text-left text-sm outline-none transition',
                        invalid && 'border-destructive',
                    )
                "
            >
                <span :class="selectedDateDisplay ? 'text-brand-black' : 'text-brand-green-gray'">
                    {{ selectedDateDisplay || placeholder }}
                </span>

                <CalendarDays class="text-brand-teal size-4.5" aria-hidden="true" />
            </button>
        </PopoverTrigger>

        <PopoverContent align="start">
            <div class="mb-4 flex items-center justify-center gap-2">
                <div class="border-brand-green-gray text-brand-black flex h-10 w-20 items-center justify-center gap-2 rounded-[6px] border bg-white text-[15px] font-bold">
                    <span>{{ monthNamesShort[calendarMonth] }}</span>

                    <div class="flex flex-col">
                        <button type="button" class="text-brand-green-gray hover:text-brand-teal leading-none" aria-label="Vorheriger Monat" @click="previousMonth">
                            <ChevronUp class="size-4" aria-hidden="true" />
                        </button>
                        <button type="button" class="text-brand-green-gray hover:text-brand-teal -mt-1 leading-none" aria-label="Nächster Monat" @click="nextMonth">
                            <ChevronDown class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <div class="border-brand-green-gray text-brand-black flex h-10 w-24 items-center justify-center gap-2 rounded-[6px] border bg-white text-[15px] font-bold">
                    <span>{{ calendarYear }}</span>

                    <div class="flex flex-col">
                        <button type="button" class="text-brand-green-gray hover:text-brand-teal leading-none" aria-label="Vorheriges Jahr" @click="previousYear">
                            <ChevronUp class="size-4" aria-hidden="true" />
                        </button>
                        <button type="button" class="text-brand-green-gray hover:text-brand-teal -mt-1 leading-none" aria-label="Nächstes Jahr" @click="nextYear">
                            <ChevronDown class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <button type="button" class="text-brand-green-gray hover:text-brand-teal ml-1" aria-label="Nächster Monat" @click="nextMonth">
                    <ChevronRight class="size-6" aria-hidden="true" />
                </button>
            </div>

            <div class="text-brand-black grid grid-cols-7 text-center text-[13px] font-bold">
                <div v-for="dayHeader in dayHeaders" :key="dayHeader" class="pb-3">
                    {{ dayHeader }}
                </div>
            </div>

            <div class="text-brand-black grid grid-cols-7 gap-y-2 text-center text-[13px] font-bold">
                <button
                    v-for="(calendarDay, index) in calendarDays"
                    :key="index"
                    type="button"
                    class="mx-auto flex h-6 w-6 items-center justify-center rounded-[6px] transition"
                    :class="{
                        'bg-brand-green text-white': isSelectedDay(calendarDay),
                        'hover:border-brand-green text-brand-black hover:border': !isSelectedDay(calendarDay) && isSelectableDay(calendarDay),
                        'text-brand-green-gray cursor-not-allowed opacity-40': !isSelectableDay(calendarDay),
                    }"
                    :disabled="!isSelectableDay(calendarDay)"
                    @click="selectDay(calendarDay)"
                >
                    {{ calendarDay.day }}
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
