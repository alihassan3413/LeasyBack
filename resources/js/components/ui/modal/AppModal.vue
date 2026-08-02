<script setup lang="ts">
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { DialogDescription, DialogTitle } from 'reka-ui';
import MdiClose from '~icons/mdi/close';

withDefaults(
    defineProps<{
        open: boolean;
        title?: string;
        description?: string;
        width?: number;
    }>(),
    {
        title: '',
        description: '',
        width: 720,
    },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

function close() {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="gap-0 overflow-visible rounded-none border-none bg-transparent p-0 shadow-none"
            :style="{ width: '100%', maxWidth: `${width}px` }"
            :show-close-button="false"
        >
            <div class="relative px-3 md:px-0">
                <button
                    type="button"
                    @click="close"
                    class="absolute -top-1 -right-1 z-10 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500 text-white shadow-md transition-colors hover:bg-emerald-600 md:-top-1 md:-right-1 md:h-14 md:w-14"
                >
                    <MdiClose class="size-6 md:size-8" />
                </button>

                <div
                    class="inverted-corner inverted-corner-top-right relative p-3 md:p-5"
                    style="filter: drop-shadow(0 10px 30px rgba(0, 0, 0, 0.15))"
                >
                    <div class="mb-3 px-2 pt-1">
                        <DialogTitle class="pr-8 text-[18px] leading-normal font-bold text-black md:pr-0 md:text-[22px]">
                            {{ title }}
                        </DialogTitle>
                        <DialogDescription
                            v-if="description"
                            class="mt-1 pb-3 text-xs leading-normal font-light text-[#00000080] not-italic md:text-sm"
                        >
                            {{ description }}
                        </DialogDescription>
                    </div>

                    <div class="max-h-[65vh] overflow-x-visible overflow-y-auto">
                        <slot />
                    </div>

                    <div v-if="$slots.footer" class="mt-8 flex justify-center gap-3">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.inverted-corner {
    --r: 38px;
    --s: 32px;
    --x: 0px;
    --y: 0px;
}

.inverted-corner::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    background: #fff;
    border: 1px solid #c6c6cd;
    border-radius: var(--r);
}

.inverted-corner-top-right::before {
    --_m: /calc(2 * var(--r)) calc(2 * var(--r)) radial-gradient(#000 70%, #0000 72%);
    --_g: conic-gradient(at calc(100% - var(--r)) var(--r), #0000 25%, #000 0);
    --_d: (var(--s) + var(--r));

    mask:
        calc(100% - var(--_d) - var(--x)) 0 var(--_m),
        100% calc(var(--_d) + var(--y)) var(--_m),
        radial-gradient(var(--s) at 100% 0, #0000 99%, #000 calc(100% + 1px)) calc(-1 * var(--r) - var(--x)) calc(var(--r) + var(--y)),
        var(--_g) calc(-1 * var(--_d) - var(--x)) 0,
        var(--_g) 0 calc(var(--_d) + var(--y));
    mask-repeat: no-repeat;
}

.inverted-corner-top-left::before {
    --_m: /calc(2 * var(--r)) calc(2 * var(--r)) radial-gradient(#000 70%, #0000 72%);
    --_g: conic-gradient(at var(--r) var(--r), #000 75%, #0000 0);
    --_d: (var(--s) + var(--r));

    mask:
        calc(var(--_d) + var(--x)) 0 var(--_m),
        0 calc(var(--_d) + var(--y)) var(--_m),
        radial-gradient(var(--s) at 0 0, #0000 99%, #000 calc(100% + 1px)) calc(var(--r) + var(--x)) calc(var(--r) + var(--y)),
        var(--_g) calc(var(--_d) + var(--x)) 0,
        var(--_g) 0 calc(var(--_d) + var(--y));
    mask-repeat: no-repeat;
}

.inverted-corner-bottom-right::before {
    --_m: /calc(2 * var(--r)) calc(2 * var(--r)) radial-gradient(#000 70%, #0000 72%);
    --_g: conic-gradient(from 90deg at calc(100% - var(--r)) calc(100% - var(--r)), #0000 25%, #000 0);
    --_d: (var(--s) + var(--r));

    mask:
        calc(100% - var(--_d) - var(--x)) 100% var(--_m),
        100% calc(100% - var(--_d) - var(--y)) var(--_m),
        radial-gradient(var(--s) at 100% 100%, #0000 99%, #000 calc(100% + 1px)) calc(-1 * var(--r) - var(--x)) calc(-1 * var(--r) - var(--y)),
        var(--_g) calc(-1 * var(--_d) - var(--x)) 0,
        var(--_g) 0 calc(-1 * var(--_d) - var(--y));
    mask-repeat: no-repeat;
}

.inverted-corner-bottom-left::before {
    --_m: /calc(2 * var(--r)) calc(2 * var(--r)) radial-gradient(#000 70%, #0000 72%);
    --_g: conic-gradient(from 180deg at var(--r) calc(100% - var(--r)), #0000 25%, #000 0);
    --_d: (var(--s) + var(--r));

    mask:
        calc(var(--_d) + var(--x)) 100% var(--_m),
        0 calc(100% - var(--_d) - var(--y)) var(--_m),
        radial-gradient(var(--s) at 0 100%, #0000 99%, #000 calc(100% + 1px)) calc(var(--r) + var(--x)) calc(-1 * var(--r) - var(--y)),
        var(--_g) calc(var(--_d) + var(--x)) 0,
        var(--_g) 0 calc(-1 * var(--_d) - var(--y));
    mask-repeat: no-repeat;
}
</style>
