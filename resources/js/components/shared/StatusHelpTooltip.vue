<script setup lang="ts">
import { cn } from '@/lib/utils';
import { TooltipArrow, TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export interface StatusHelpTooltipProps {
    text: string;
    label?: string;
    placement?: 'top' | 'bottom' | 'left' | 'right';
    disabled?: boolean;
}

const props = withDefaults(defineProps<StatusHelpTooltipProps>(), {
    label: 'Statusbeschreibung anzeigen',
    placement: 'top',
    disabled: false,
});

const isDisabled = computed(() => props.disabled || props.text.trim().length === 0);

const isOpen = ref(false);

let activeInstanceClose: (() => void) | null = null;

function closeThisInstance() {
    isOpen.value = false;
}

watch(isOpen, (open) => {
    if (open) {
        if (activeInstanceClose && activeInstanceClose !== closeThisInstance) {
            activeInstanceClose();
        }
        activeInstanceClose = closeThisInstance;
    } else if (activeInstanceClose === closeThisInstance) {
        activeInstanceClose = null;
    }
});

onBeforeUnmount(() => {
    if (activeInstanceClose === closeThisInstance) {
        activeInstanceClose = null;
    }
});

function handleTriggerClick() {
    if (isDisabled.value) {
        return;
    }

    isOpen.value = true;
}
</script>

<template>
    <TooltipProvider :delay-duration="150" :disable-hoverable-content="true">
        <TooltipRoot v-model:open="isOpen" :disabled="isDisabled" :disable-closing-trigger="true">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :disabled="isDisabled"
                    :aria-label="label"
                    :aria-expanded="isOpen"
                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-[#8a9a9a] outline-none transition-colors hover:bg-[#01b990]/10 hover:text-[#01b990] focus-visible:ring-2 focus-visible:ring-[#01b990]/50 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-40 data-[state=open]:bg-[#01b990]/10 data-[state=open]:text-[#01b990]"
                    @click="handleTriggerClick"
                >
                    <IconMdiHelpCircleOutline class="size-4" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side="placement"
                    :side-offset="8"
                    :collision-padding="12"
                    :aria-label="text"
                    :class="
                        cn(
                            'bg-popover text-popover-foreground border-border z-[70] max-w-64 rounded-lg border px-3 py-2 text-sm leading-relaxed break-words shadow-lg outline-none',
                            'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95',
                            'data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2',
                            'motion-reduce:animate-none motion-reduce:transition-none',
                        )
                    "
                >
                    {{ text }}
                    <TooltipArrow class="fill-popover" :width="10" :height="5" />
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
