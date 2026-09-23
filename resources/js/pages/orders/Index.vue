<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPortalDate } from '@/lib/portalDate';
import { BOOKABLE_SERVICE } from '@/lib/services';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { CustomerOrderRow } from '@/types/order';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Every order the customer has placed, across their whole fleet.
 *
 * Its own page rather than a tab of the fleet, because an order outlives the
 * state its vehicle is in: a car that has been through the process twice has
 * two of them, and the fleet row can only ever speak for the current one.
 * Opening a row goes to `orders.show`, which already renders the full record.
 */
const props = defineProps<{
    orders: CustomerOrderRow[];
    filters: { search: string; status: string };
}>();

const search = ref(props.filters.search);
const status = ref(props.filters.status);

const SCOPES = [
    { value: 'open', label: 'Laufend' },
    { value: 'closed', label: 'Abgeschlossen' },
    { value: '', label: 'Alle' },
];

const hasQuery = computed(() => search.value !== '' || status.value !== '');

function reload() {
    router.get(
        route('orders.index'),
        {
            search: search.value || undefined,
            status: status.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['orders', 'filters'] },
    );
}

/* Matches the fleet page: each keystroke re-renders the whole table. */
const debouncedReload = useDebounceFn(() => reload(), 300);

watch(search, () => debouncedReload());
watch(status, () => reload());

function openOrder(order: CustomerOrderRow) {
    router.visit(route('orders.show', order.id));
}

/**
 * A company order is a collection from the customer's own address, a private
 * one an appointment at an inspection station — the column means both.
 */
function appointmentLabel(order: CustomerOrderRow): string {
    return order.appointment ? formatPortalDate(order.appointment) : '—';
}
</script>

<template>
    <Head title="Aufträge" />

    <AppLayout>
        <div class="flex flex-col">
            <header class="mb-5 flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                <div>
                    <h1 class="text-brand-teal text-[22px] font-semibold md:text-[28px]">Aufträge</h1>
                    <p class="text-muted-foreground mt-1 text-sm">Alle gebuchten Leistungen und ihr aktueller Stand.</p>
                </div>
            </header>

            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="border-border inline-flex shrink-0 overflow-hidden rounded-full border">
                    <button
                        v-for="scope in SCOPES"
                        :key="scope.value"
                        type="button"
                        class="px-4 py-2 text-sm font-semibold transition-colors"
                        :class="status === scope.value ? 'bg-brand-teal text-white' : 'hover:bg-muted text-brand-black bg-white'"
                        @click="status = scope.value"
                    >
                        {{ scope.label }}
                    </button>
                </div>

                <div
                    class="focus-within:border-brand-green border-border flex h-10 items-center gap-2 rounded-full border px-4 sm:max-w-[360px] sm:flex-1"
                >
                    <IconMdiMagnify class="text-muted-foreground size-[18px] shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Auftragsnummer, Kennzeichen oder Modell"
                        class="placeholder:text-muted-foreground w-full bg-transparent text-sm outline-none"
                        autocomplete="off"
                        aria-label="Aufträge durchsuchen"
                    />
                </div>
            </div>

            <!-- Desktop: table -->
            <div class="hidden overflow-hidden rounded-[12px] border border-gray-100 shadow-sm md:block">
                <Table>
                    <TableHeader>
                        <TableRow style="background-color: #01b990; height: 44px">
                            <TableHead class="w-[20%] px-4 text-[13px] font-medium text-white">Auftrag</TableHead>
                            <TableHead class="w-[24%] px-4 text-[13px] font-medium text-white">Fahrzeug</TableHead>
                            <TableHead class="w-[18%] px-4 text-[13px] font-medium text-white">Leistung</TableHead>
                            <TableHead class="w-[18%] px-4 text-[13px] font-medium text-white">Termin</TableHead>
                            <TableHead class="w-[20%] px-4 text-right text-[13px] font-medium text-white">Status</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow v-if="!orders.length" class="hover:bg-transparent">
                            <TableCell colspan="5" class="px-4 py-10 text-center text-[14px] text-gray-500">
                                {{ hasQuery ? 'Kein Auftrag gefunden.' : 'Noch keine Aufträge gebucht.' }}
                            </TableCell>
                        </TableRow>

                        <TableRow
                            v-for="order in orders"
                            :key="order.id"
                            class="cursor-pointer border-b border-[#f0f5f5] bg-white"
                            style="height: 56px"
                            @click="openOrder(order)"
                        >
                            <TableCell class="px-4">
                                <span class="text-brand-teal block text-[14px] font-medium">{{ order.auftragsnummer }}</span>
                                <span class="text-muted-foreground block text-[12px]">{{ formatPortalDate(order.created_at) }}</span>
                            </TableCell>
                            <TableCell class="px-4">
                                <span class="block text-[14px] text-gray-700">{{ order.license_plate }}</span>
                                <span class="text-muted-foreground block truncate text-[12px]">
                                    {{ [order.make, order.model].filter(Boolean).join(' ') || '—' }}
                                </span>
                            </TableCell>
                            <TableCell class="px-4 text-[14px] text-gray-600">
                                <span class="block">{{ BOOKABLE_SERVICE.title }}</span>
                                <span v-if="order.location" class="text-muted-foreground block truncate text-[12px]">{{ order.location }}</span>
                            </TableCell>
                            <TableCell class="px-4 text-[14px] text-gray-600">{{ appointmentLabel(order) }}</TableCell>
                            <TableCell class="px-4 text-right">
                                <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                                    {{ getVehicleStatusDisplay(order.order_status).label }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Mobile: cards -->
            <div class="space-y-3 md:hidden">
                <p v-if="!orders.length" class="rounded-xl border border-gray-100 bg-white p-6 text-center text-[14px] text-gray-500">
                    {{ hasQuery ? 'Kein Auftrag gefunden.' : 'Noch keine Aufträge gebucht.' }}
                </p>

                <button
                    v-for="order in orders"
                    :key="order.id"
                    type="button"
                    class="border-border w-full rounded-xl border bg-white p-4 text-left"
                    @click="openOrder(order)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="text-brand-teal block text-[15px] font-semibold">{{ order.license_plate }}</span>
                            <span class="text-muted-foreground block truncate text-[12.5px]">
                                {{ [order.make, order.model].filter(Boolean).join(' ') || '—' }} · {{ BOOKABLE_SERVICE.title }}
                            </span>
                        </div>
                        <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                            {{ getVehicleStatusDisplay(order.order_status).label }}
                        </Badge>
                    </div>

                    <div class="text-muted-foreground mt-3 flex flex-col gap-1 text-[12px]">
                        <span>Auftrag {{ order.auftragsnummer }}</span>
                        <span v-if="order.appointment">Termin {{ appointmentLabel(order) }}</span>
                        <span v-if="order.location">{{ order.location }}</span>
                    </div>
                </button>
            </div>

            <p v-if="!orders.length && !hasQuery" class="text-muted-foreground mt-4 text-[13px]">
                Eine Leistung buchen Sie über
                <Link :href="route('dashboard')" class="text-brand-teal font-semibold hover:underline">Mein Dashboard</Link>.
            </p>
        </div>
    </AppLayout>
</template>
