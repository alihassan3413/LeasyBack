<script setup lang="ts">
/**
 * One vehicle as a card, for the dashboard's narrow layout — the counterpart to
 * VehicleRow.vue's table row.
 *
 * Extracted because the dashboard held two near-copies of this markup, one for
 * active vehicles and one for completed ones, and the completed copy had
 * quietly fallen a long way behind: no status, no chevron, no click handler and
 * no expanded panel, so a finished order could not be opened at all. One card
 * for both lists is what stops that happening again.
 */
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { NEW_ORDER_ACTION_LABEL, newOrderAction } from '@/lib/customerOrderFlow';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';
import type { VehicleData } from '@/types/vehicle';
import { computed } from 'vue';
import VehicleActionsMenu from './VehicleActionsMenu.vue';
import VehicleExpandedPanel from './VehicleExpandedPanel.vue';

const props = defineProps<{ vehicle: VehicleData; expanded: boolean }>();

const emit = defineEmits<{ toggle: []; startProcess: [] }>();

const { can } = useB2bPermissions();

// A company member without orders.create would be refused by the route anyway
// (b2b.can:orders.create) — don't offer the action.
const orderAction = computed(() => (can('orders.create') ? newOrderAction(props.vehicle.orders) : null));

const orderActionLabel = computed(() => (orderAction.value ? NEW_ORDER_ACTION_LABEL[orderAction.value] : ''));

const status = computed(() => {
    const current = props.vehicle.orders[0];

    if (!current) {
        return { label: 'Eingeplant', dotColor: '#ef8450' };
    }

    return {
        label: getOrderStatusLabel(current.order_status, current.payment?.repair_stage),
        dotColor: current.order_status === 'cancelled' ? '#EF4444' : '#01B990',
    };
});

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
        <div class="cursor-pointer p-4" :class="expanded ? 'bg-gray-50' : ''" @click="emit('toggle')">
            <div class="flex items-start justify-between">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <span class="text-[16px] font-bold text-[#10393b]">{{ vehicle.license_plate }}</span>
                    </div>
                    <span class="text-[14px] text-gray-600">{{ vehicle.make }} {{ vehicle.model }}</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: status.dotColor }"></span>
                    <span class="ml-1 text-[12px] text-gray-600">{{ status.label }}</span>
                    <VehicleActionsMenu :vehicle="vehicle" />
                    <button class="transition-transform focus:outline-none" :class="expanded ? 'rotate-180' : ''">
                        <IconIcRoundArrowDropDown class="text-[24px] text-gray-400" />
                    </button>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-3 text-[12px] text-gray-500">
                <div class="flex items-center gap-1">
                    <IconMdiCalendarOutline class="h-4 w-4" />
                    <span>Leasingende: {{ formatDate(vehicle.leasing_end_date) }}</span>
                </div>
            </div>
        </div>

        <VehicleExpandedPanel v-if="expanded" :vehicle="vehicle" />

        <div v-if="orderAction" class="flex items-center justify-between border-t border-gray-100 px-4 py-3">
            <button
                class="flex items-center gap-2 rounded-lg px-3 py-2 font-medium text-white"
                style="background-color: #ef8450"
                @click.stop="emit('startProcess')"
            >
                <IconSolarPlayBold class="h-5 w-5" />
                <span class="text-[14px]">{{ orderActionLabel }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
