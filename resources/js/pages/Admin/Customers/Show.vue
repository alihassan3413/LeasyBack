<script setup lang="ts">
import CreateVehicleModal from '@/components/admin/CreateVehicleModal.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import type { AdminCustomerDetail, AdminCustomerOrder, AdminCustomerType, AdminCustomerVehicle } from '@/types/admin';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    type: AdminCustomerType;
    customer: AdminCustomerDetail;
    vehicles: AdminCustomerVehicle[];
    orders: AdminCustomerOrder[];
}>();

const identifier = computed(() => (props.type === 'b2b' ? (props.customer.b2b_id ?? '') : String(props.customer.user_id ?? '')));

const displayName = computed(() => {
    if (props.type === 'b2b' && props.customer.company_name) {
        return props.customer.company_name;
    }

    return (
        [props.customer.salutation, props.customer.first_name, props.customer.last_name].filter(Boolean).join(' ') ||
        props.customer.user_email ||
        'Kunde'
    );
});

const initials = computed(() => {
    const source = props.type === 'b2b' && props.customer.company_name ? props.customer.company_name : displayName.value;
    const parts = source.trim().split(/\s+/).filter(Boolean);

    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    return source.slice(0, 2).toUpperCase() || '?';
});

const email = computed(() => props.customer.user_email ?? props.customer.contact_email ?? '—');

const addressLines = computed(() => {
    const streetLine = [props.customer.street, props.customer.number].filter(Boolean).join(' ');
    const cityLine = [props.customer.zip_code, props.customer.city].filter(Boolean).join(' ');

    return [streetLine, props.customer.additional_address, cityLine, props.customer.country].filter((line): line is string => !!line);
});

const activeVehicles = computed(() => props.vehicles.filter((vehicle) => vehicle.current_order_status !== null).length);
const openOrders = computed(() => props.orders.filter((order) => !['delivered', 'cancelled', 'discarded'].includes(order.order_status)).length);

const statusUpdating = ref(false);
const impersonating = ref(false);

function impersonate() {
    impersonating.value = true;

    router.post(route('admin.impersonate.store', props.customer.user_id), {}, { onFinish: () => (impersonating.value = false) });
}

function toggleStatus() {
    statusUpdating.value = true;

    router.patch(
        route('admin.customers.status', { type: props.type, id: identifier.value }),
        { is_active: !props.customer.is_active },
        { preserveScroll: true, onFinish: () => (statusUpdating.value = false) },
    );
}

function formatGermanDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const createVehicleOpen = ref(false);

const serviceFeeEditing = ref(false);

const serviceFeeForm = useForm({
    service_fee_amount: props.customer.service_fee_amount ?? '295.00',
    service_fee_effective_from: props.customer.service_fee_effective_from ?? '2026-01-01',
});

const serviceFeeDisplay = computed(() => {
    const amount = Number(props.customer.service_fee_amount);

    return Number.isFinite(amount) ? amount.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) : '—';
});

function startServiceFeeEditing() {
    serviceFeeForm.clearErrors();
    serviceFeeForm.service_fee_amount = props.customer.service_fee_amount ?? '295.00';
    serviceFeeForm.service_fee_effective_from = props.customer.service_fee_effective_from ?? '2026-01-01';
    serviceFeeEditing.value = true;
}

function cancelServiceFeeEditing() {
    serviceFeeForm.clearErrors();
    serviceFeeEditing.value = false;
}

function submitServiceFee() {
    serviceFeeForm.patch(route('admin.customers.service-fee', identifier.value), {
        preserveScroll: true,
        onSuccess: () => (serviceFeeEditing.value = false),
    });
}
</script>

