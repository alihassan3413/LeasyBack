<script setup lang="ts">
import { TableCell, TableRow } from '@/components/ui/table';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import VehicleExpandedPanel from '@/components/vehicle/VehicleExpandedPanel.vue';
import { canStartNewOrder } from '@/lib/customerOrderFlow';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';
import type { StationData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    vehicle: VehicleData;
    isExpanded: boolean;
    completed: boolean;
    stations: StationData[];
}>();

const emit = defineEmits<{ toggle: [] }>();

const orderModalOpen = ref(false);

const canStartProcess = computed(() => canStartNewOrder(props.vehicle.orders));

const vehicleStatus = computed(() => {
    const current = props.vehicle.orders[0];

    if (!current) {
        return { label: 'Eingeplant', dotColor: '#ef8450' };
    }

    return {
        label: getOrderStatusLabel(current.order_status),
        dotColor: current.order_status === 'cancelled' ? '#EF4444' : '#01B990',
    };
});

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function handleClick() {
    if (props.completed) {
        return;
    }

    emit('toggle');
}

function openDetail() {
    router.visit(route('vehicles.show', props.vehicle.vehicle_id));
}

function startProcess() {
    orderModalOpen.value = true;
}
</script>

<template>
    <TableRow
        class="cursor-pointer border-b border-[#f0f5f5]"
        style="height: 52px"
        :class="isExpanded ? 'bg-gray-50' : 'bg-white'"
        @click="handleClick"
    >
        <TableCell class="h-[52px] truncate px-4 text-[14px] font-medium text-gray-700">
            {{ vehicle.license_plate }}
        </TableCell>
        <TableCell class="h-[52px] truncate px-4 text-[14px] text-gray-600"> {{ vehicle.make }} {{ vehicle.model }} </TableCell>
        <TableCell class="h-[52px] px-4 text-[14px] text-gray-600">
            {{ formatDate(vehicle.leasing_end_date) }}
        </TableCell>
        <TableCell class="h-[52px] px-4">
            <div class="flex items-center gap-2">
                <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: vehicleStatus.dotColor }"></span>
                <span class="text-[14px] text-gray-600">{{ vehicleStatus.label }}</span>
            </div>
        </TableCell>
        <TableCell class="h-[52px] px-4 text-right">
            <div class="flex items-center justify-end gap-1">
                <button
                    class="rounded p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-[#10393b]"
                    title="Details öffnen"
                    aria-label="Details öffnen"
                    @click.stop="openDetail"
                >
                    <IconMdiOpenInNew class="h-[18px] w-[18px]" />
                </button>

                <button v-if="canStartProcess" class="rounded p-1 transition-opacity hover:bg-orange-50 hover:opacity-70" @click.stop="startProcess">
                    <IconSolarPlayBold class="h-5 w-5" style="color: rgb(239, 132, 80)" />
                </button>

                <button class="transition-transform focus:outline-none" :class="isExpanded ? 'rotate-180' : ''">
                    <IconIcRoundArrowDropDown class="text-[32px] text-gray-400 transition-transform duration-200" />
                </button>
            </div>
        </TableCell>
    </TableRow>

    <VehicleExpandedPanel v-if="isExpanded && !completed" :vehicle="vehicle" />

    <OrderCreationModal v-model:open="orderModalOpen" :vehicle-id="vehicle.vehicle_id" :stations="stations" />
</template>
