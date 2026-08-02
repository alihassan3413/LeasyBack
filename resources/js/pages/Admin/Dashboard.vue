<script setup lang="ts">
import AdminLayout from '@/layouts/AdminLayout.vue';
import { ADMIN_ORDER_STATUS_FILTERS, getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import type { AdminSummaryData } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type PanelType = 'orders' | 'users' | 'vehicles';

interface AdminPanelUser {
    user_id: number | string;
    user_email: string | null;
    salutation: string | null;
    first_name: string | null;
    last_name: string | null;
    company_name?: string | null;
    is_active: boolean | number;
}

interface AdminPanelVehicle {
    vehicle_id: string;
    license_plate: string | null;
    vin: string | null;
    make: string | null;
    model: string | null;
    current_order_status: string | null;
}

interface AdminPanelOrder {
    id: string;
    auftragsnummer: string | null;
    make: string | null;
    model: string | null;
    license_plate: string | null;
    order_status: string;
    created_at: string | null;
}

interface PanelList<T> {
    data: T[];
    total: number | string;
    page: number;
}

interface DashboardFilters {
    panel: PanelType;
    search: string;
    status: string;
    user_type: 'B2C' | 'B2B';
    page: number;
}

const props = defineProps<{
    summary: AdminSummaryData;
    filters: DashboardFilters;
    orders: PanelList<AdminPanelOrder> | null;
    users: PanelList<AdminPanelUser> | null;
    vehicles: PanelList<AdminPanelVehicle> | null;
}>();

const today = new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date());

const activePanel = ref<PanelType>(props.filters.panel);
const search = ref(props.filters.search);
const panelOrdersFilter = ref(props.filters.status);
const panelUsersType = ref<'B2C' | 'B2B'>(props.filters.user_type);
const page = ref(props.filters.page || 1);
const loading = ref(false);

const panelOrdersFilters = ADMIN_ORDER_STATUS_FILTERS;
const panelLimit = 10;
const numberFormat = new Intl.NumberFormat('de-DE');

function toNumber(value: number | string | null | undefined): number {
    const numeric = typeof value === 'number' ? value : Number(value ?? 0);

    return Number.isFinite(numeric) ? numeric : 0;
}

const totalOrders = computed(() => toNumber(props.summary.total_orders));
const activeOrders = computed(() => toNumber(props.summary.active_orders));
const completedOrders = computed(() => toNumber(props.summary.delivered_orders));
const pendingInspections = computed(() => toNumber(props.summary.pending_inspections));
const totalB2B = computed(() => toNumber(props.summary.total_b2b_companies));
const totalB2C = computed(() => toNumber(props.summary.total_b2c_customers));
const totalVehicles = computed(() => toNumber(props.summary.total_vehicles));
const totalCustomers = computed(() => totalB2B.value + totalB2C.value);

const panelUsers = computed(() => props.users?.data ?? []);
const panelUsersTotal = computed(() => toNumber(props.users?.total));
const panelVehicles = computed(() => props.vehicles?.data ?? []);
const panelVehiclesTotal = computed(() => toNumber(props.vehicles?.total));
const panelOrders = computed(() => props.orders?.data ?? []);
const panelOrdersTotal = computed(() => toNumber(props.orders?.total));

function totalPages(total: number): number {
    return Math.max(1, Math.ceil(total / panelLimit));
}

const panelUsersTotalPages = computed(() => totalPages(panelUsersTotal.value));
const panelVehiclesTotalPages = computed(() => totalPages(panelVehiclesTotal.value));
const panelOrdersTotalPages = computed(() => totalPages(panelOrdersTotal.value));

function reloadActivePanel() {
    loading.value = true;

    router.get(
        route('admin.dashboard'),
        {
            panel: activePanel.value,
            search: search.value || undefined,
            status: activePanel.value === 'orders' && panelOrdersFilter.value ? panelOrdersFilter.value : undefined,
            user_type: activePanel.value === 'users' ? panelUsersType.value : undefined,
            page: page.value > 1 ? page.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [activePanel.value, 'filters'],
            onFinish: () => (loading.value = false),
        },
    );
}

const debouncedReload = useDebounceFn(reloadActivePanel, 300);

function activatePanel(type: PanelType) {
    if (activePanel.value === type) {
        return;
    }

    search.value = '';
    page.value = 1;
    activePanel.value = type;
    reloadActivePanel();
}