<template>
    <Head :title="displayName" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <BackButton :href="route('admin.customers.index', { type })" label="Zurück zur Kundenliste" />

                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-bold tracking-[0.12em] text-[#9bb0af] uppercase">
                        {{ type === 'b2b' ? 'Firmenkunde' : 'Privatkunde' }}
                    </p>
                    <h1 class="truncate text-[16px] leading-tight font-extrabold tracking-[-0.3px] text-[#10393b]">{{ displayName }}</h1>
                </div>

                <button
                    v-if="customer.is_active && customer.user_id"
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6] disabled:opacity-50"
                    :disabled="impersonating"
                    @click="impersonate"
                >
                    <IconMdiAccountArrowRightOutline class="size-4" />
                    Als Kunde anmelden
                </button>

                <button
                    type="button"
                    class="mr-2 flex shrink-0 items-center gap-1.5 rounded-[13px] px-4 py-2 text-[12.5px] font-bold text-white transition-all hover:-translate-y-px disabled:opacity-50"
                    :style="
                        customer.is_active
                            ? 'background: linear-gradient(135deg, #ef8450, #e0703a); box-shadow: 0 8px 20px rgba(239, 132, 80, 0.24)'
                            : 'background: linear-gradient(135deg, #10393b, #1a5052); box-shadow: 0 8px 20px rgba(16, 57, 59, 0.2)'
                    "
                    :disabled="statusUpdating"
                    @click="toggleStatus"
                >
                    <IconMdiAccountOffOutline v-if="customer.is_active" class="size-4" />
                    <IconMdiAccountCheckOutline v-else class="size-4" />
                    {{ customer.is_active ? 'Deaktivieren' : 'Aktivieren' }}
                </button>
            </div>
        </template>

        <div class="flex h-full flex-col gap-5">
            <main class="flex flex-1 flex-col gap-5 overflow-y-auto pr-1 pb-4">
                <section class="grid grid-cols-[1.15fr_1fr_1fr] gap-4 max-[1100px]:grid-cols-1">
                    <div class="identity-card">
                        <div class="absolute -top-24 -right-20 h-64 w-64 rounded-full bg-white/10 blur-2xl"></div>

                        <div class="relative z-10 flex h-full flex-col">
                            <div class="flex items-start gap-4">
                                <div
                                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[19px] border border-white/25 bg-white/15 text-[19px] font-extrabold text-white"
                                >
                                    {{ initials }}
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[19px] font-extrabold tracking-[-0.4px] text-white">{{ displayName }}</p>
                                    <p class="mt-1 truncate text-[12.5px] text-white/70">{{ email }}</p>

                                    <span
                                        class="mt-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-extrabold"
                                        :class="customer.is_active ? 'bg-white/20 text-white' : 'bg-[#ef8450] text-white'"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ customer.is_active ? 'Aktiv' : 'Inaktiv' }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-auto grid grid-cols-2 gap-2 border-t border-white/20 pt-5">
                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">Kunde seit</p>
                                    <p class="mt-1 text-[15px] font-extrabold text-white">{{ formatGermanDate(customer.created_at) }}</p>
                                </div>

                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">ID</p>
                                    <p class="mt-1 truncate font-mono text-[13px] font-bold text-white">{{ identifier }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card flex flex-col">
                        <div class="mb-4 flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                                <IconMdiMapMarkerOutline class="size-[17px]" />
                            </span>
                            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Anschrift</h2>
                        </div>

                        <div v-if="addressLines.length" class="space-y-1">
                            <p v-for="line in addressLines" :key="line" class="text-[13.5px] leading-relaxed font-semibold text-[#10393b]">
                                {{ line }}
                            </p>
                        </div>

                        <p v-else class="text-[13px] text-[#9bb0af]">Keine Anschrift hinterlegt.</p>

                        <div v-if="type === 'b2b' && customer.vat_id" class="mt-auto border-t border-[#eef3f2] pt-4">
                            <p class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">USt-IdNr.</p>
                            <p class="mt-1 font-mono text-[13px] font-bold text-[#10393b]">{{ customer.vat_id }}</p>
                        </div>
                    </div>

                    <div class="content-card flex flex-col gap-3">
                        <div class="flex items-center justify-between rounded-[13px] bg-[#f4f7f6] px-4 py-3.5">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                                    <IconMdiCarMultiple class="size-[17px]" />
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Fahrzeuge</p>
                                    <p class="text-[11.5px] font-semibold text-[#6f8585]">{{ activeVehicles }} im Prozess</p>
                                </div>
                            </div>

                            <p class="text-[26px] leading-none font-extrabold text-[#10393b]">{{ vehicles.length }}</p>
                        </div>

                        <div class="flex items-center justify-between rounded-[13px] bg-[#f4f7f6] px-4 py-3.5">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#6366f1]/10 text-[#6366f1]">
                                    <IconMdiClipboardTextClockOutline class="size-[17px]" />
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Aufträge</p>
                                    <p class="text-[11.5px] font-semibold text-[#6f8585]">{{ openOrders }} offen</p>
                                </div>
                            </div>

                            <p class="text-[26px] leading-none font-extrabold text-[#10393b]">{{ orders.length }}</p>
                        </div>

                        <button
                            type="button"
                            class="mt-auto flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2.5 text-[13px] font-bold text-[#10393b] transition-all hover:border-[#01B990] hover:bg-[#f0fbf8] hover:text-[#00856a]"
                            @click="createVehicleOpen = true"
                        >
                            <IconMdiPlus class="size-4" />
                            Fahrzeug anlegen
                        </button>
                    </div>
                </section>

                <section v-if="type === 'b2b'" class="content-card">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#6366f1]/10 text-[#6366f1]">
                                <IconMdiCurrencyEur class="size-[17px]" />
                            </span>
                            <div>
                                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Kaufmännische Einstellungen</h2>
                                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">Jährliche Servicepauschale dieses Unternehmens</p>
                            </div>
                        </div>

                        <div class="flex shrink-0">
                            <button
                                v-if="!serviceFeeEditing"
                                type="button"
                                class="flex items-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#01B990] hover:bg-[#f0fbf8] hover:text-[#00856a]"
                                @click="startServiceFeeEditing"
                            >
                                <IconMdiPencilOutline class="size-4" />
                                Bearbeiten
                            </button>
                            <button
                                v-else
                                type="button"
                                class="flex items-center gap-1.5 rounded-[13px] px-4 py-2 text-[12.5px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b]"
                                @click="cancelServiceFeeEditing"
                            >
                                <IconMdiClose class="size-4" />
                                Abbrechen
                            </button>
                        </div>
                    </div>

                    <div v-if="!serviceFeeEditing" class="grid grid-cols-2 gap-3 max-[720px]:grid-cols-1">
                        <div class="rounded-[13px] bg-[#f4f7f6] px-4 py-3.5">
                            <p class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Servicepauschale</p>
                            <p class="mt-1 text-[20px] leading-none font-extrabold text-[#10393b]">{{ serviceFeeDisplay }}</p>
                        </div>

                        <div class="rounded-[13px] bg-[#f4f7f6] px-4 py-3.5">
                            <p class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Gültig ab</p>
                            <p class="mt-1 text-[20px] leading-none font-extrabold text-[#10393b]">
                                {{ formatGermanDate(customer.service_fee_effective_from ?? null) }}
                            </p>
                        </div>
                    </div>

                    <form v-else class="flex flex-col gap-4" @submit.prevent="submitServiceFee">
                        <div class="grid grid-cols-2 gap-3 max-[720px]:grid-cols-1">
                            <div>
                                <label for="service_fee_amount" class="text-[12.5px] font-bold text-[#10393b]">Servicepauschale (EUR, netto)</label>
                                <input
                                    id="service_fee_amount"
                                    v-model="serviceFeeForm.service_fee_amount"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="mt-1 w-full rounded-[13px] border border-[#e9efee] bg-white px-3.5 py-2.5 text-[13.5px] font-semibold text-[#10393b] outline-none focus:border-[#01B990]"
                                />
                                <p v-if="serviceFeeForm.errors.service_fee_amount" class="mt-1 text-[11.5px] text-[#ef8450]">
                                    {{ serviceFeeForm.errors.service_fee_amount }}
                                </p>
                            </div>

                            <div>
                                <label for="service_fee_effective_from" class="text-[12.5px] font-bold text-[#10393b]">Gültig ab</label>
                                <input
                                    id="service_fee_effective_from"
                                    v-model="serviceFeeForm.service_fee_effective_from"
                                    type="date"
                                    class="mt-1 w-full rounded-[13px] border border-[#e9efee] bg-white px-3.5 py-2.5 text-[13.5px] font-semibold text-[#10393b] outline-none focus:border-[#01B990]"
                                />
                                <p v-if="serviceFeeForm.errors.service_fee_effective_from" class="mt-1 text-[11.5px] text-[#ef8450]">
                                    {{ serviceFeeForm.errors.service_fee_effective_from }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="rounded-[13px] bg-[#10393b] px-6 py-2.5 text-[13px] font-bold text-white transition-all hover:-translate-y-px disabled:opacity-50"
                                :disabled="serviceFeeForm.processing"
                            >
                                {{ serviceFeeForm.processing ? 'Wird gespeichert…' : 'Speichern' }}
                            </button>
                        </div>
                    </form>
                </section>

                <section v-if="type === 'b2b' && customer.members?.length" class="content-card">
                    <h2 class="mb-4 text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Mitglieder</h2>

                    <div class="flex flex-col gap-1">
                        <div
                            v-for="member in customer.members"
                            :key="member.user_id"
                            class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                        >
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] text-[11px] font-extrabold text-white"
                                style="background: linear-gradient(150deg, #01b990, #10393b)"
                            >
                                {{ member.user_email.slice(0, 2).toUpperCase() }}
                            </div>

                            <p class="min-w-0 flex-1 truncate text-[13px] font-semibold text-[#10393b]">{{ member.user_email }}</p>

                            <span class="rounded-full bg-[#f4f7f6] px-2.5 py-1 text-[11px] font-bold text-[#6f8585]">{{ member.role }}</span>
                        </div>
                    </div>
                </section>

                <section class="grid grid-cols-2 gap-4 max-[1180px]:grid-cols-1">
                    <div class="content-card">
                        <div class="mb-4">
                            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Fahrzeuge</h2>
                            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ vehicles.length }} Fahrzeuge</p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <p v-if="!vehicles.length" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Fahrzeuge vorhanden.</p>

                            <Link
                                v-for="vehicle in vehicles"
                                :key="vehicle.vehicle_id"
                                :href="route('admin.vehicles.show', vehicle.vehicle_id)"
                                class="group flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                                    <IconMdiCarOutline class="size-[18px]" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-bold text-[#10393b]">{{ vehicle.make }} {{ vehicle.model }}</p>
                                    <p class="truncate font-mono text-[11.5px] text-[#6f8585]">{{ vehicle.license_plate }}</p>
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

                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[8px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white"
                                >
                                    <IconMdiArrowTopRight class="size-[13px]" />
                                </span>
                            </Link>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="mb-4">
                            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Aufträge</h2>
                            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ orders.length }} Aufträge</p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <p v-if="!orders.length" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Aufträge vorhanden.</p>

                            <Link
                                v-for="order in orders"
                                :key="order.id"
                                :href="route('admin.orders.show', order.id)"
                                class="group flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#6366f1]/10 text-[#6366f1]">
                                    <IconMdiFileDocumentOutline class="size-[17px]" />
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

                                    <span class="hidden text-[11px] text-[#9bb0af] tabular-nums lg:block">
                                        {{ formatGermanDate(order.created_at) }}
                                    </span>
                                </div>
                            </Link>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        <CreateVehicleModal v-model:open="createVehicleOpen" :type="type" :owner-id="identifier" />
    </AdminLayout>
</template>

<style scoped>
.identity-card {
    position: relative;
    min-height: 220px;
    overflow: hidden;
    border: 1px solid #01b990;
    border-radius: 26px;
    background: linear-gradient(145deg, #55bd99 0%, #0a8d70 100%);
    padding: 28px;
    box-shadow: 0 20px 45px rgba(1, 185, 144, 0.24);
}

.content-card {
    border: 1px solid #eef3f2;
    border-radius: 24px;
    background: #ffffff;
    padding: 24px;
    box-shadow: 0 6px 22px rgba(16, 57, 59, 0.04);
}

button:not(:disabled) {
    cursor: pointer;
}
</style>
