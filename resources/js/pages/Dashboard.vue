<script setup lang="ts">
import OnboardingModal from '@/components/dashboard/OnboardingModal.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import SortableTableHead from '@/components/vehicle/SortableTableHead.vue';
import VehicleExpandedPanel from '@/components/vehicle/VehicleExpandedPanel.vue';
import VehiclePagination, { type PaginationMeta } from '@/components/vehicle/VehiclePagination.vue';
import VehicleRow from '@/components/vehicle/VehicleRow.vue';
import VehicleToolbar from '@/components/vehicle/VehicleToolbar.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import AppLayout from '@/layouts/AppLayout.vue';
import { canStartNewOrder } from '@/lib/customerOrderFlow';
import { ONBOARDING_VIDEO_POSTER_URL, ONBOARDING_VIDEO_URL } from '@/lib/onboarding';
import { getOrderStatusLabel, isVehicleCompleted } from '@/lib/vehicleStatus';
import FleetOverview from '@/components/b2b/FleetOverview.vue';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { type SharedData } from '@/types';
import type { B2bAnalytics } from '@/types/b2b';
import type { MemberFilterOption } from '@/components/vehicle/VehicleToolbar.vue';
import type { StationData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

export interface DashboardFilters {
    search: string;
    status: string;
    sort: string;
    direction: string;
    /** Company member who registered the vehicle; '' means everyone. */
    created_by: string;
}

const props = defineProps<{
    vehicles: VehicleData[];
    stations: StationData[];
    filters: DashboardFilters;
    pagination: PaginationMeta;
    /** Empty unless the viewer may see the whole company fleet. */
    memberOptions: MemberFilterOption[];
    analytics: B2bAnalytics | null;
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const sort = ref(props.filters.sort);
const direction = ref(props.filters.direction);
const createdBy = ref(props.filters.created_by ?? '');

function reload(page = 1) {
    const sorted = sort.value !== 'created_at' || direction.value !== 'desc';

    router.get(
        route('dashboard'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            created_by: createdBy.value || undefined,
            sort: sorted ? sort.value : undefined,
            direction: sorted ? direction.value : undefined,
            page: page > 1 ? page : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['vehicles', 'filters', 'pagination', 'analytics'] },
    );
}

function goToPage(page: number) {
    expandedId.value = null;
    reload(page);
}

const debouncedReload = useDebounceFn(() => reload(), 300);

watch(search, () => debouncedReload());
watch([status, sort, direction, createdBy], () => reload());

function toggleSort(column: string) {
    if (sort.value === column) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    sort.value = column;
    direction.value = 'asc';
}

function resetFilters() {
    status.value = '';
    createdBy.value = '';
}

const hasQuery = computed(() => search.value !== '' || status.value !== '' || createdBy.value !== '');

const { can, seesOwnVehiclesOnly } = useB2bPermissions();

function latestOrderStatus(vehicle: VehicleData): string | undefined {
    return vehicle.orders[0]?.order_status;
}

const activeVehicles = computed(() => props.vehicles.filter((vehicle) => !isVehicleCompleted(latestOrderStatus(vehicle))));
const completedVehicles = computed(() => props.vehicles.filter((vehicle) => isVehicleCompleted(latestOrderStatus(vehicle))));

const expandedId = ref<string | null>(null);

watch(
    activeVehicles,
    (vehicles) => {
        if (vehicles.length > 0 && !expandedId.value) {
            expandedId.value = vehicles[0].vehicle_id;
        }
    },
    { immediate: true },
);

function handleToggle(vehicle: VehicleData) {
    expandedId.value = expandedId.value === vehicle.vehicle_id ? null : vehicle.vehicle_id;
}

const addVehicleOpen = ref(false);

const orderModalOpen = ref(false);
const orderVehicleId = ref<string | null>(null);

function startProcess(vehicle: VehicleData) {
    orderVehicleId.value = vehicle.vehicle_id;
    orderModalOpen.value = true;
}

function getVehicleStatus(vehicle: VehicleData) {
    const current = vehicle.orders[0];

    if (!current) {
        return { label: 'Eingeplant', dotColor: '#ef8450' };
    }

    return {
        label: getOrderStatusLabel(current.order_status),
        dotColor: current.order_status === 'cancelled' ? '#EF4444' : '#01B990',
    };
}

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

const page = usePage<SharedData>();

const {
    isOpen: onboardingOpen,
    maybeShow: maybeShowOnboarding,
    open: openOnboarding,
    dismiss: dismissOnboarding,
} = useOnboarding(() => page.props.auth.user?.email);

function onOnboardingOpenChange(value: boolean) {
    if (!value) {
        dismissOnboarding();
    }
}

onMounted(() => {
    if (page.props.auth.user?.user_type === 'Privatkunde') {
        maybeShowOnboarding();
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <template #header>
            <VehicleToolbar
                v-model:search="search"
                v-model:status="status"
                v-model:created-by="createdBy"
                :member-options="memberOptions"
                @reset="resetFilters"
            />
        </template>

        <div class="flex flex-col">
            <div class="mb-6 flex flex-col gap-4">
                <div class="flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                    <div>
                        <h1 class="text-[22px] font-semibold text-[#10393b] md:text-[28px]">Mein Dashboard</h1>
                        <p v-if="seesOwnVehiclesOnly" class="mt-1 text-sm text-[#6f8585]">
                            Sie sehen nur die Fahrzeuge, die Sie selbst angelegt haben.
                        </p>
                    </div>

                    <div class="flex w-full flex-col items-stretch gap-3 md:w-auto md:items-end">
                        <button
                            v-if="can('vehicles.create')"
                            class="flex w-full items-center justify-center gap-2 rounded-full px-4 py-2 font-medium text-white md:w-auto"
                            style="background-color: #ef8450"
                            @click="addVehicleOpen = true"
                        >
                            <IconIcBaselinePlus class="h-5 w-5" />
                            <span>Neues Fahrzeug anlegen</span>
                        </button>
                    </div>
                </div>

                <FleetOverview v-if="analytics" :analytics="analytics" :active-filter="status" />
            </div>

            <div>
                <div class="hidden overflow-hidden rounded-[12px] border border-gray-100 shadow-sm md:block">
                    <Table>
                        <TableHeader>
                            <TableRow style="background-color: #01b990; height: 44px">
                                <SortableTableHead column="license_plate" :sort="sort" :direction="direction" class="w-[22%] px-4" @sort="toggleSort">
                                    Kennzeichen
                                </SortableTableHead>
                                <SortableTableHead column="make" :sort="sort" :direction="direction" class="w-[30%] px-4" @sort="toggleSort">
                                    Marke / Modell
                                </SortableTableHead>
                                <SortableTableHead
                                    column="leasing_end_date"
                                    :sort="sort"
                                    :direction="direction"
                                    class="w-[20%] px-4"
                                    @sort="toggleSort"
                                >
                                    Leasingende
                                </SortableTableHead>
                                <SortableTableHead column="status" :sort="sort" :direction="direction" class="w-[16%] px-4" @sort="toggleSort">
                                    Status
                                </SortableTableHead>
                                <TableHead class="w-[10%] px-4 text-right text-[13px] font-medium text-white">Optionen</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            <TableRow v-if="!vehicles.length" class="hover:bg-transparent">
                                <TableCell colspan="5" class="px-4 py-10 text-center text-[14px] text-gray-500">
                                    {{ hasQuery ? 'Keine Fahrzeuge gefunden.' : 'Noch keine Fahrzeuge angelegt.' }}
                                </TableCell>
                            </TableRow>

                            <VehicleRow
                                v-for="vehicle in activeVehicles"
                                :key="vehicle.vehicle_id"
                                :vehicle="vehicle"
                                :is-expanded="expandedId === vehicle.vehicle_id"
                                :completed="false"
                                :stations="stations"
                                @toggle="handleToggle(vehicle)"
                            />

                            <TableRow
                                v-if="completedVehicles.length"
                                class="border-0 hover:bg-transparent"
                                style="background-color: #01b990; height: 44px"
                            >
                                <TableCell colspan="5" class="h-[44px] px-4 text-[13px] font-bold text-white"> Abgeschlossene Vorgänge </TableCell>
                            </TableRow>

                            <VehicleRow
                                v-for="vehicle in completedVehicles"
                                :key="vehicle.vehicle_id"
                                :vehicle="vehicle"
                                :is-expanded="expandedId === vehicle.vehicle_id"
                                :completed="true"
                                :stations="stations"
                                @toggle="handleToggle(vehicle)"
                            />
                        </TableBody>
                    </Table>
                </div>

                <div class="space-y-4 md:hidden">
                    <p v-if="!vehicles.length" class="rounded-xl border border-gray-100 bg-white p-6 text-center text-[14px] text-gray-500">
                        {{ hasQuery ? 'Keine Fahrzeuge gefunden.' : 'Noch keine Fahrzeuge angelegt.' }}
                    </p>

                    <div
                        v-for="vehicle in activeVehicles"
                        :key="vehicle.vehicle_id"
                        class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm"
                    >
                        <div class="cursor-pointer p-4" :class="expandedId === vehicle.vehicle_id ? 'bg-gray-50' : ''" @click="handleToggle(vehicle)">
                            <div class="flex items-start justify-between">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-[16px] font-bold text-[#10393b]">{{ vehicle.license_plate }}</span>
                                    </div>
                                    <span class="text-[14px] text-gray-600">{{ vehicle.make }} {{ vehicle.model }}</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="h-3 w-3 rounded-full" :style="{ backgroundColor: getVehicleStatus(vehicle).dotColor }"></span>
                                    <span class="ml-1 text-[12px] text-gray-600">{{ getVehicleStatus(vehicle).label }}</span>
                                    <button
                                        class="ml-1 transition-transform focus:outline-none"
                                        :class="expandedId === vehicle.vehicle_id ? 'rotate-180' : ''"
                                    >
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

                        <VehicleExpandedPanel v-if="expandedId === vehicle.vehicle_id" :vehicle="vehicle" />

                        <div
                            v-if="canStartNewOrder(vehicle.orders) && can('orders.create')"
                            class="flex items-center justify-between border-t border-gray-100 px-4 py-3"
                        >
                            <button
                                class="flex items-center gap-2 rounded-lg px-3 py-2 font-medium text-white"
                                style="background-color: #ef8450"
                                @click.stop="startProcess(vehicle)"
                            >
                                <IconSolarPlayBold class="h-5 w-5" />
                                <span class="text-[14px]">Vorgang starten</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="completedVehicles.length" class="mt-6">
                        <div class="flex items-center gap-2 rounded-lg px-4 py-3" style="background-color: #01b990">
                            <span class="text-[14px] font-bold text-white">Abgeschlossene Vorgänge</span>
                        </div>
                    </div>

                    <div
                        v-for="vehicle in completedVehicles"
                        :key="vehicle.vehicle_id"
                        class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm"
                    >
                        <div class="cursor-pointer p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex flex-col gap-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-[16px] font-bold text-[#10393b]">{{ vehicle.license_plate }}</span>
                                    </div>
                                    <span class="text-[14px] text-gray-600">{{ vehicle.make }} {{ vehicle.model }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <VehiclePagination :meta="pagination" @change="goToPage" />
            </div>
        </div>

        <button
            type="button"
            aria-label="Einführung ansehen"
            title="Einführung ansehen"
            class="fixed right-4 bottom-20 z-[60] flex items-center gap-2 rounded-full py-2.5 pr-4 pl-2.5 text-white shadow-lg transition-all duration-200 hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-[#01b990]/40 md:right-8 md:bottom-8"
            style="background-color: #10393b"
            @click="openOnboarding"
        >
            <span class="flex h-6 w-6 items-center justify-center rounded-full" style="background-color: #01b990">
                <IconMdiPlay class="h-4 w-4" />
            </span>
            <span class="text-sm font-medium">Einführung</span>
        </button>

        <AddVehicleModal v-model:open="addVehicleOpen" :vehicle="null" />
        <OrderCreationModal v-if="orderVehicleId" v-model:open="orderModalOpen" :vehicle-id="orderVehicleId" :stations="stations" />
        <OnboardingModal
            :open="onboardingOpen"
            :video-url="ONBOARDING_VIDEO_URL"
            :poster-url="ONBOARDING_VIDEO_POSTER_URL"
            @update:open="onOnboardingOpenChange"
        />
    </AppLayout>
</template>
