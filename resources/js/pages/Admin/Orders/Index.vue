<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ADMIN_ORDER_STATUS_FILTERS, getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import type { AdminOrderList, AdminOrderRow } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    orders: AdminOrderList;
    filters: { search: string; status: string };
}>();

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const loading = ref(false);

const page = computed(() => props.orders.page);
const totalPages = computed(() => Math.max(1, Math.ceil(props.orders.total / props.orders.limit)));
const hasQuery = computed(() => search.value !== '' || statusFilter.value !== '');

function reload(overrides: Record<string, string | undefined> = {}) {
    loading.value = true;

    router.get(
        route('admin.orders.index'),
        {
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            ...overrides,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['orders', 'filters'],
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

function ownerLabel(order: AdminOrderRow): string {
    return order.company_name || order.user_email || 'Nicht zugeordnet';
}

function vehicleTitle(order: AdminOrderRow): string {
    return [order.make, order.model].filter(Boolean).join(' ') || 'Ohne Marke';
}

function formatGermanDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function openDetail(order: AdminOrderRow) {
    router.visit(route('admin.orders.show', order.id));
}
</script>

<template>
    <Head title="Aufträge" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <h1 class="shrink-0 text-[16px] font-extrabold tracking-[-0.3px] text-[#10393b]">Auftragsverwaltung</h1>

                <div class="admin-search ml-auto">
                    <IconMdiMagnify class="size-4 shrink-0" />

                    <input v-model="search" type="search" placeholder="Auftragsnummer, Kennzeichen…" class="admin-search-input" />

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
                        <h2 class="text-[20px] font-extrabold tracking-[-0.4px] text-[#10393b]">Alle Aufträge</h2>

                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <p class="text-[12px] font-medium text-[#9bb0af]">{{ orders.total }} Aufträge{{ hasQuery ? ' gefunden' : ' gesamt' }}</p>
                            <span class="h-[3px] w-[3px] rounded-full bg-[#d3dedd]"></span>
                            <span class="rounded-full bg-[#01B990]/10 px-2.5 py-1 text-[11px] font-bold text-[#00856a]">
                                {{ orders.total_active }} Aktiv
                            </span>
                            <span class="rounded-full bg-[#6366f1]/10 px-2.5 py-1 text-[11px] font-bold text-[#4f46e5]">
                                {{ orders.total_confirmed }} Bestätigt
                            </span>
                            <span class="rounded-full bg-[#10393b]/[0.08] px-2.5 py-1 text-[11px] font-bold text-[#10393b]">
                                {{ orders.total_delivered }} Geliefert
                            </span>
                        </div>
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
                                <th class="admin-th">Auftrag</th>
                                <th class="admin-th">Fahrzeug</th>
                                <th class="admin-th">Kunde</th>
                                <th class="admin-th">Status</th>
                                <th class="admin-th">Erstellt</th>
                                <th class="w-12 border-b border-[#eef3f2]"></th>
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

                            <tr v-else-if="!orders.data.length">
                                <td colspan="6" class="py-16 text-center text-[13px] text-[#9bb0af]">Keine Aufträge gefunden.</td>
                            </tr>

                            <tr
                                v-for="order in loading ? [] : orders.data"
                                :key="order.id"
                                class="group cursor-pointer border-b border-[#eef3f2] transition-colors hover:bg-[#f6f9f8]"
                                @click="openDetail(order)"
                            >
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-[#6366f1]/10 text-[#6366f1]">
                                            <IconMdiFileDocumentOutline class="size-[17px]" />
                                        </div>

                                        <div class="min-w-0">
                                            <div class="truncate font-mono text-[13px] font-bold text-[#10393b]">{{ order.auftragsnummer }}</div>
                                            <div class="mt-0.5 text-[11px] text-[#9bb0af]">{{ order.leasyback_partner }}</div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-5 py-3.5">
                                    <div class="truncate text-[13px] font-bold text-[#10393b]">{{ vehicleTitle(order) }}</div>
                                    <div class="mt-0.5 truncate font-mono text-[11px] text-[#9bb0af]">{{ order.license_plate }}</div>
                                </td>

                                <td class="max-w-[220px] truncate px-5 py-3.5 text-[13px] text-[#5a6e6c]">{{ ownerLabel(order) }}</td>

                                <td class="px-5 py-3.5">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                        :style="{
                                            background: getStatus(order.order_status).background,
                                            color: getStatus(order.order_status).color,
                                        }"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ getStatus(order.order_status).label }}
                                    </span>
                                </td>

                                <td class="px-5 py-3.5 text-[12.5px] text-[#9bb0af] tabular-nums">{{ formatGermanDate(order.created_at) }}</td>

                                <td class="px-3 py-3.5">
                                    <span
                                        class="flex h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white"
                                    >
                                        <IconMdiArrowTopRight class="size-[15px]" />
                                    </span>
                                </td>
                            </tr>
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
