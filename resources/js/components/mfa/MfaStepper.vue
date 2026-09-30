<script setup lang="ts">
/**
 * Where the enrolment is and how much is left. Purely informational — the
 * steps are not clickable, because going back would mean re-issuing a secret
 * the server has already tied to this session.
 */
import { cn } from '@/lib/utils';
import MdiCheck from '~icons/mdi/check';

const props = defineProps<{ current: number }>();

const steps = ['Methode', 'Einrichten', 'Bestätigen', 'Notfallcodes'];
</script>

<template>
    <ol class="flex items-center gap-1.5" data-testid="mfa-stepper" :aria-label="`Schritt ${props.current} von ${steps.length}`">
        <li v-for="(label, index) in steps" :key="label" class="flex min-w-0 flex-1 flex-col gap-1.5">
            <span
                :class="
                    cn(
                        'h-1 rounded-full transition-colors motion-reduce:transition-none',
                        index + 1 < props.current ? 'bg-[#01b990]' : index + 1 === props.current ? 'bg-[#01b990]' : 'bg-[#e9efee]',
                    )
                "
                aria-hidden="true"
            />
            <span
                :class="
                    cn(
                        'flex items-center gap-1 truncate text-[10.5px] font-bold tracking-wide uppercase',
                        index + 1 <= props.current ? 'text-[#0b7a63]' : 'text-[#9bb0af]',
                    )
                "
                :aria-current="index + 1 === props.current ? 'step' : undefined"
            >
                <MdiCheck v-if="index + 1 < props.current" class="size-3 shrink-0" aria-hidden="true" />
                <span class="truncate max-[480px]:sr-only">{{ label }}</span>
            </span>
        </li>
    </ol>
</template>