watch(search, debouncedReload);
watch([panelOrdersFilter, panelUsersType], () => {
    page.value = 1;
    reloadActivePanel();
});
watch(page, reloadActivePanel);

function pageRange(currentPage: number, total: number): Array<number | '…'> {
    const pages: Array<number | '…'> = [];

    for (let candidate = 1; candidate <= total; candidate += 1) {
        const shouldDisplay = candidate === 1 || candidate === total || Math.abs(candidate - currentPage) <= 1;

        if (shouldDisplay) {
            pages.push(candidate);
            continue;
        }

        if (pages[pages.length - 1] !== '…') {
            pages.push('…');
        }
    }

    return pages;
}

function userInitials(user: AdminPanelUser): string {
    const first = user.first_name?.charAt(0) ?? '';
    const last = user.last_name?.charAt(0) ?? '';

    return `${first}${last}`.toUpperCase() || '?';
}

function formatGermanDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const donutDistribution = computed(() => [
    { label: 'Aktiv', count: activeOrders.value, color: '#01B990' },
    { label: 'Abgeschlossen', count: completedOrders.value, color: '#10393b' },
    { label: 'Ausstehend', count: pendingInspections.value, color: '#ef8450' },
]);

const donutTotal = computed(() => donutDistribution.value.reduce((total, item) => total + item.count, 0));

const donutSegments = computed(() => {
    const circumference = 2 * Math.PI * 48;
    let accumulatedLength = 0;

    return donutDistribution.value.map((item) => {
        const segmentLength = donutTotal.value > 0 ? (item.count / donutTotal.value) * circumference : 0;

        const segment = {
            label: item.label,
            color: item.color,
            dash: `${Math.max(segmentLength - 4, 0)} ${circumference}`,
            offset: -accumulatedLength,
        };

        accumulatedLength += segmentLength;

        return segment;
    });
});

const services = [
    { name: 'API-Gateway', status: 'ok', label: 'Aktiv' },
    { name: 'Datenbank', status: 'ok', label: 'Aktiv' },
    { name: 'E-Mail-Dienst', status: 'ok', label: 'Aktiv' },
    { name: 'Hintergrundjobs', status: 'warning', label: 'Verzögert' },
];

