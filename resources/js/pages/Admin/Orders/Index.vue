<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ADMIN_ORDER_STATUS_FILTERS, getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import { taskPriorityStyle } from '@/lib/adminTaskPriority';
import { formatPortalDate } from '@/lib/portalDate';
import type { AdminOrderList, AdminOrderListRow } from '@/types/admin';
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

/**
 * The list's primary view: everything, or one of AdminQueryService's three
 * status *groups* (§ OrderStatus::openValues()/inProgressValues()/
 * closedValues()) — never one of the 16 exact statuses, which live in the
 * secondary dropdown below instead. Both write the same `status` param, so
 * only one is ever active at a time.
 */
const STATUS_TABS: { value: string; label: string }[] = [
    { value: '', label: 'Alle' },
    { value: 'open', label: 'Offen' },
    { value: 'in_progress', label: 'In Bearbeitung' },
    { value: 'closed', label: 'Abgeschlossen' },
];

/** The 16 exact statuses — the tabs above already cover "Alle". */
const detailedStatusOptions = computed(() => ADMIN_ORDER_STATUS_FILTERS.slice(1));

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

/* 350ms: each keystroke that fires re-renders the whole table, which is the
   dominant cost on a phone. */
const debouncedReload = useDebounceFn(() => reload(), 350);

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

function ownerLabel(order: AdminOrderListRow): string {
    return order.company_name || order.user_email || 'Nicht zugeordnet';
}

function vehicleTitle(order: AdminOrderListRow): string {
    return [order.make, order.model].filter(Boolean).join(' ') || 'Ohne Marke';
}

function formatGermanDate(value: string | null): string {
    return formatPortalDate(value) || '—';
}

function openDetail(order: AdminOrderListRow) {
    router.visit(route('admin.orders.show', order.id));
}
</script>

