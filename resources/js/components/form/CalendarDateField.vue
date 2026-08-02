<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useAppointmentCalendar } from '@/composables/useAppointmentCalendar';
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        label?: string;
        helpText?: string;
        placeholder?: string;
        error?: string;
        describedBy?: string;
        invalid?: boolean;
        disabled?: boolean;
        minDaysAhead?: number;
        allowPast?: boolean;
        blockWeekends?: boolean;
        inputHeight?: string;
        inputRounded?: string;
        inputClass?: string;
    }>(),
    {
        placeholder: 'TT.MM.JJJJ',
        helpText: '',
        minDaysAhead: 0,
        allowPast: false,
        blockWeekends: false,
        disabled: false,
        inputHeight: 'h-10',
        inputRounded: 'rounded-full',
        inputClass: '',
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const autoId = useId();
const fieldId = computed(() => props.id ?? autoId);
const errorId = computed(() => `${fieldId.value}-error`);
const hasError = computed(() => !!props.error || props.invalid === true);

const describedByIds = computed(() => {
    const ids = [props.describedBy, props.error ? errorId.value : null].filter(Boolean);

    return ids.length ? ids.join(' ') : undefined;
});

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
    <div class="space-y-1">
        <label v-if="label" :for="fieldId" class="text-brand-teal text-sm font-bold">
            {{ label }}

            <span v-if="helpText" class="text-brand-green-gray ml-1 text-[10px] font-normal">
                {{ helpText }}
            </span>
        </label>

        <Popover v-model:open="isOpen">
            <PopoverTrigger as-child>
                <button
                    :id="fieldId"
                    type="button"
                    :disabled="disabled"
                    :aria-invalid="hasError"
                    :aria-describedby="describedByIds"
                    :class="[
                        'border-input text-brand-black focus:border-brand-green flex w-full items-center justify-between border bg-white px-4 text-left text-sm outline-none transition disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400',
                        inputHeight,
                        inputRounded,
                        inputClass,
                        hasError ? 'border-red-400 bg-red-50' : '',
                    ]"
                >
                    <span :class="selectedDateDisplay ? 'text-brand-black' : 'text-brand-green-gray'">
                        {{ selectedDateDisplay || placeholder }}
                    </span>

                    <IconMaterialSymbolsLightCalendarMonthOutline class="text-brand-teal text-lg" />
                </button>
            </PopoverTrigger>

            <PopoverContent align="start">
                <div class="mb-4 flex items-center justify-center gap-2">
                    <div
                        class="border-brand-green-gray text-brand-black flex h-10 w-20 items-center justify-center gap-2 rounded-[6px] border bg-white text-[15px] font-bold"
                    >
                        <span>
                            {{ monthNamesShort[calendarMonth] }}
                        </span>

                        <div class="flex flex-col">
                            <button
                                type="button"
                                class="text-brand-green-gray hover:text-brand-teal leading-none"
                                aria-label="Vorheriger Monat"
                                @click="previousMonth"
                            >
                                <IconMaterialSymbolsLightKeyboardArrowUp class="text-xl" />
                            </button>

                            <button
                                type="button"
                                class="text-brand-green-gray hover:text-brand-teal -mt-2 leading-none"
                                aria-label="Nächster Monat"
                                @click="nextMonth"
                            >
                                <IconMaterialSymbolsLightKeyboardArrowDown class="text-xl" />
                            </button>
                        </div>
                    </div>

                    <div
                        class="border-brand-green-gray text-brand-black flex h-10 w-24 items-center justify-center gap-2 rounded-[6px] border bg-white text-[15px] font-bold"
                    >
                        <span>
                            {{ calendarYear }}
                        </span>

                        <div class="flex flex-col">
                            <button
                                type="button"
                                class="text-brand-green-gray hover:text-brand-teal leading-none"
                                aria-label="Vorheriges Jahr"
                                @click="previousYear"
                            >
                                <IconMaterialSymbolsLightKeyboardArrowUp class="text-xl" />
                            </button>

                            <button
                                type="button"
                                class="text-brand-green-gray hover:text-brand-teal -mt-2 leading-none"
                                aria-label="Nächstes Jahr"
                                @click="nextYear"
                            >
                                <IconMaterialSymbolsLightKeyboardArrowDown class="text-xl" />
                            </button>
                        </div>
                    </div>

                    <button type="button" class="text-brand-green-gray hover:text-brand-teal ml-1" aria-label="Nächster Monat" @click="nextMonth">
                        <IconMaterialSymbolsLightKeyboardArrowRight class="text-3xl" />
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
                            'text-brand-black hover:border-brand-green hover:border': !isSelectedDay(calendarDay) && isSelectableDay(calendarDay),
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

        <p v-if="error" :id="errorId" class="text-[11px] text-red-500">
            {{ error }}
        </p>
    </div>
</template>
