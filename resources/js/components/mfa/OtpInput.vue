<script setup lang="ts">
/**
 * One box per digit, backed by a single string.
 *
 * Separate inputs rather than one field because that is what a phone keyboard
 * and a password manager both expect: `one-time-code` on the first box lets iOS
 * and Android offer the SMS or mail code, and a paste anywhere in the row fills
 * the whole thing.
 */
import { cn } from '@/lib/utils';
import { computed, nextTick, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        length?: number;
        disabled?: boolean;
        invalid?: boolean;
        autofocus?: boolean;
        label?: string;
    }>(),
    {
        length: 6,
        disabled: false,
        invalid: false,
        autofocus: false,
        label: 'Bestätigungscode',
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'complete', value: string): void;
}>();

const boxes = ref<(HTMLInputElement | null)[]>([]);

const digits = computed(() => {
    const characters = props.modelValue.replace(/\D/g, '').slice(0, props.length).split('');

    return Array.from({ length: props.length }, (_, index) => characters[index] ?? '');
});

function commit(next: string) {
    const cleaned = next.replace(/\D/g, '').slice(0, props.length);

    emit('update:modelValue', cleaned);

    if (cleaned.length === props.length) {
        emit('complete', cleaned);
    }
}

function focusBox(index: number) {
    const target = boxes.value[Math.min(Math.max(index, 0), props.length - 1)];

    target?.focus();
    target?.select();
}

function onInput(index: number, event: Event) {
    const input = event.target as HTMLInputElement;
    // A phone keyboard can deliver more than one character at a time, and the
    // browser's own autofill delivers the entire code into the first box.
    const typed = input.value.replace(/\D/g, '');

    if (typed === '') {
        input.value = digits.value[index] ?? '';

        return;
    }

    const characters = digits.value.slice();

    for (let offset = 0; offset < typed.length && index + offset < props.length; offset++) {
        characters[index + offset] = typed[offset];
    }

    commit(characters.join(''));

    nextTick(() => focusBox(index + typed.length));
}

function onKeydown(index: number, event: KeyboardEvent) {
    if (event.key === 'Backspace') {
        event.preventDefault();

        const characters = digits.value.slice();

        // Backspace in an empty box steps back and clears the one before it,
        // which is what the key does in a single field.
        if (characters[index] === '' && index > 0) {
            characters[index - 1] = '';
            commit(characters.join(''));
            nextTick(() => focusBox(index - 1));

            return;
        }

        characters[index] = '';
        commit(characters.join(''));

        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        focusBox(index - 1);
    }

    if (event.key === 'ArrowRight') {
        event.preventDefault();
        focusBox(index + 1);
    }
}

function onPaste(event: ClipboardEvent) {
    event.preventDefault();

    const pasted = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '');

    if (pasted === '') {
        return;
    }

    commit(pasted);
    nextTick(() => focusBox(Math.min(pasted.length, props.length - 1)));
}

watch(
    () => props.modelValue,
    (value) => {
        if (value === '') {
            nextTick(() => focusBox(0));
        }
    },
);
</script>

<template>
    <div
        role="group"
        :aria-label="label"
        :aria-invalid="invalid || undefined"
        class="flex items-center gap-2 max-[380px]:gap-1.5"
        data-testid="otp-input"
    >
        <input
            v-for="(digit, index) in digits"
            :key="index"
            :ref="(element) => (boxes[index] = element as HTMLInputElement | null)"
            :value="digit"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="1"
            :autocomplete="index === 0 ? 'one-time-code' : 'off'"
            :autofocus="autofocus && index === 0"
            :disabled="disabled"
            :aria-label="`Ziffer ${index + 1} von ${length}`"
            :data-testid="`otp-box-${index}`"
            :class="
                cn(
                    'h-14 w-full min-w-0 rounded-[13px] border bg-white text-center text-[22px] font-extrabold text-[#10393b] tabular-nums transition-colors outline-none',
                    'focus-visible:border-[#01b990] focus-visible:ring-2 focus-visible:ring-[#01b990]/25',
                    'disabled:cursor-not-allowed disabled:bg-[#f6f9f8] disabled:opacity-60',
                    'motion-reduce:transition-none max-[380px]:h-12 max-[380px]:text-[18px]',
                    invalid ? 'border-[#c0392b] bg-[#c0392b]/[0.03]' : digit ? 'border-[#01b990]' : 'border-[#d8e4e2]',
                )
            "
            @input="onInput(index, $event)"
            @keydown="onKeydown(index, $event)"
            @paste="onPaste"
            @focus="($event.target as HTMLInputElement).select()"
        />
    </div>
</template>
