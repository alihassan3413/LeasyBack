<script setup lang="ts">
import CreateVehicleModal from '@/components/admin/CreateVehicleModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminCustomerList, AdminCustomerListItem, AdminCustomerType } from '@/types/admin';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    type: AdminCustomerType;
    customers: AdminCustomerList;
    filters: { search: string; is_active: string | null };
}>();

const search = ref(props.filters.search);
const statusFilter = ref<'' | 'active' | 'inactive'>(
    props.filters.is_active === 'true' ? 'active' : props.filters.is_active === 'false' ? 'inactive' : '',
);
const loading = ref(false);

const statusFilterOptions: { label: string; value: '' | 'active' | 'inactive' }[] = [
    { label: 'Alle', value: '' },
    { label: 'Aktiv', value: 'active' },
    { label: 'Inaktiv', value: 'inactive' },
];

const pageTitle = computed(() => (props.type === 'b2c' ? 'Privatkunden' : 'Firmenkunden'));
const page = computed(() => props.customers.page);
const totalPages = computed(() => Math.max(1, Math.ceil(props.customers.total / props.customers.limit)));
const hasQuery = computed(() => search.value !== '' || statusFilter.value !== '');

function reload(overrides: Record<string, string | undefined> = {}) {
    loading.value = true;

    router.get(
        route('admin.customers.index'),
        {
            type: props.type,
            search: search.value || undefined,
            is_active: statusFilter.value === 'active' ? 'true' : statusFilter.value === 'inactive' ? 'false' : undefined,
            ...overrides,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            // `type` has to reload too: it drives the tab state and the row links.
            only: ['type', 'customers', 'filters'],
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

function setStatusFilter(value: '' | 'active' | 'inactive') {
    if (statusFilter.value === value) {
        return;
    }

    statusFilter.value = value;
    reload();
}

// Search and status filter carry across the tab switch, so the inputs never
// show a query the results below aren't actually filtered by.
function switchType(type: AdminCustomerType) {
    if (type === props.type) {
        return;
    }

    reload({ type });
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

function userInitials(customer: AdminCustomerListItem): string {
    return ((customer.first_name?.[0] ?? '') + (customer.last_name?.[0] ?? '')).toUpperCase() || '?';
}

function displayName(customer: AdminCustomerListItem): string {
    if (props.type === 'b2b' && customer.company_name) {
        return customer.company_name;
    }

    return [customer.salutation, customer.first_name, customer.last_name].filter(Boolean).join(' ') || customer.user_email;
}

function ownerId(customer: AdminCustomerListItem): string {
    return props.type === 'b2b' ? (customer.b2b_id ?? '') : String(customer.user_id);
}

function formatGermanDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function openDetail(customer: AdminCustomerListItem) {
    router.visit(route('admin.customers.show', { type: props.type, id: ownerId(customer) }));
}

// B2B rows are company records; the session takeover targets a real user
// account, which for a company row is the member the list joined on.
function impersonate(customer: AdminCustomerListItem) {
    router.post(route('admin.impersonate.store', customer.user_id), {}, { preserveScroll: true });
}

const createVehicleOpen = ref(false);
const createVehicleOwner = ref<AdminCustomerListItem | null>(null);

function openCreateVehicle(customer: AdminCustomerListItem) {
    createVehicleOwner.value = customer;
    createVehicleOpen.value = true;
}
</script>

<template>
    <Head title="Kunden" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
                <h1 class="shrink-0 text-[16px] font-extrabold tracking-[-0.3px] text-[#10393b]">Kundenverwaltung</h1>

                <div class="admin-search basis-full md:ml-auto md:basis-0">
                    <IconMdiMagnify class="size-4 shrink-0" />

                    <input
                        v-model="search"
                        type="search"
                        placeholder="Name, E-Mail, Stadt…"
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
            <section
                class="flex flex-col rounded-[24px] border border-[#eef3f2] bg-white p-3 sm:p-6 md:min-h-0 md:flex-1"
                style="box-shadow: 0 6px 22px rgba(16, 57, 59, 0.04)"
            >
                <div class="mb-5 flex shrink-0 flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-[20px] font-extrabold tracking-[-0.4px] text-[#10393b]">{{ pageTitle }}</h2>

                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <p class="text-[12px] font-medium text-[#9bb0af]">{{ customers.total }} Kunden{{ hasQuery ? ' gefunden' : ' gesamt' }}</p>
                            <span class="h-[3px] w-[3px] rounded-full bg-[#d3dedd]"></span>
                            <span class="rounded-full bg-[#01B990]/10 px-2.5 py-1 text-[11px] font-bold text-[#00856a]">
                                {{ customers.total_active }} Aktiv
                            </span>
                            <span class="rounded-full bg-[#ef8450]/10 px-2.5 py-1 text-[11px] font-bold text-[#c0622e]">
                                {{ customers.total_inactive }} Inaktiv
                            </span>
                        </div>
                    </div>

                    <div class="flex w-full min-w-0 flex-wrap items-center gap-2 md:w-auto">
                        <div class="flex min-w-0 flex-wrap gap-0.5 rounded-[12px] bg-[#f4f7f6] p-[3px]">
                            <button
                                type="button"
                                class="rounded-[9px] px-4 py-1.5 text-[12.5px] font-bold transition-all"
                                :class="
                                    type === 'b2c'
                                        ? 'bg-white text-[#10393b] shadow-[0_1px_5px_rgba(16,57,59,0.1)]'
                                        : 'text-[#6f8585] hover:text-[#10393b]'
                                "
                                @click="switchType('b2c')"
                            >
                                Privatkunden
                            </button>

                            <button
                                type="button"
                                class="rounded-[9px] px-4 py-1.5 text-[12.5px] font-bold transition-all"
                                :class="
                                    type === 'b2b'
                                        ? 'bg-white text-[#10393b] shadow-[0_1px_5px_rgba(16,57,59,0.1)]'
                                        : 'text-[#6f8585] hover:text-[#10393b]'
                                "
                                @click="switchType('b2b')"
                            >
                                Firmenkunden
                            </button>
                        </div>

                        <span class="hidden h-6 w-px bg-[#eef3f2] sm:block"></span>

                        <div class="filter-rail flex min-w-0 gap-1.5 sm:flex-wrap">
                            <button
                                v-for="option in statusFilterOptions"
                                :key="option.value"
                                type="button"
                                class="shrink-0 rounded-full px-3.5 py-1.5 text-[12px] font-bold whitespace-nowrap transition-all"
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
                    </div>
                </div>

                <!--
                    Below `md` the card grows with its content and the shell
                    scrolls; `flex-1` inside a `h-dvh` column collapsed this to
                    zero height once the filters wrapped, which is why the table
                    disappeared under the status filters on phones.
                -->
                <div class="w-full overflow-x-auto rounded-[18px] border border-[#eef3f2] md:min-h-0 md:flex-1 md:overflow-y-auto">
                    <table class="w-full border-collapse sm:min-w-[760px]">
                        <thead class="z-10 md:sticky md:top-0">
                            <tr class="bg-[#f8faf9]">
                                <th class="admin-th">Kunde</th>
                                <th class="admin-th hidden sm:table-cell">E-Mail</th>
                                <th class="admin-th hidden md:table-cell">Stadt</th>
                                <th class="admin-th hidden md:table-cell">Land</th>
                                <th class="admin-th hidden sm:table-cell">Status</th>
                                <th class="admin-th hidden md:table-cell">Beigetreten</th>
                                <th class="w-[76px] border-b border-[#eef3f2] sm:w-12"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-if="loading">
                                <tr v-for="item in 8" :key="item">
                                    <td colspan="7" class="px-5 py-4">
                                        <div class="h-4 animate-pulse rounded-full bg-[#f4f7f6]" :style="{ width: 55 + (item % 5) * 9 + '%' }"></div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-else-if="!customers.data.length">
                                <td colspan="7" class="py-16 text-center text-[13px] text-[#9bb0af]">Keine Kunden gefunden.</td>
                            </tr>

                            <tr
                                v-for="customer in loading ? [] : customers.data"
                                :key="customer.user_id"
                                class="group cursor-pointer border-b border-[#eef3f2] transition-colors hover:bg-[#f6f9f8]"
                                @click="openDetail(customer)"
                            >
                                <td class="px-3 py-3.5 sm:px-5">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-[12px] font-extrabold text-white"
                                            style="background: linear-gradient(150deg, #01b990, #10393b)"
                                        >
                                            {{ userInitials(customer) }}
                                        </div>

                                        <div class="min-w-0">
                                            <div class="truncate text-[13.5px] font-bold text-[#10393b]">{{ displayName(customer) }}</div>
                                            <!-- Stands in for the E-Mail column, which is hidden at this width. -->
                                            <div class="mt-0.5 truncate text-[11px] text-[#5a6e6c] sm:hidden">{{ customer.user_email }}</div>
                                            <div class="mt-0.5 truncate text-[11px] text-[#9bb0af]">ID {{ ownerId(customer) }}</div>

                                            <!-- Stands in for the Status column, which is hidden at this width. -->
                                            <span
                                                class="mt-1.5 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold sm:hidden"
                                                :class="customer.is_active ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#ef8450]/10 text-[#c0622e]'"
                                            >
                                                <span class="h-[5px] w-[5px] shrink-0 rounded-full bg-current"></span>
                                                {{ customer.is_active ? 'Aktiv' : 'Inaktiv' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td class="hidden px-3 py-3.5 text-[13px] text-[#5a6e6c] sm:table-cell sm:px-5">{{ customer.user_email }}</td>
                                <td class="hidden px-3 py-3.5 text-[13px] text-[#5a6e6c] sm:px-5 md:table-cell">{{ customer.city || '—' }}</td>
                                <td class="hidden px-3 py-3.5 text-[13px] text-[#5a6e6c] sm:px-5 md:table-cell">{{ customer.country || '—' }}</td>

                                <td class="hidden px-3 py-3.5 sm:table-cell sm:px-5">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold whitespace-nowrap"
                                        :class="customer.is_active ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#ef8450]/10 text-[#c0622e]'"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ customer.is_active ? 'Aktiv' : 'Inaktiv' }}
                                    </span>
                                </td>

                                <td class="hidden px-3 py-3.5 text-[12.5px] text-[#9bb0af] tabular-nums sm:px-5 md:table-cell">
                                    {{ formatGermanDate(customer.created_at) }}
                                </td>

                                <td class="px-2 py-3.5 sm:px-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            v-if="customer.is_active"
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#10393b] hover:text-white"
                                            title="Als dieser Kunde anmelden"
                                            @click.stop="impersonate(customer)"
                                        >
                                            <IconMdiAccountArrowRightOutline class="size-[16px]" />
                                        </button>

                                        <button
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#EF8450] hover:text-white"
                                            title="Fahrzeug erstellen"
                                            @click.stop="openCreateVehicle(customer)"
                                        >
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 5v14M5 12h14" />
                                            </svg>
                                        </button>

                                        <!-- Purely decorative affordance; dropped where width is scarce. -->
                                        <span
                                            class="hidden h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white sm:flex"
                                        >
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M7 17L17 7M17 7H8M17 7v9" />
                                            </svg>
                                        </span>
                                    </div>
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

        <CreateVehicleModal v-if="createVehicleOwner" v-model:open="createVehicleOpen" :type="type" :owner-id="ownerId(createVehicleOwner)" />
    </AdminLayout>
</template>

<style scoped>
/* .admin-th / .admin-search / .lb-pg live in resources/css/app.css — shared by
   every Admin/*\/Index.vue. They used to be duplicated here and drifted. */
button:not(:disabled) {
    cursor: pointer;
}
</style>
