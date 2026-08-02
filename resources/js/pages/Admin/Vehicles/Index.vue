<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminVehicleList, AdminVehicleRow } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Car, ClipboardCheck, Clock } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

const props = defineProps<{
    vehicles: AdminVehicleList;
    filters: { status: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Fahrzeuge', href: route('admin.vehicles.index') }];

type Accent = 'teal' | 'green' | 'orange';

const accentStyles: Record<Accent, { fg: string; bg: string }> = {
    teal: { fg: '#10393b', bg: 'rgba(16, 57, 59, 0.08)' },
    green: { fg: '#01875e', bg: 'rgba(1, 185, 144, 0.12)' },
    orange: { fg: '#c0622e', bg: 'rgba(239, 132, 80, 0.14)' },
};

const numberFormat = new Intl.NumberFormat('de-DE');

const stats = computed<{ label: string; description: string; value: number; icon: Component; accent: Accent }[]>(() => [
    { label: 'Fahrzeuge', description: 'Fuhrpark gesamt', value: props.vehicles.total, icon: Car, accent: 'teal' },
    { label: 'Aktiv', description: 'Auftrag in Bearbeitung', value: props.vehicles.total_active, icon: Clock, accent: 'orange' },
    { label: 'Geprüft', description: 'Begutachtung erfolgt', value: props.vehicles.total_inspected, icon: ClipboardCheck, accent: 'green' },
    { label: 'Abgeschlossen', description: 'Ausgeliefert', value: props.vehicles.total_delivered, icon: CheckCircle2, accent: 'green' },
]);

/**
 * Only statuses the backend accepts — AdminQueryService::filters() validates
 * `status` against the OrderStatus enum, so an "Eingeplant"/none option (a
 * vehicle with no order yet) cannot be expressed here and is deliberately
 * absent.
 */
const statusOptions: { label: string; value: string }[] = [
    { label: 'Alle', value: '' },
    { label: 'Bestellt', value: 'order_placed' },
    { label: 'Bestätigt', value: 'confirmed' },
    { label: 'Geprüft', value: 'inspected' },
    { label: 'In der Werkstatt', value: 'workshop' },
    { label: 'Abgeschlossen', value: 'delivered' },
];

const activeStatus = computed(() => props.filters.status ?? '');

/** Brand status colors, mirroring leasyback_web's VehiclesView palette. */
const statusColors: Record<string, { bg: string; fg: string }> = {
    order_requested: { bg: 'rgba(59, 130, 246, 0.1)', fg: '#3b82f6' },
    order_placed: { bg: 'rgba(239, 132, 80, 0.12)', fg: '#c0622e' },
    confirmed: { bg: 'rgba(99, 102, 241, 0.12)', fg: '#4f46e5' },
    inspected: { bg: 'rgba(1, 185, 144, 0.12)', fg: '#00856a' },
    workshop: { bg: 'rgba(245, 158, 11, 0.14)', fg: '#b45309' },
    reinspection: { bg: 'rgba(124, 58, 237, 0.12)', fg: '#6d28d9' },
    reworkshop: { bg: 'rgba(234, 88, 12, 0.12)', fg: '#c2410c' },
    delivered: { bg: 'rgba(16, 57, 59, 0.09)', fg: '#10393b' },
    discarded: { bg: 'rgba(107, 114, 128, 0.12)', fg: '#374151' },
    cancelled: { bg: 'rgba(220, 38, 38, 0.1)', fg: '#991b1b' },
};

const notStartedColor = { bg: 'rgba(245, 158, 11, 0.14)', fg: '#b45309' };

function statusStyle(status: string | null): { bg: string; fg: string } {
    if (!status) {
        return notStartedColor;
    }
    return statusColors[status] ?? { bg: 'rgba(0, 0, 0, 0.05)', fg: '#6f8585' };
}

function ownerRoute(vehicle: AdminVehicleRow): string | null {
    if (vehicle.vehicle_belongs === 'B2C' && vehicle.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: vehicle.user_id });
    }
    if (vehicle.vehicle_belongs === 'B2B' && vehicle.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: vehicle.b2b_id });
    }
    return null;
}

function ownerLabel(vehicle: AdminVehicleRow): string {
    if (vehicle.vehicle_belongs === 'B2B') {
        return vehicle.company_name ?? '—';
    }
    return vehicle.user_email ?? '—';
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    const date = new Date(value);
    // Falls back to a dash rather than rendering "Invalid Date" — leasing
    // dates come straight from the DB and are not guaranteed well-formed.
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE');
}

const totalPages = computed(() => Math.max(1, Math.ceil(props.vehicles.total / props.vehicles.limit)));

const visiblePages = computed(() => {
    const pages: number[] = [];
    const current = props.vehicles.page;
    const start = Math.max(1, current - 2);
    const end = Math.min(totalPages.value, current + 2);
    for (let i = start; i <= end; i += 1) {
        pages.push(i);
    }
    if (start > 1) {
        pages.unshift(1);
    }
    if (start > 2) {
        pages.splice(1, 0, -1);
    }
    if (end < totalPages.value) {
        pages.push(-1);
    }
    if (end < totalPages.value - 1) {
        pages.splice(pages.length - 1, 0, -1);
    }
    return pages;
});

