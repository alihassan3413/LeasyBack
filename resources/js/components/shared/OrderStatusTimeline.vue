<script setup lang="ts">
import StatusHelpTooltip from '@/components/shared/StatusHelpTooltip.vue';
import { providerDisplayLabel, timelineDotStyle, timelineLineStyle, type OrderTimelineEntry } from '@/lib/timeline';

const props = withDefaults(
    defineProps<{
        entries: OrderTimelineEntry[];
        headerLabel?: string;
        headerTooltipDescription?: string;
        headerClass?: string;
        bodyClass?: string;
        transformProviderLabel?: boolean;
    }>(),
    {
        headerLabel: undefined,
        headerTooltipDescription: undefined,
        headerClass: 'px-6 pt-6 pb-5 flex items-center justify-between',
        bodyClass: 'flex-1 px-6 pb-5',
        transformProviderLabel: true,
    },
);

function isProviderEntry(entry: OrderTimelineEntry): boolean {
    const key = entry.label.toLowerCase();

    return key === 'dekra' || key === 'tuvsud';
}

function providerLabel(entry: OrderTimelineEntry): string {
    return props.transformProviderLabel ? providerDisplayLabel(entry.label) : entry.label;
}
</script>

<template>
    <div v-if="headerLabel" :class="headerClass">
        <p class="flex items-center gap-1.5 text-[16px] leading-tight font-bold text-[#000000] uppercase">
            {{ headerLabel }}
            <StatusHelpTooltip v-if="headerTooltipDescription" :text="headerTooltipDescription" />
        </p>
    </div>

    <div :class="bodyClass">
        <div v-for="(entry, i) in entries" :key="i" class="relative flex items-start pb-6">
            <div v-if="i < entries.length - 1" class="absolute top-5 left-2 h-full w-0.5" :style="timelineLineStyle(entry)" />

            <div class="relative z-10 mt-1 h-4 w-4 shrink-0 rounded-full border-2" :style="timelineDotStyle(entry)" />

            <div class="min-w-0 flex-1 pl-5">
                <p v-if="entry.datetime" class="mb-1 text-[14px] font-medium text-[#2e3e3f]">
                    {{ entry.datetime }}
                </p>

                <template v-if="isProviderEntry(entry)">
                    <p class="mb-1 text-[16px] font-bold" style="color: #01b990">
                        {{ providerLabel(entry) }}
                    </p>
                    <p v-if="entry.sublabel" class="text-[14px] font-normal whitespace-pre-line text-[#2e3e3f]">
                        {{ entry.sublabel }}
                    </p>
                </template>

                <template v-else>
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="flex items-center gap-1.5 text-[14px] font-normal"
                                :class="
                                    entry.isCancelled || entry.isRejected ? 'text-[#dc2626]' : entry.isFuture ? 'text-[#8f9ba7]' : 'text-[#2e3e3f]'
                                "
                            >
                                {{ entry.label }}
                                <StatusHelpTooltip v-if="entry.tooltipDescription" :text="entry.tooltipDescription" />
                            </p>
                            <span
                                v-if="entry.isCancelled"
                                class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: rgba(220, 38, 38, 0.1); color: #dc2626"
                            >
                                Storniert
                            </span>
                            <span
                                v-else-if="entry.isRejected"
                                class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: rgba(220, 38, 38, 0.1); color: #dc2626"
                            >
                                Abgelehnt
                            </span>
                            <span
                                v-else-if="entry.isCurrent"
                                class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: rgba(1, 185, 144, 0.15); color: #01b990"
                            >
                                Aktueller Status
                            </span>
                            <span
                                v-else-if="entry.isNext"
                                class="mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: rgba(1, 185, 144, 0.1); color: #01b990"
                            >
                                Nächster Schritt
                            </span>
                            <p v-if="entry.sublabel" class="text-[14px] font-normal whitespace-pre-line text-[#2e3e3f]">
                                {{ entry.sublabel }}
                            </p>
                        </div>
                        <div v-if="entry.isReport && (entry.docUrl || entry.invoiceUrl || entry.showPaymentAction)" class="flex items-center gap-2">
                            <slot name="actions" :entry="entry" />
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