<template>
    <Head title="Aufträge" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
                <h1 class="shrink-0 text-[16px] font-extrabold tracking-[-0.3px] text-[#10393b]">Auftragsverwaltung</h1>

                <div class="admin-search basis-full md:ml-auto md:basis-0">
                    <IconMdiMagnify class="size-4 shrink-0" />

                    <input
                        v-model="search"
                        type="search"
                        placeholder="Auftragsnummer, Kennzeichen…"
                        class="admin-search-input"
                        autocomplete="off"
                        autocapitalize="off"
                        autocorrect="off"
                        spellcheck="false"
                        enterkeyhint="search"
                        aria-label="Suche"
                    />

                    <button v-if="search" type="button" class="search-clear" title="Suche zurücksetzen" @click="clearSearch">
                        <IconMdiClose class="size-3.5" />
                    </button>
                </div>
            </div>
        </template>

        <div class="flex flex-col gap-5 md:h-full">
            <!-- Flat, hairline-bordered section — no shadow, no oversized
                 radius. A panel earns a shadow only when it floats above
                 something (menus, modals); a page section never does. -->
            <section class="flex flex-col rounded-[10px] border border-[#eef3f2] bg-white p-3 sm:p-6 md:min-h-0 md:flex-1">
                <!-- ── Header: title + a plain compact summary, no colour pills ── -->
                <div class="mb-4 shrink-0">
                    <h2 class="text-[18px] font-extrabold tracking-[-0.3px] text-[#10393b]">Alle Aufträge</h2>
                    <p class="mt-1 text-[12.5px] font-medium text-[#6f8585]">
                        {{ orders.total }} Aufträge{{ hasQuery ? ' gefunden' : '' }} · {{ orders.total_open }} offen ·
                        {{ orders.total_in_progress }} in Bearbeitung · {{ orders.total_closed }} abgeschlossen
                    </p>
                </div>

                <!-- ── Filters: 4 primary tabs + the 16 detailed statuses in one secondary dropdown ── -->
                <div class="mb-4 flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-[#eef3f2] pb-0">
                    <nav class="flex items-center gap-5 overflow-x-auto" aria-label="Auftragsstatus">
                        <button
                            v-for="tab in STATUS_TABS"
                            :key="tab.value"
                            type="button"
                            class="-mb-px shrink-0 border-b-2 pb-2.5 text-[13px] font-bold whitespace-nowrap transition-colors"
                            :class="
                                statusFilter === tab.value
                                    ? 'border-[#10393b] text-[#10393b]'
                                    : 'border-transparent text-[#9bb0af] hover:text-[#10393b]'
                            "
                            @click="setStatusFilter(tab.value)"
                        >
                            {{ tab.label }}
                        </button>
                    </nav>

                    <select
                        class="mb-1.5 shrink-0 rounded-[6px] border border-[#eef3f2] bg-white px-2.5 py-1.5 text-[12px] font-semibold text-[#5a6e6c]"
                        aria-label="Detaillierter Status"
                        :value="statusFilter"
                        @change="setStatusFilter(($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Weitere Status…</option>
                        <option v-for="option in detailedStatusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>

                <!--
                    Below `md` the card grows with its content and the shell
                    scrolls; `flex-1` inside a `h-dvh` column collapsed this to
                    zero height once the filters wrapped, which is why the table
                    disappeared under the status filters on phones.
                -->
                <div class="w-full overflow-x-auto rounded-[10px] border border-[#eef3f2] md:min-h-0 md:flex-1 md:overflow-y-auto">
                    <table class="w-full border-collapse sm:min-w-[860px]">
                        <thead class="z-10 md:sticky md:top-0">
                            <tr class="bg-[#f8faf9]">
                                <th class="admin-th">Auftrag</th>
                                <th class="admin-th hidden sm:table-cell">Fahrzeug</th>
                                <th class="admin-th hidden md:table-cell">Kunde</th>
                                <th class="admin-th hidden sm:table-cell">Status</th>
                                <th class="admin-th hidden sm:table-cell">Priorität</th>
                                <th class="admin-th hidden md:table-cell">Erstellt</th>
                                <th class="w-10 border-b border-[#eef3f2]"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-if="loading">
                                <tr v-for="item in 8" :key="item">
                                    <td colspan="7" class="px-5 py-3.5">
                                        <div class="h-4 animate-pulse rounded-[4px] bg-[#f4f7f6]" :style="{ width: 55 + (item % 5) * 9 + '%' }"></div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-else-if="!orders.data.length">
                                <td colspan="7" class="py-16 text-center text-[13px] text-[#9bb0af]">Keine Aufträge gefunden.</td>
                            </tr>

                            <tr
                                v-for="order in loading ? [] : orders.data"
                                :key="order.id"
                                class="group cursor-pointer border-b border-[#eef3f2] transition-colors hover:bg-[#f6f9f8]"
                                @click="openDetail(order)"
                            >
                                <!-- Auftrag: the primary identifier. No icon
                                     tile — the order number carries the
                                     weight on its own. -->
                                <td class="px-3 py-2.5 sm:px-5">
                                    <div class="min-w-0">
                                        <div class="truncate font-mono text-[14px] font-extrabold text-[#10393b]">{{ order.auftragsnummer }}</div>
                                        <div class="mt-0.5 text-[11px] text-[#9bb0af]">{{ order.leasyback_partner }}</div>

                                        <!--
                                            Below `sm` Fahrzeug, Status and Priorität fold away
                                            rather than scroll into view; they reappear here so a
                                            phone still shows what each order is and where it stands.
                                        -->
                                        <div class="mt-1.5 sm:hidden">
                                            <div class="truncate text-[12.5px] font-semibold text-[#3f5250]">{{ vehicleTitle(order) }}</div>
                                            <div class="truncate font-mono text-[11px] text-[#9bb0af]">{{ order.license_plate }}</div>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                <span
                                                    class="inline-flex items-center gap-1.5 rounded-[6px] px-2 py-1 text-[11px] font-bold"
                                                    :style="{
                                                        background: getStatus(order.order_status).background,
                                                        color: getStatus(order.order_status).color,
                                                    }"
                                                >
                                                    <span class="h-[5px] w-[5px] shrink-0 rounded-full bg-current"></span>
                                                    {{ getStatus(order.order_status).label }}
                                                </span>

                                                <span
                                                    v-if="taskPriorityStyle(order.priority)"
                                                    class="inline-flex items-center gap-1.5 rounded-[6px] px-2 py-1 text-[11px] font-extrabold"
                                                    :class="taskPriorityStyle(order.priority)?.badge"
                                                >
                                                    <span class="h-[5px] w-[5px] shrink-0 rounded-full bg-current"></span>
                                                    {{ taskPriorityStyle(order.priority)?.label }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Fahrzeug: secondary — quieter weight than the order number. -->
                                <td class="hidden px-3 py-2.5 sm:table-cell sm:px-5">
                                    <div class="truncate text-[12.5px] font-semibold text-[#3f5250]">{{ vehicleTitle(order) }}</div>
                                    <div class="mt-0.5 truncate font-mono text-[11px] text-[#9bb0af]">{{ order.license_plate }}</div>
                                </td>

                                <!-- Kunde: secondary. -->
                                <td class="hidden max-w-[200px] truncate px-3 py-2.5 text-[12.5px] text-[#5a6e6c] sm:px-5 md:table-cell">
                                    {{ ownerLabel(order) }}
                                </td>

                                <!-- Status: its own column — no longer sharing a cell with urgency. -->
                                <td class="hidden px-3 py-2.5 sm:table-cell sm:px-5">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-[6px] px-2 py-1 text-[11px] font-bold whitespace-nowrap"
                                        :style="{
                                            background: getStatus(order.order_status).background,
                                            color: getStatus(order.order_status).color,
                                        }"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ getStatus(order.order_status).label }}
                                    </span>
                                </td>

                                <!-- Priorität: OrderTaskPriorityResolver's verdict on this
                                     order's next task — the same ranking the list is sorted
                                     by. Blank for a closed order: nothing left to rank. -->
                                <td class="hidden px-3 py-2.5 sm:table-cell sm:px-5">
                                    <span
                                        v-if="taskPriorityStyle(order.priority)"
                                        class="inline-flex items-center gap-1.5 rounded-[6px] px-2 py-1 text-[11px] font-extrabold whitespace-nowrap"
                                        :class="taskPriorityStyle(order.priority)?.badge"
                                    >
                                        <span class="h-[5px] w-[5px] shrink-0 rounded-full bg-current"></span>
                                        {{ taskPriorityStyle(order.priority)?.label }}
                                    </span>
                                    <span v-else class="text-[11px] text-[#c3d0ce]">—</span>
                                </td>

                                <td class="hidden px-3 py-2.5 text-[12px] text-[#9bb0af] tabular-nums sm:px-5 md:table-cell">
                                    {{ formatGermanDate(order.created_at) }}
                                </td>

                                <td class="px-2 py-2.5 sm:px-3">
                                    <IconMdiChevronRight class="size-4 text-[#bcccca] transition-colors group-hover:text-[#10393b]" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex shrink-0 flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <span class="text-[12px] font-medium text-[#9bb0af]">Seite {{ page }} von {{ totalPages }}</span>

                    <div class="flex flex-wrap items-center justify-end gap-1">
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
