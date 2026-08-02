<script setup lang="ts">
import type { CustomerOrderFlowStep } from '@/lib/customerOrderFlow';
import MdiCheck from '~icons/mdi/check';
import MdiClose from '~icons/mdi/close';

defineProps<{ steps: CustomerOrderFlowStep[] }>();
</script>

<template>
    <ol class="relative">
        <li v-for="(step, index) in steps" :key="step.stage" class="relative flex gap-4 pb-6 last:pb-0">
            <span
                v-if="index < steps.length - 1"
                class="absolute top-7 left-[13px] h-[calc(100%-1.25rem)] w-[2px] rounded-full"
                :class="step.completed ? 'bg-[#01B990]' : 'bg-[#e6eded]'"
            ></span>

            <span
                class="relative z-10 mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                :class="
                    step.isCancelled || step.isRejected
                        ? 'border-[#E5533D] bg-[#E5533D] text-white'
                        : step.completed
                          ? 'border-[#01B990] bg-[#01B990] text-white'
                          : step.isCurrent
                            ? 'border-[#01B990] bg-white'
                            : 'border-[#e6eded] bg-white'
                "
            >
                <MdiClose v-if="step.isCancelled || step.isRejected" class="text-[15px]" />
                <MdiCheck v-else-if="step.completed" class="text-[15px]" />
                <span v-else-if="step.isCurrent" class="size-2 rounded-full bg-[#01B990]"></span>
                <span v-else class="size-1.5 rounded-full bg-[#cfdada]"></span>
            </span>

            <div class="min-w-0 flex-1 pt-0.5">
                <p
                    class="text-[13.5px] leading-snug"
                    :class="step.completed || step.isCurrent ? 'font-bold text-[#10393b]' : 'font-medium text-[#9aacac]'"
                >
                    {{ step.label }}
                </p>
                <p v-if="step.subtitle" class="mt-0.5 text-[12px] leading-[1.45] text-[#00000080]">{{ step.subtitle }}</p>
                <p v-if="step.datetime" class="mt-1 text-[11px] text-[#9aacac]">{{ step.datetime }}</p>

                <div v-if="step.reportDocUrl || step.invoiceDocUrl" class="mt-2 flex flex-wrap gap-2">
                    <a
                        v-if="step.reportDocUrl"
                        :href="step.reportDocUrl"
                        target="_blank"
                        rel="noopener"
                        class="rounded-full border border-[#d8e4e3] px-3 py-1 text-[11.5px] font-semibold text-[#10393b] transition hover:border-[#01B990] hover:text-[#01B990]"
                    >
                        Gutachten öffnen
                    </a>
                    <a
                        v-if="step.invoiceDocUrl"
                        :href="step.invoiceDocUrl"
                        target="_blank"
                        rel="noopener"
                        class="rounded-full border border-[#d8e4e3] px-3 py-1 text-[11.5px] font-semibold text-[#10393b] transition hover:border-[#01B990] hover:text-[#01B990]"
                    >
                        Rechnung öffnen
                    </a>
                </div>
            </div>
        </li>
    </ol>
</template>