function reload(params: Record<string, string | number>) {
    router.get(route('admin.vehicles.index'), params, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(status: string) {
    reload(status ? { status } : {});
}

function goToPage(page: number) {
    if (page < 1 || page > totalPages.value) {
        return;
    }
    const params: Record<string, string | number> = { page };
    if (activeStatus.value) {
        params.status = activeStatus.value;
    }
    reload(params);
}
</script>

<template>
    <Head title="Fahrzeuge" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <header class="rounded-[24px] border border-[#e8f1ef] bg-white p-6 shadow-[0_8px_30px_rgba(16,57,59,0.08)] md:p-8">
                <p class="text-[12px] font-bold tracking-[0.2em] text-[#01b990] uppercase">Fahrzeuge</p>
                <h1 class="mt-2 text-[28px] font-extrabold text-[#10393b] md:text-[32px]">Fuhrpark verwalten</h1>
                <p class="mt-2 max-w-2xl text-sm text-[#5a6e6c] md:text-base">Alle Fahrzeuge aus Privat- und Firmenkundenbestand.</p>
            </header>

            <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="rounded-[24px] border border-[#eef3f2] bg-white p-6 shadow-[0_10px_28px_rgba(16,57,59,0.06)]"
                >
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-[12px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">{{ stat.label }}</p>
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[14px]"
                            :style="{ backgroundColor: accentStyles[stat.accent].bg, color: accentStyles[stat.accent].fg }"
                        >
                            <component :is="stat.icon" :size="20" :stroke-width="2" />
                        </span>
                    </div>
                    <div class="mt-5 text-[34px] leading-none font-extrabold text-[#10393b]">{{ numberFormat.format(stat.value) }}</div>
                    <p class="mt-2 text-sm text-[#6f8585]">{{ stat.description }}</p>
                </div>
            </section>

            <section class="rounded-[24px] border border-[#eef3f2] bg-white p-6 shadow-[0_10px_28px_rgba(16,57,59,0.06)]">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        class="rounded-full border px-4 py-2 text-sm font-semibold transition"
                        :class="
                            activeStatus === option.value
                                ? 'border-[#10393b] bg-[#10393b] text-white'
                                : 'border-[#d9e2e2] bg-white text-[#334155] hover:bg-[#f4f7f6]'
                        "
                        @click="setStatus(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </section>

            <section class="rounded-[24px] border border-[#eef3f2] bg-white p-6 shadow-[0_10px_28px_rgba(16,57,59,0.06)]">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left">
                        <thead>
                            <tr class="border-b border-[#eef3f2]">
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Kennzeichen</th>
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Marke · Modell</th>
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Halter</th>
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Leasingende</th>
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Status</th>
                                <th class="px-4 py-3 text-[11px] font-semibold tracking-[0.14em] text-[#5a6e6c] uppercase">Erstellt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#eef3f2]">
                            <tr v-if="vehicles.data.length === 0">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-[#6f8585]">Keine Fahrzeuge gefunden.</td>
                            </tr>
                            <tr v-for="vehicle in vehicles.data" :key="vehicle.vehicle_id" class="transition-colors hover:bg-[#f7faf8]">
                                <td class="px-4 py-4">
                                    <Link
                                        :href="route('admin.vehicles.show', vehicle.vehicle_id)"
                                        class="font-semibold text-[#10393b] hover:text-[#01875e]"
                                    >
                                        {{ vehicle.license_plate }}
                                    </Link>
                                    <div class="mt-0.5 text-[12px] text-[#9fb0af]">VIN {{ vehicle.vin ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-4 text-sm text-[#3f5352]">
                                    {{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <Link v-if="ownerRoute(vehicle)" :href="ownerRoute(vehicle)!" class="text-[#3f5352] hover:text-[#01875e]">
                                        {{ ownerLabel(vehicle) }}
                                    </Link>
                                    <span v-else class="text-[#3f5352]">{{ ownerLabel(vehicle) }}</span>
                                    <div class="mt-0.5 text-[12px] text-[#9fb0af]">
                                        {{ vehicle.vehicle_belongs === 'B2B' ? 'Firmenkunde' : 'Privatkunde' }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-sm text-[#6f8585]">{{ formatDate(vehicle.leasing_end_date) }}</td>
                                <td class="px-4 py-4">
                                    <span
                                        class="inline-flex items-center rounded-full px-3 py-1 text-[12px] font-semibold"
                                        :style="{
                                            backgroundColor: statusStyle(vehicle.current_order_status).bg,
                                            color: statusStyle(vehicle.current_order_status).fg,
                                        }"
                                    >
                                        {{ getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).label }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-sm text-[#6f8585]">{{ formatDate(vehicle.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-[#6f8585]">Seite {{ vehicles.page }} von {{ totalPages }} · {{ numberFormat.format(vehicles.total) }} Fahrzeuge</p>

                    <div v-if="totalPages > 1" class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-full border border-[#d9e2e2] bg-white px-3 py-2 text-sm font-semibold text-[#334155] transition hover:bg-[#f4f7f6] disabled:opacity-40"
                            :disabled="vehicles.page <= 1"
                            @click="goToPage(vehicles.page - 1)"
                        >
                            Zurück
                        </button>

                        <template v-for="(pageItem, index) in visiblePages" :key="`${pageItem}-${index}`">
                            <button
                                v-if="pageItem > 0"
                                type="button"
                                class="rounded-full px-3 py-2 text-sm font-semibold transition"
                                :class="
                                    pageItem === vehicles.page
                                        ? 'bg-[#01b990] text-white shadow-[0_8px_20px_rgba(1,185,144,0.18)]'
                                        : 'bg-white text-[#334155] hover:bg-[#f4f7f6]'
                                "
                                @click="goToPage(pageItem)"
                            >
                                {{ pageItem }}
                            </button>
                            <span v-else class="px-2 text-sm font-semibold text-[#94a3b8]">…</span>
                        </template>

                        <button
                            type="button"
                            class="rounded-full border border-[#d9e2e2] bg-white px-3 py-2 text-sm font-semibold text-[#334155] transition hover:bg-[#f4f7f6] disabled:opacity-40"
                            :disabled="vehicles.page >= totalPages"
                            @click="goToPage(vehicles.page + 1)"
                        >
                            Weiter
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