function openCustomer(user: AdminPanelUser) {
    router.visit(route('admin.customers.show', { type: panelUsersType.value === 'B2B' ? 'b2b' : 'b2c', id: user.user_id }));
}
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout>
        <div class="flex h-full flex-col gap-5">
            <main class="flex flex-1 flex-col gap-5 overflow-y-auto pr-1 pb-4">
                <div class="flex items-end justify-between gap-5 max-[760px]:items-start">
                    <div>
                        <p class="mb-1.5 text-[12px] font-bold text-[#01B990] capitalize">{{ today }}</p>

                        <h1 class="text-[34px] leading-none font-extrabold tracking-[-1.2px] text-[#10393b]">Übersicht</h1>

                        <p class="mt-2 text-[13.5px] font-medium text-[#6f8585]">Willkommen zurück — der aktuelle Stand Ihrer Flotte.</p>
                    </div>
                </div>

                <section class="grid grid-cols-3 gap-4 max-[1100px]:grid-cols-1">
                    <button
                        type="button"
                        class="dashboard-card"
                        :class="{ 'dashboard-card-active': activePanel === 'orders' }"
                        @click="activatePanel('orders')"
                    >
                        <div v-if="activePanel === 'orders'" class="absolute -top-24 -right-20 h-64 w-64 rounded-full bg-white/15 blur-2xl"></div>

                        <div class="relative z-10 flex h-full flex-col">
                            <div class="flex items-start justify-between gap-4">
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[17px] border"
                                    :class="
                                        activePanel === 'orders'
                                            ? 'border-white/25 bg-white/20 text-white'
                                            : 'border-[#d9ece7] bg-[#01B990]/10 text-[#00856a]'
                                    "
                                >
                                    <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                        <path d="M14 2v6h6M16 13H8M16 17H8" />
                                    </svg>
                                </div>

                                <span
                                    class="rounded-full px-3 py-1.5 text-[11px] font-extrabold"
                                    :class="activePanel === 'orders' ? 'bg-white/20 text-white' : 'bg-[#01B990]/10 text-[#00856a]'"
                                >
                                    {{ activePanel === 'orders' ? 'Aktiv' : 'Anzeigen' }}
                                </span>
                            </div>

                            <div class="mt-6">
                                <p
                                    class="text-[12px] font-bold tracking-[0.08em] uppercase"
                                    :class="activePanel === 'orders' ? 'text-white/65' : 'text-[#9bb0af]'"
                                >
                                    Gesamtübersicht
                                </p>

                                <p class="mt-2 text-[48px] leading-none font-extrabold tracking-[-2px]">{{ numberFormat.format(totalOrders) }}</p>

                                <p class="mt-2 text-[14px] font-bold" :class="activePanel === 'orders' ? 'text-white/85' : 'text-[#6f8585]'">
                                    Aufträge gesamt
                                </p>
                            </div>

                            <div
                                class="mt-auto grid grid-cols-2 gap-2 border-t pt-5"
                                :class="activePanel === 'orders' ? 'border-white/20' : 'border-[#edf2f1]'"
                            >
                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'orders' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'orders' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Offen
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ activeOrders }}</p>
                                </div>

                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'orders' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'orders' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Geliefert
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ completedOrders }}</p>
                                </div>

                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'orders' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'orders' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Inspektionen
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ pendingInspections }}</p>
                                </div>

                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'orders' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'orders' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Kunden
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ totalCustomers }}</p>
                                </div>
                            </div>
                        </div>
                    </button>

                    <button type="button" class="dashboard-card" :class="{ 'dashboard-card-active': activePanel === 'users' }" @click="activatePanel('users')">
                        <div v-if="activePanel === 'users'" class="absolute -top-24 -right-20 h-64 w-64 rounded-full bg-white/15 blur-2xl"></div>

                        <div class="relative z-10 flex h-full flex-col">
                            <div class="flex items-start justify-between gap-4">
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[17px] border"
                                    :class="
                                        activePanel === 'users'
                                            ? 'border-white/25 bg-white/20 text-white'
                                            : 'border-[#e1e7e6] bg-[#10393b]/[0.06] text-[#10393b]'
                                    "
                                >
                                    <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M23 21v-2a4 4 0 00-3-3.87" />
                                        <path d="M16 3.13a4 4 0 010 7.75" />
                                    </svg>
                                </div>

                                <span
                                    class="rounded-full px-3 py-1.5 text-[11px] font-extrabold"
                                    :class="activePanel === 'users' ? 'bg-white/20 text-white' : 'bg-[#10393b]/[0.06] text-[#6f8585]'"
                                >
                                    B2B: {{ totalB2B }} · B2C: {{ totalB2C }}
                                </span>
                            </div>

                            <div class="mt-6">
                                <p
                                    class="text-[12px] font-bold tracking-[0.08em] uppercase"
                                    :class="activePanel === 'users' ? 'text-white/65' : 'text-[#9bb0af]'"
                                >
                                    Kunden
                                </p>

                                <p class="mt-2 text-[48px] leading-none font-extrabold tracking-[-2px]">{{ numberFormat.format(totalCustomers) }}</p>

                                <p class="mt-2 text-[14px] font-bold" :class="activePanel === 'users' ? 'text-white/85' : 'text-[#6f8585]'">
                                    Kunden gesamt
                                </p>
                            </div>

                            <div
                                class="mt-auto grid grid-cols-2 gap-2 border-t pt-5"
                                :class="activePanel === 'users' ? 'border-white/20' : 'border-[#edf2f1]'"
                            >
                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'users' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'users' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Firmenkunden
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ totalB2B }}</p>
                                </div>

                                <div class="rounded-[13px] px-3 py-2.5" :class="activePanel === 'users' ? 'bg-white/10' : 'bg-[#f4f7f6]'">
                                    <p
                                        class="text-[10px] font-bold tracking-[0.05em] uppercase"
                                        :class="activePanel === 'users' ? 'text-white/55' : 'text-[#9bb0af]'"
                                    >
                                        Privatkunden
                                    </p>
                                    <p class="mt-1 text-[17px] font-extrabold">{{ totalB2C }}</p>
                                </div>
                            </div>
                        </div>
                    </button>

                    <button
                        type="button"
                        class="dashboard-card"
                        :class="{ 'dashboard-card-active': activePanel === 'vehicles' }"
                        @click="activatePanel('vehicles')"
                    >
                        <div v-if="activePanel === 'vehicles'" class="absolute -top-24 -right-20 h-64 w-64 rounded-full bg-white/15 blur-2xl"></div>

                        <div class="relative z-10 flex h-full flex-col">
                            <div class="flex items-start justify-between gap-4">
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[17px] border"
                                    :class="
                                        activePanel === 'vehicles'
                                            ? 'border-white/25 bg-white/20 text-white'
                                            : 'border-[#f4dfd5] bg-[#ef8450]/10 text-[#ef8450]'
                                    "
                                >
                                    <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v9a2 2 0 01-2 2h-1" />
                                        <circle cx="9" cy="17" r="2" />
                                        <circle cx="17" cy="17" r="2" />
                                    </svg>
                                </div>

                                <span
                                    class="rounded-full px-3 py-1.5 text-[11px] font-extrabold"
                                    :class="activePanel === 'vehicles' ? 'bg-white/20 text-white' : 'bg-[#ef8450]/10 text-[#c0622e]'"
                                >
                                    Im Fuhrpark
                                </span>
                            </div>

                            <div class="mt-6">
                                <p
                                    class="text-[12px] font-bold tracking-[0.08em] uppercase"
                                    :class="activePanel === 'vehicles' ? 'text-white/65' : 'text-[#9bb0af]'"
                                >
                                    Fahrzeuge
                                </p>

                                <p class="mt-2 text-[48px] leading-none font-extrabold tracking-[-2px]">{{ numberFormat.format(totalVehicles) }}</p>

                                <p class="mt-2 text-[14px] font-bold" :class="activePanel === 'vehicles' ? 'text-white/85' : 'text-[#6f8585]'">
                                    Fahrzeuge gesamt
                                </p>
                            </div>

                            <div class="mt-auto border-t pt-5" :class="activePanel === 'vehicles' ? 'border-white/20' : 'border-[#edf2f1]'">
                                <div
                                    class="flex items-center justify-between rounded-[13px] px-3 py-3"
                                    :class="activePanel === 'vehicles' ? 'bg-white/10' : 'bg-[#f4f7f6]'"
                                >
                                    <span class="text-[11px] font-bold" :class="activePanel === 'vehicles' ? 'text-white/65' : 'text-[#9bb0af]'">
                                        Fahrzeugliste öffnen
                                    </span>
                                </div>
                            </div>
                        </div>
                    </button>
                </section>

                <section class="grid grid-cols-[1.65fr_1fr] items-start gap-4 max-[1180px]:grid-cols-1">
                    <Transition name="panel" mode="out-in">
                        <section v-if="activePanel === 'users'" key="users" class="content-card">
                            <div class="mb-4 flex items-center justify-between gap-4 max-[720px]:flex-col max-[720px]:items-start">
                                <div>
                                    <h2 class="text-[18px] font-extrabold tracking-[-0.3px] text-[#10393b]">Kunden</h2>
                                    <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ panelUsersTotal }} Kunden insgesamt</p>
                                </div>

                                <div class="flex gap-0.5 rounded-[12px] bg-[#f4f7f6] p-[3px]">
                                    <button
                                        type="button"
                                        class="segment-button"
                                        :class="{ 'segment-button-active': panelUsersType === 'B2C' }"
                                        @click="panelUsersType = 'B2C'"
                                    >
                                        Privatkunden
                                    </button>

                                    <button
                                        type="button"
                                        class="segment-button"
                                        :class="{ 'segment-button-active': panelUsersType === 'B2B' }"
                                        @click="panelUsersType = 'B2B'"
                                    >
                                        Firmenkunden
                                    </button>
                                </div>
                            </div>

                            <div class="panel-search">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="M21 21l-4.35-4.35" />
                                </svg>

                                <input v-model="search" type="text" placeholder="Kunden durchsuchen…" class="panel-search-input" />

                                <button v-if="search" type="button" class="search-clear" @click="search = ''">×</button>
                            </div>

                            <div v-if="loading" class="flex flex-col gap-2">
                                <div v-for="item in 6" :key="item" class="h-[58px] animate-pulse rounded-[15px] bg-[#f4f7f6]"></div>
                            </div>

                            <div v-else class="flex flex-col gap-1">
                                <div v-if="panelUsers.length === 0" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Kunden gefunden.</div>

                                <button
                                    v-for="user in panelUsers"
                                    :key="user.user_id"
                                    type="button"
                                    class="group flex w-full items-center gap-3 rounded-[13px] px-3 py-2.5 text-left transition-colors hover:bg-[#f6f9f8]"
                                    @click="openCustomer(user)"
                                >
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] text-[11px] font-extrabold text-white"
                                        style="background: linear-gradient(150deg, #01b990, #10393b)"
                                    >
                                        {{ userInitials(user) }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-bold text-[#10393b]">
                                            {{ user.salutation }} {{ user.first_name }} {{ user.last_name }}
                                        </p>

                                        <p class="truncate text-[11.5px] text-[#6f8585]">{{ user.company_name ?? user.user_email }}</p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                            :class="user.is_active ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#ef8450]/10 text-[#c0622e]'"
                                        >
                                            <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                                            {{ user.is_active ? 'Aktiv' : 'Inaktiv' }}
                                        </span>

                                        <span
                                            class="flex h-7 w-7 items-center justify-center rounded-[8px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white"
                                        >
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M7 17L17 7M17 7H8M17 7v9" />
                                            </svg>
                                        </span>
                                    </div>
                                </button>
                            </div>

                            <div class="pagination-row">
                                <span>Seite {{ page }} von {{ panelUsersTotalPages }}</span>

                                <div class="flex gap-1">
                                    <button type="button" class="lb-pg" :disabled="page <= 1" @click="page -= 1">←</button>

                                    <button
                                        v-for="item in pageRange(page, panelUsersTotalPages)"
                                        :key="String(item)"
                                        type="button"
                                        class="lb-pg"
                                        :class="{ 'lb-pg-active': item === page, 'lb-pg-dot': item === '…' }"
                                        @click="typeof item === 'number' && (page = item)"
                                    >
                                        {{ item }}
                                    </button>

                                    <button type="button" class="lb-pg" :disabled="page >= panelUsersTotalPages" @click="page += 1">→</button>
                                </div>
                            </div>
                        </section>

                        <section v-else-if="activePanel === 'vehicles'" key="vehicles" class="content-card">
                            <div class="mb-4">
                                <h2 class="text-[18px] font-extrabold tracking-[-0.3px] text-[#10393b]">Fahrzeuge</h2>
                                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ panelVehiclesTotal }} Fahrzeuge insgesamt</p>
                            </div>

                            <div class="panel-search">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="M21 21l-4.35-4.35" />
                                </svg>

                                <input v-model="search" type="text" placeholder="Fahrzeuge durchsuchen…" class="panel-search-input" />

                                <button v-if="search" type="button" class="search-clear" @click="search = ''">×</button>
                            </div>

                            <div v-if="loading" class="flex flex-col gap-2">
                                <div v-for="item in 6" :key="item" class="h-[58px] animate-pulse rounded-[15px] bg-[#f4f7f6]"></div>
                            </div>

                            <div v-else class="flex flex-col gap-1">
                                <div v-if="panelVehicles.length === 0" class="py-12 text-center text-[13px] text-[#9bb0af]">
                                    Keine Fahrzeuge gefunden.
                                </div>

                                <div
                                    v-for="vehicle in panelVehicles"
                                    :key="vehicle.vehicle_id"
                                    class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                                >
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v9a2 2 0 01-2 2h-1" />
                                            <circle cx="9" cy="17" r="2" />
                                            <circle cx="17" cy="17" r="2" />
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-bold text-[#10393b]">{{ vehicle.make }} {{ vehicle.model }}</p>

                                        <p class="truncate font-mono text-[11.5px] text-[#6f8585]">{{ vehicle.license_plate }} · {{ vehicle.vin }}</p>
                                    </div>

                                    <span
                                        v-if="vehicle.current_order_status"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                        :style="{
                                            background: getStatus(vehicle.current_order_status).background,
                                            color: getStatus(vehicle.current_order_status).color,
                                        }"
                                    >
                                        <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                                        {{ getStatus(vehicle.current_order_status).label }}
                                    </span>
                                </div>
                            </div>

                            <div class="pagination-row">
                                <span>Seite {{ page }} von {{ panelVehiclesTotalPages }}</span>

                                <div class="flex gap-1">
                                    <button type="button" class="lb-pg" :disabled="page <= 1" @click="page -= 1">←</button>

                                    <button
                                        v-for="item in pageRange(page, panelVehiclesTotalPages)"
                                        :key="String(item)"
                                        type="button"
                                        class="lb-pg"
                                        :class="{ 'lb-pg-active': item === page, 'lb-pg-dot': item === '…' }"
                                        @click="typeof item === 'number' && (page = item)"
                                    >
                                        {{ item }}
                                    </button>

                                    <button type="button" class="lb-pg" :disabled="page >= panelVehiclesTotalPages" @click="page += 1">→</button>
                                </div>
                            </div>
                        </section>

                        <section v-else key="orders" class="content-card">
                            <div class="mb-4 flex items-center justify-between gap-4 max-[720px]:flex-col max-[720px]:items-start">
                                <div>
                                    <h2 class="text-[18px] font-extrabold tracking-[-0.3px] text-[#10393b]">Letzte Aufträge</h2>
                                    <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">
                                        {{ panelOrders.length }} von {{ panelOrdersTotal }} Aufträgen
                                    </p>
                                </div>
                            </div>

                            <div class="mb-4 flex flex-wrap gap-1.5">
                                <button
                                    v-for="filter in panelOrdersFilters"
                                    :key="filter.value"
                                    type="button"
                                    class="status-pill"
                                    :class="{ 'status-pill-active': panelOrdersFilter === filter.value }"
                                    @click="panelOrdersFilter = filter.value"
                                >
                                    {{ filter.label }}
                                </button>
                            </div>

                            <div class="panel-search">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="M21 21l-4.35-4.35" />
                                </svg>

                                <input v-model="search" type="text" placeholder="Aufträge durchsuchen…" class="panel-search-input" />

                                <button v-if="search" type="button" class="search-clear" @click="search = ''">×</button>
                            </div>

                            <div v-if="loading" class="flex flex-col gap-2">
                                <div v-for="item in 6" :key="item" class="h-[58px] animate-pulse rounded-[15px] bg-[#f4f7f6]"></div>
                            </div>

                            <div v-else class="flex flex-col gap-1">
                                <div v-if="panelOrders.length === 0" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Aufträge gefunden.</div>

                                <div
                                    v-for="order in panelOrders"
                                    :key="order.id"
                                    class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                                >
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#6366f1]/10 text-[#6366f1]">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                            <path d="M14 2v6h6M16 13H8M16 17H8" />
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="mb-0.5 flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-[13px] font-bold text-[#10393b]">{{ order.auftragsnummer }}</span>

                                            <span class="text-[11.5px] text-[#9bb0af]">{{ order.make }} {{ order.model }}</span>
                                        </div>

                                        <p class="truncate font-mono text-[11.5px] text-[#6f8585]">{{ order.license_plate }}</p>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                            :style="{
                                                background: getStatus(order.order_status).background,
                                                color: getStatus(order.order_status).color,
                                            }"
                                        >
                                            <span class="h-[4px] w-[4px] rounded-full bg-current"></span>
                                            {{ getStatus(order.order_status).label }}
                                        </span>

                                        <span class="hidden text-[11px] text-[#9bb0af] tabular-nums lg:block">{{ formatGermanDate(order.created_at) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="pagination-row">
                                <span>Seite {{ page }} von {{ panelOrdersTotalPages }}</span>

                                <div class="flex gap-1">
                                    <button type="button" class="lb-pg" :disabled="page <= 1" @click="page -= 1">←</button>

                                    <button
                                        v-for="item in pageRange(page, panelOrdersTotalPages)"
                                        :key="String(item)"
                                        type="button"
                                        class="lb-pg"
                                        :class="{ 'lb-pg-active': item === page, 'lb-pg-dot': item === '…' }"
                                        @click="typeof item === 'number' && (page = item)"
                                    >
                                        {{ item }}
                                    </button>

                                    <button type="button" class="lb-pg" :disabled="page >= panelOrdersTotalPages" @click="page += 1">→</button>
                                </div>
                            </div>
                        </section>
                    </Transition>

                    <aside class="flex flex-col gap-4">
                        <section
                            v-if="pendingInspections > 0"
                            class="flex items-center gap-3 rounded-[20px] border border-[#ef8450]/20 p-4"
                            style="background: linear-gradient(135deg, rgba(239, 132, 80, 0.11), rgba(239, 132, 80, 0.03))"
                        >
                            <div
                                class="lb-pulse flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-[13px] text-white"
                                style="background: linear-gradient(140deg, #f59b6c, #ef8450)"
                            >
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0" />
                                </svg>
                            </div>

                            <div class="min-w-0 flex-1">
                                <strong class="block text-[13px] font-extrabold text-[#10393b]">
                                    {{ pendingInspections }} ausstehende Inspektion{{ pendingInspections === 1 ? '' : 'en' }}
                                </strong>

                                <span class="text-[11.5px] font-semibold text-[#b06c44]">Warten auf Bearbeitung</span>
                            </div>
                        </section>

                        <section class="content-card">
                            <h2 class="mb-5 text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Auftragsstatus</h2>

                            <div class="flex items-center gap-4">
                                <svg class="h-[120px] w-[120px] shrink-0" viewBox="0 0 120 120">
                                    <circle cx="60" cy="60" r="48" fill="none" stroke="#f0f4f3" stroke-width="14" />

                                    <circle
                                        v-for="segment in donutSegments"
                                        :key="segment.label"
                                        cx="60"
                                        cy="60"
                                        r="48"
                                        fill="none"
                                        :stroke="segment.color"
                                        stroke-width="14"
                                        :stroke-dasharray="segment.dash"
                                        :stroke-dashoffset="segment.offset"
                                        stroke-linecap="round"
                                        transform="rotate(-90 60 60)"
                                    />

                                    <text x="60" y="55" text-anchor="middle" class="donut-total">{{ donutTotal }}</text>

                                    <text x="60" y="72" text-anchor="middle" class="donut-label">AUFTRÄGE</text>
                                </svg>

                                <div class="flex flex-1 flex-col gap-3">
                                    <div v-for="item in donutDistribution" :key="item.label" class="flex items-center gap-2">
                                        <span class="h-[8px] w-[8px] shrink-0 rounded-[3px]" :style="{ background: item.color }"></span>

                                        <span class="flex-1 text-[12px] font-semibold text-[#6f8585]">{{ item.label }}</span>

                                        <span class="text-[13px] font-extrabold text-[#10393b] tabular-nums">{{ item.count }}</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="content-card">
                            <div class="mb-5 flex items-center justify-between">
                                <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Systemstatus</h2>

                                <span class="rounded-full bg-[#01B990]/10 px-2.5 py-1 text-[11px] font-bold text-[#00856a]">3 / 4 aktiv</span>
                            </div>

                            <div class="flex flex-col gap-3">
                                <div v-for="service in services" :key="service.name" class="flex items-center gap-2.5">
                                    <span
                                        class="h-2 w-2 shrink-0 rounded-full"
                                        :class="
                                            service.status === 'ok'
                                                ? 'bg-[#01B990] shadow-[0_0_0_3px_rgba(1,185,144,0.15)]'
                                                : 'bg-[#ef8450] shadow-[0_0_0_3px_rgba(239,132,80,0.15)]'
                                        "
                                    ></span>

                                    <span class="flex-1 text-[13px] font-semibold text-[#1a2e2f]">{{ service.name }}</span>

                                    <span
                                        class="rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                        :class="service.status === 'ok' ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#ef8450]/10 text-[#c0622e]'"
                                    >
                                        {{ service.label }}
                                    </span>
                                </div>
                            </div>
                        </section>
                    </aside>
                </section>
            </main>
        </div>
    </AdminLayout>
</template>

<style scoped>
.dashboard-card {
    position: relative;
    min-height: 320px;
    overflow: hidden;
    border: 1px solid #e8efee;
    border-radius: 26px;
    background: #ffffff;
    padding: 28px;
    color: #10393b;
    text-align: left;
    box-shadow: 0 8px 28px rgba(16, 57, 59, 0.06);
    transition:
        transform 200ms ease,
        box-shadow 200ms ease,
        border-color 200ms ease,
        background 200ms ease;
}

.dashboard-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 38px rgba(16, 57, 59, 0.1);
}

.dashboard-card-active {
    border-color: #01b990;
    background: linear-gradient(145deg, #55bd99 0%, #0a8d70 100%);
    color: #ffffff;
    box-shadow: 0 20px 45px rgba(1, 185, 144, 0.24);
}

.content-card {
    border: 1px solid #eef3f2;
    border-radius: 24px;
    background: #ffffff;
    padding: 24px;
    box-shadow: 0 6px 22px rgba(16, 57, 59, 0.04);
}

.panel-search {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    border: 1px solid #e9efee;
    border-radius: 13px;
    background: #f4f7f6;
    padding: 10px 14px;
    color: #6f8585;
}

.panel-search-input {
    min-width: 0;
    flex: 1;
    border: 0;
    outline: 0;
    background: transparent;
    color: #1a2e2f;
    font-size: 13px;
}

.panel-search-input::placeholder {
    color: #9bb0af;
}

.search-clear {
    display: flex;
    width: 24px;
    height: 24px;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    color: #9bb0af;
    transition:
        background 150ms ease,
        color 150ms ease;
}

.search-clear:hover {
    background: #ffffff;
    color: #10393b;
}

.segment-button {
    border-radius: 9px;
    padding: 6px 14px;
    color: #6f8585;
    font-size: 12px;
    font-weight: 700;
    transition:
        background 150ms ease,
        color 150ms ease,
        box-shadow 150ms ease;
}

.segment-button:hover {
    color: #10393b;
}

.segment-button-active {
    background: #ffffff;
    color: #10393b;
    box-shadow: 0 1px 5px rgba(16, 57, 59, 0.1);
}

.status-pill {
    border-radius: 999px;
    padding: 6px 14px;
    background: #f4f7f6;
    color: #6f8585;
    font-size: 12px;
    font-weight: 700;
    transition:
        background 150ms ease,
        color 150ms ease;
}

.status-pill:hover {
    background: #eaf0ef;
    color: #10393b;
}

.status-pill-active {
    background: #10393b;
    color: #ffffff;
    box-shadow: 0 3px 10px rgba(16, 57, 59, 0.18);
}

.pagination-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 16px;
    border-top: 1px solid #eef3f2;
    padding-top: 12px;
    color: #9bb0af;
    font-size: 11.5px;
}

.lb-pg {
    display: flex;
    width: 32px;
    height: 32px;
    align-items: center;
    justify-content: center;
    border: 1px solid #eef3f2;
    border-radius: 8px;
    color: #6f8585;
    font-size: 12.5px;
    font-weight: 700;
    transition:
        border-color 150ms ease,
        background 150ms ease,
        color 150ms ease;
}

.lb-pg:hover:not(:disabled) {
    border-color: #10393b;
    color: #10393b;
}

.lb-pg:disabled {
    cursor: not-allowed;
    opacity: 0.35;
}

.lb-pg-active {
    border-color: #10393b;
    background: #10393b;
    color: #ffffff;
}

.lb-pg-active:hover:not(:disabled) {
    color: #ffffff;
}

.lb-pg-dot {
    cursor: default;
    border-color: transparent;
    color: #9bb0af;
}

.panel-enter-active {
    transition: all 220ms cubic-bezier(0.16, 1, 0.3, 1);
}

.panel-leave-active {
    transition: all 160ms ease;
}

.panel-enter-from {
    opacity: 0;
    transform: translateY(10px);
}

.panel-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}

.lb-pulse {
    animation: lb-pulse 2.2s infinite;
    box-shadow: 0 0 0 0 rgba(239, 132, 80, 0.5);
}

.donut-total {
    fill: #10393b;
    font-size: 24px;
    font-weight: 800;
}

.donut-label {
    fill: #9bb0af;
    font-size: 9px;
    font-weight: 600;
    letter-spacing: 0.08em;
}

@keyframes lb-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(239, 132, 80, 0.4);
    }

    70% {
        box-shadow: 0 0 0 12px rgba(239, 132, 80, 0);
    }

    100% {
        box-shadow: 0 0 0 0 rgba(239, 132, 80, 0);
    }
}

@media (max-width: 720px) {
    .dashboard-card {
        min-height: 300px;
        padding: 22px;
    }

    .content-card {
        padding: 18px;
    }

    .pagination-row {
        align-items: flex-start;
        flex-direction: column;
        gap: 10px;
    }
}
</style>
