<script setup lang="ts">
import AdminOrderActionsMenu from '@/components/admin/AdminOrderActionsMenu.vue';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import VehicleExpandedPanel from '@/components/vehicle/VehicleExpandedPanel.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ADMIN_ORDER_STATUS_FILTERS, getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import { toVehicleData } from '@/lib/adminVehicle';
import type { AdminVehicleRow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type OwnerType = 'all' | 'Privatkunde' | 'Firmenkunde';

interface VehicleList {
    page: number;
    limit: number;
    total: number;
    total_active: number;
    total_confirmed: number;
    total_inspected: number;
    total_delivered: number;
    data: AdminVehicleRow[];
}

const props = defineProps<{
    vehicles: VehicleList;
    filters: { search: string; status: string; user_type: OwnerType };
    /** Only ever the row currently expanded — see VehicleController::index(). */
    expandedVehicle: AdminVehicleRow | null;
}>();

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const ownerType = ref<OwnerType>(props.filters.user_type);
const loading = ref(false);

const ownerTypeOptions: { label: string; value: OwnerType }[] = [
    { label: 'Alle', value: 'all' },
    { label: 'Privatkunden', value: 'Privatkunde' },
    { label: 'Firmenkunden', value: 'Firmenkunde' },
];

const pageTitle = computed(() => {
    if (ownerType.value === 'Firmenkunde') {
        return 'Firmenkunden Fahrzeuge';
    }

    return ownerType.value === 'Privatkunde' ? 'Privatkunden Fahrzeuge' : 'Alle Fahrzeuge';
});

const page = computed(() => props.vehicles.page);
const totalPages = computed(() => Math.max(1, Math.ceil(props.vehicles.total / props.vehicles.limit)));
const hasQuery = computed(() => search.value !== '' || statusFilter.value !== '');

function reload(overrides: Record<string, string | undefined> = {}) {
    loading.value = true;

    router.get(
        route('admin.vehicles.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            user_type: ownerType.value !== 'all' ? ownerType.value : undefined,
            ...overrides,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['vehicles', 'filters'],
            onFinish: () => (loading.value = false),
        },
    );
}

const debouncedReload = useDebounceFn(() => reload(), 300);

watch(search, debouncedReload);

function clearSearch() {
    search.value = '';
    reload();
}

function setStatusFilter(value: string) {
    if (statusFilter.value === value) {
        return;
    }

    statusFilter.value = value;
    reload();
}

function setOwnerType(value: OwnerType) {
    if (ownerType.value === value) {
        return;
    }

    ownerType.value = value;
    reload();
}

function goToPage(target: number) {
    reload({ page: String(target) });
}

function pageRange(current: number, last: number): (number | '…')[] {
    const out: (number | '…')[] = [];

    for (let index = 1; index <= last; index += 1) {
        if (index === 1 || index === last || Math.abs(index - current) <= 1) {
            out.push(index);
        } else if (out[out.length - 1] !== '…') {
            out.push('…');
        }
    }

    return out;
}

function ownerLabel(vehicle: AdminVehicleRow): string {
    return vehicle.company_name || vehicle.user_email || 'Nicht zugeordnet';
}

function formatGermanDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function openDetail(vehicle: AdminVehicleRow) {
    router.visit(route('admin.vehicles.show', vehicle.vehicle_id));
}

/**
 * Rows expand in place, as in v1's Admin vehicle table. The list itself is
 * deliberately not hydrated for the panel (that would be three extra queries
 * per row), so opening a row asks the server for just that one vehicle via a
 * partial reload of `expandedVehicle`.
 */
const expandedId = ref<string | null>(null);
const expanding = ref(false);

const panelVehicle = computed(() =>
    props.expandedVehicle && props.expandedVehicle.vehicle_id === expandedId.value ? toVehicleData(props.expandedVehicle) : null,
);

function toggleExpand(vehicle: AdminVehicleRow) {
    if (expandedId.value === vehicle.vehicle_id) {
        expandedId.value = null;

        return;
    }

    expandedId.value = vehicle.vehicle_id;

    if (props.expandedVehicle?.vehicle_id === vehicle.vehicle_id) {
        return;
    }

    expanding.value = true;

    router.reload({
        only: ['expandedVehicle'],
        data: { expanded: vehicle.vehicle_id },
        onFinish: () => (expanding.value = false),
    });
}

/** Any filter/page change re-queries the list, which drops whatever row was open. */
watch(
    () => props.vehicles.data,
    () => (expandedId.value = null),
);
</script>

<template>
    <Head title="Fahrzeuge" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <h1 class="shrink-0 text-[16px] font-extrabold tracking-[-0.3px] text-[#10393b]">Fahrzeugverwaltung</h1>

                <div class="admin-search ml-auto">
                    <IconMdiMagnify class="size-4 shrink-0" />

                    <input v-model="search" type="search" placeholder="Kennzeichen, VIN, Marke…" class="admin-search-input" />

                    <button v-if="search" type="button" class="search-clear" title="Suche zurücksetzen" @click="clearSearch">
                        <IconMdiClose class="size-3.5" />
                    </button>
                </div>
            </div>
        </template>

        <div class="flex h-full flex-col gap-5">
            <section
                class="flex min-h-0 flex-1 flex-col rounded-[24px] border border-[#eef3f2] bg-white p-3 sm:p-6"
                style="box-shadow: 0 6px 22px rgba(16, 57, 59, 0.04)"
            >
                <div class="mb-5 flex shrink-0 flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-[20px] font-extrabold tracking-[-0.4px] text-[#10393b]">{{ pageTitle }}</h2>

                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <p class="text-[12px] font-medium text-[#9bb0af]">
                                {{ vehicles.total }} Fahrzeuge{{ hasQuery ? ' gefunden' : ' gesamt' }}
                            </p>
                            <span class="h-[3px] w-[3px] rounded-full bg-[#d3dedd]"></span>
                            <span class="rounded-full bg-[#01B990]/10 px-2.5 py-1 text-[11px] font-bold text-[#00856a]">
                                {{ vehicles.total_active }} Aktiv
                            </span>
                            <span class="rounded-full bg-[#6366f1]/10 px-2.5 py-1 text-[11px] font-bold text-[#4f46e5]">
                                {{ vehicles.total_confirmed }} Bestätigt
                            </span>
                            <span class="rounded-full bg-[#10393b]/[0.08] px-2.5 py-1 text-[11px] font-bold text-[#10393b]">
                                {{ vehicles.total_delivered }} Geliefert
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-0.5 rounded-[12px] bg-[#f4f7f6] p-[3px]">
                        <button
                            v-for="option in ownerTypeOptions"
                            :key="option.value"
                            type="button"
                            class="rounded-[9px] px-4 py-1.5 text-[12.5px] font-bold transition-all"
                            :class="
                                ownerType === option.value
                                    ? 'bg-white text-[#10393b] shadow-[0_1px_5px_rgba(16,57,59,0.1)]'
                                    : 'text-[#6f8585] hover:text-[#10393b]'
                            "
                            @click="setOwnerType(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <div class="mb-4 flex shrink-0 flex-wrap gap-1.5">
                    <button
                        v-for="option in ADMIN_ORDER_STATUS_FILTERS"
                        :key="option.value"
                        type="button"
                        class="rounded-full px-3.5 py-1.5 text-[12px] font-bold transition-all"
                        :class="
                            statusFilter === option.value
                                ? 'bg-[#10393b] text-white shadow-[0_3px_10px_rgba(16,57,59,0.18)]'
                                : 'bg-[#f4f7f6] text-[#6f8585] hover:bg-[#eaf0ef] hover:text-[#10393b]'
                        "
                        @click="setStatusFilter(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto rounded-[18px] border border-[#eef3f2]">
                    <table class="w-full min-w-[860px] border-collapse">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-[#f8faf9]">
                                <th class="admin-th">Fahrzeug</th>
                                <th class="admin-th">Kennzeichen / VIN</th>
                                <th class="admin-th">Kunde</th>
                                <th class="admin-th">Auftragsstatus</th>
                                <th class="admin-th">Leasingende</th>
                                <th class="w-28 border-b border-[#eef3f2]"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-if="loading">
                                <tr v-for="item in 8" :key="item">
                                    <td colspan="6" class="px-5 py-4">
                                        <div class="h-4 animate-pulse rounded-full bg-[#f4f7f6]" :style="{ width: 55 + (item % 5) * 9 + '%' }"></div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-else-if="!vehicles.data.length">
                                <td colspan="6" class="py-16 text-center text-[13px] text-[#9bb0af]">Keine Fahrzeuge gefunden.</td>
                            </tr>

                            <template v-for="vehicle in loading ? [] : vehicles.data" :key="vehicle.vehicle_id">
                                <tr
                                    class="group cursor-pointer border-b border-[#eef3f2] transition-colors hover:bg-[#f6f9f8]"
                                    :class="expandedId === vehicle.vehicle_id ? 'bg-[#f6f9f8]' : ''"
                                    @click="toggleExpand(vehicle)"
                                >
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-[#ef8450]/10 text-[#ef8450]"
                                            >
                                                <IconMdiCarOutline class="size-[17px]" />
                                            </div>

                                            <div class="min-w-0">
                                                <div class="truncate text-[13.5px] font-bold text-[#10393b]">
                                                    {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke' }}
                                                </div>
                                                <div class="mt-0.5 text-[11px] text-[#9bb0af]">
                                                    {{ vehicle.vehicle_belongs === 'B2B' ? 'Firmenkunde' : 'Privatkunde' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-5 py-3.5">
                                        <div class="font-mono text-[13px] font-bold text-[#10393b]">{{ vehicle.license_plate }}</div>
                                        <div class="mt-0.5 truncate font-mono text-[11px] text-[#9bb0af]">{{ vehicle.vin || '—' }}</div>
                                    </td>

                                    <td class="max-w-[220px] truncate px-5 py-3.5 text-[13px] text-[#5a6e6c]">{{ ownerLabel(vehicle) }}</td>

                                    <td class="px-5 py-3.5">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                            :style="{
                                                background: getStatus(vehicle.current_order_status).background,
                                                color: getStatus(vehicle.current_order_status).color,
                                            }"
                                        >
                                            <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                            {{ getStatus(vehicle.current_order_status).label }}
                                        </span>

                                        <div v-if="vehicle.current_auftragsnummer" class="mt-1 truncate font-mono text-[11px] text-[#9bb0af]">
                                            {{ vehicle.current_auftragsnummer }}
                                        </div>
                                    </td>

                                    <td class="px-5 py-3.5 text-[12.5px] text-[#9bb0af] tabular-nums">
                                        {{ formatGermanDate(vehicle.leasing_end_date) }}
                                    </td>

                                    <td class="px-3 py-3.5">
                                        <div class="flex items-center justify-end gap-1" @click.stop>
                                            <AdminOrderActionsMenu
                                                :vehicle-id="vehicle.vehicle_id"
                                                :order-id="vehicle.current_order_id"
                                                :auftragsnummer="vehicle.current_auftragsnummer"
                                                :order-status="vehicle.current_order_status"
                                                :available-transitions="vehicle.current_order_transitions"
                                            >
                                                <template #extra>
                                                    <DropdownMenuItem @select="openDetail(vehicle)">
                                                        <IconMdiOpenInNew />
                                                        Detailseite öffnen
                                                    </DropdownMenuItem>
                                                </template>
                                            </AdminOrderActionsMenu>

                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white"
                                                :title="expandedId === vehicle.vehicle_id ? 'Details ausblenden' : 'Details anzeigen'"
                                                :aria-expanded="expandedId === vehicle.vehicle_id"
                                                @click="toggleExpand(vehicle)"
                                            >
                                                <IconMdiChevronDown
                                                    class="size-[17px] transition-transform duration-200"
                                                    :class="expandedId === vehicle.vehicle_id ? 'rotate-180' : ''"
                                                />
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <tr v-if="expandedId === vehicle.vehicle_id && expanding">
                                    <td colspan="6" class="border-b border-[#eef3f2] bg-[#EFEFEF] p-4">
                                        <div class="flex flex-col gap-3">
                                            <div
                                                v-for="bar in 4"
                                                :key="bar"
                                                class="h-4 animate-pulse rounded-full bg-white"
                                                :style="{ width: 40 + bar * 12 + '%' }"
                                            ></div>
                                        </div>
                                    </td>
                                </tr>

                                <VehicleExpandedPanel v-else-if="expandedId === vehicle.vehicle_id && panelVehicle" :vehicle="panelVehicle" admin />
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex shrink-0 items-center justify-between">
                    <span class="text-[12px] font-medium text-[#9bb0af]">Seite {{ page }} von {{ totalPages }}</span>

                    <div class="flex gap-1">
                        <button type="button" class="lb-pg" :disabled="page <= 1" @click="goToPage(page - 1)">←</button>

                        <button
                            v-for="item in pageRange(page, totalPages)"
                            :key="String(item)"
                            type="button"
                            class="lb-pg"
                            :class="{ 'lb-pg-active': item === page, 'lb-pg-dot': item === '…' }"
                            @click="typeof item === 'number' && goToPage(item)"
                        >
                            {{ item }}
                        </button>

                        <button type="button" class="lb-pg" :disabled="page >= totalPages" @click="goToPage(page + 1)">→</button>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
