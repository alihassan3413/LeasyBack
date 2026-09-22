<script setup lang="ts">
import FleetOverview from '@/components/b2b/FleetOverview.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import ImportVehiclesModal from '@/components/vehicle/ImportVehiclesModal.vue';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import SortableTableHead from '@/components/vehicle/SortableTableHead.vue';
import VehicleMobileCard from '@/components/vehicle/VehicleMobileCard.vue';
import VehiclePagination, { type PaginationMeta } from '@/components/vehicle/VehiclePagination.vue';
import VehicleRow from '@/components/vehicle/VehicleRow.vue';
import type { MemberFilterOption } from '@/components/vehicle/VehicleToolbar.vue';
import VehicleToolbar from '@/components/vehicle/VehicleToolbar.vue';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import AppLayout from '@/layouts/AppLayout.vue';
import { isVehicleCompleted } from '@/lib/vehicleStatus';
import type { B2bAnalytics } from '@/types/b2b';
import type { StationData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';

export interface VehicleFilters {
    search: string;
    status: string;
    sort: string;
    direction: string;
    /** Company member who registered the vehicle; '' means everyone. */
    created_by: string;

    make: string;
    leasinggeber: string;
    leasing_end: string;
}

const props = defineProps<{
    vehicles: VehicleData[];
    stations: StationData[];
    filters: VehicleFilters;
    pagination: PaginationMeta;
    /** Empty unless the viewer may see the whole company fleet. */
    memberOptions: MemberFilterOption[];
    analytics: B2bAnalytics | null;
}>();

// Every vehicle the viewer owns is on this page, so any notification about
// one of them is about something visible here — no filter.
useLiveUpdates();

const search = ref(props.filters.search);
const status = ref(props.filters.status);
const sort = ref(props.filters.sort);
const direction = ref(props.filters.direction);
const createdBy = ref(props.filters.created_by ?? '');
const make = ref(props.filters.make ?? '');
const leasinggeber = ref(
    props.filters.leasinggeber ?? ''
);
const leasingEnd = ref(
    props.filters.leasing_end ?? ''
);

function reload(page = 1) {
    const sorted = sort.value !== 'created_at' || direction.value !== 'desc';

    router.get(
        route('vehicles.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
            created_by: createdBy.value || undefined,
            sort: sorted ? sort.value : undefined,
            direction: sorted ? direction.value : undefined,
            page: page > 1 ? page : undefined,
            make: make.value || undefined,
            leasinggeber: leasinggeber.value || undefined,
            leasing_end: leasingEnd.value || undefined,
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
watch(
    [
        status,
        sort,
        direction,
        createdBy,
        make,
        leasinggeber,
        leasingEnd
    ],
    () => reload()
);

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

const { can, seesOwnVehiclesOnly, isCompanyUser } = useB2bPermissions();

function latestOrderStatus(vehicle: VehicleData): string | undefined {
    return vehicle.current_order?.order_status;
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

/**
 * The panel is roughly half again as tall as the list's viewport, so a row
 * opened in the lower half used to unfold almost entirely below the fold: the
 * arrow turned, a sliver of panel appeared, and the row read as unresponsive.
 * Collapsing the previous panel made it worse by clamping the scroll container
 * and moving the list under the cursor. Bringing the opened row to the top of
 * the list is what makes the panel it owns the thing you are looking at.
 */
async function handleToggle(vehicle: VehicleData) {
    const opening = expandedId.value !== vehicle.vehicle_id;

    expandedId.value = opening ? vehicle.vehicle_id : null;

    if (!opening) {
        return;
    }

    await nextTick();

    // Both the table row and the mobile card carry the id; only one of them is
    // laid out at any width.
    const row = Array.from(document.querySelectorAll<HTMLElement>(`[data-vehicle-row="${vehicle.vehicle_id}"]`)).find(
        (element) => element.getClientRects().length > 0,
    );

    row?.scrollIntoView({ block: 'start', behavior: 'smooth' });
}

const addVehicleOpen = ref(false);
const importVehiclesOpen = ref(false);

const orderModalOpen = ref(false);
const orderVehicle = ref<VehicleData | null>(null);

function startProcess(vehicle: VehicleData) {
    orderVehicle.value = vehicle;
    orderModalOpen.value = true;
}
</script>

<template>

    <Head title="Fahrzeuge" />

    <AppLayout>
        <template #header>
           <VehicleToolbar
    v-model:search="search"
    v-model:status="status"
    v-model:created-by="createdBy"
    v-model:make="make"
    v-model:leasinggeber="leasinggeber"
    v-model:leasing-end="leasingEnd"
    :member-options="memberOptions"
    @reset="resetFilters"
/>
        </template>

        <div class="flex flex-col">
            <div class="mb-6 flex flex-col gap-4">
                <div class="flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                    <div>
                        <h1 class="text-brand-teal text-[22px] font-semibold md:text-[28px]">Fahrzeuge</h1>
                        <p v-if="seesOwnVehiclesOnly" class="text-muted-foreground mt-1 text-sm">
                            Sie sehen nur die Fahrzeuge, die Sie selbst angelegt haben.
                        </p>
                    </div>

                    <div class="flex w-full flex-col items-stretch gap-3 md:w-auto md:flex-row md:items-center">
                        <!--
                            Import is a company feature only. `can()` returns true for
                            non-Firmenkunde accounts by design, so isCompanyUser is
                            what keeps this off a Privatkunde dashboard — matching the
                            controller, which refuses them with 403.
                        -->
                        <button v-if="isCompanyUser && can('vehicles.create')"
                            class="flex w-full items-center justify-center gap-2 rounded-full border border-[#ef8450] px-4 py-2 font-medium text-[#ef8450] transition-colors hover:bg-[#fff4ee] md:w-auto"
                            @click="importVehiclesOpen = true">
                            <span>Fahrzeuge importieren</span>
                        </button>

                        <button v-if="can('vehicles.create')"
                            class="flex w-full items-center justify-center gap-2 rounded-full px-4 py-2 font-medium text-white md:w-auto"
                            style="background-color: #ef8450" @click="addVehicleOpen = true">
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
                                <SortableTableHead column="license_plate" :sort="sort" :direction="direction"
                                    class="w-[22%] px-4" @sort="toggleSort">
                                    Kennzeichen
                                </SortableTableHead>
                                <SortableTableHead column="make" :sort="sort" :direction="direction"
                                    class="w-[30%] px-4" @sort="toggleSort">
                                    Marke / Modell
                                </SortableTableHead>
                                <SortableTableHead column="leasing_end_date" :sort="sort" :direction="direction"
                                    class="w-[20%] px-4" @sort="toggleSort">
                                    Leasingende
                                </SortableTableHead>
                                <SortableTableHead column="status" :sort="sort" :direction="direction"
                                    class="w-[16%] px-4" @sort="toggleSort">
                                    Status
                                </SortableTableHead>
                                <TableHead class="w-[10%] px-4 text-right text-[13px] font-medium text-white">Optionen
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            <TableRow v-if="!vehicles.length" class="hover:bg-transparent">
                                <TableCell colspan="5" class="px-4 py-10 text-center text-[14px] text-gray-500">
                                    {{ hasQuery ? 'Keine Fahrzeuge gefunden.' : 'Noch keine Fahrzeuge angelegt.' }}
                                </TableCell>
                            </TableRow>

                            <VehicleRow v-for="vehicle in activeVehicles" :key="vehicle.vehicle_id" :vehicle="vehicle"
                                :is-expanded="expandedId === vehicle.vehicle_id" :stations="stations"
                                @toggle="handleToggle(vehicle)" />

                            <TableRow v-if="completedVehicles.length" class="border-0 hover:bg-transparent"
                                style="background-color: #01b990; height: 44px">
                                <TableCell colspan="5" class="h-[44px] px-4 text-[13px] font-bold text-white">
                                    Abgeschlossene
                                    Vorgänge </TableCell>
                            </TableRow>

                            <VehicleRow v-for="vehicle in completedVehicles" :key="vehicle.vehicle_id"
                                :vehicle="vehicle" :is-expanded="expandedId === vehicle.vehicle_id" :stations="stations"
                                @toggle="handleToggle(vehicle)" />
                        </TableBody>
                    </Table>
                </div>

                <div class="space-y-4 md:hidden">
                    <p v-if="!vehicles.length"
                        class="rounded-xl border border-gray-100 bg-white p-6 text-center text-[14px] text-gray-500">
                        {{ hasQuery ? 'Keine Fahrzeuge gefunden.' : 'Noch keine Fahrzeuge angelegt.' }}
                    </p>

                    <VehicleMobileCard v-for="vehicle in activeVehicles" :key="vehicle.vehicle_id" :vehicle="vehicle"
                        :expanded="expandedId === vehicle.vehicle_id" @toggle="handleToggle(vehicle)"
                        @start-process="startProcess(vehicle)" />

                    <div v-if="completedVehicles.length" class="mt-6">
                        <div class="flex items-center gap-2 rounded-lg px-4 py-3" style="background-color: #01b990">
                            <span class="text-[14px] font-bold text-white">Abgeschlossene Vorgänge</span>
                        </div>
                    </div>

                    <!--
                        The same card as above, deliberately. A finished order is
                        still the one place a customer finds their Gutachten and
                        their Rechnung, so it opens exactly like an active one.
                    -->
                    <VehicleMobileCard v-for="vehicle in completedVehicles" :key="vehicle.vehicle_id" :vehicle="vehicle"
                        :expanded="expandedId === vehicle.vehicle_id" @toggle="handleToggle(vehicle)"
                        @start-process="startProcess(vehicle)" />
                </div>

                <VehiclePagination :meta="pagination" @change="goToPage" />
            </div>
        </div>

        <AddVehicleModal v-model:open="addVehicleOpen" :vehicle="null" />
        <ImportVehiclesModal v-model:open="importVehiclesOpen" />
        <OrderCreationModal v-if="orderVehicle" v-model:open="orderModalOpen" :vehicle-id="orderVehicle.vehicle_id"
            :stations="stations" :vehicle="orderVehicle" />
    </AppLayout>
</template>
