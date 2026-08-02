<script setup lang="ts">
import AdminOffersCard from '@/components/admin/AdminOffersCard.vue';
import AdminOrderActionsMenu from '@/components/admin/AdminOrderActionsMenu.vue';
import OrderStatusTimeline from '@/components/shared/OrderStatusTimeline.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import { getCustomerOrderFlowSteps, getCustomerOrderHeadline } from '@/lib/customerOrderFlow';
import { toOrderTimelineEntries } from '@/lib/timeline';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';
import type { AdminOrderDetail } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ order: AdminOrderDetail }>();

const ownerRoute = computed(() => {
    if (props.order.user_type === 'Firmenkunde' && props.order.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: props.order.b2b_id });
    }

    if (props.order.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: props.order.user_id });
    }

    return null;
});

const ownerLabel = computed(() => props.order.company_name || props.order.user_email || 'Nicht zugeordnet');
const vehicleTitle = computed(() => [props.order.make, props.order.model].filter(Boolean).join(' ') || 'Ohne Marke');

/**
 * The same customer-flow timeline the dashboard's VehicleExpandedPanel.vue
 * renders, so Admin and customer never disagree about where an order stands.
 * (This replaces a `<OrderStatusTimeline :status="…" />` call that passed a
 * prop the component does not have — its required `entries` was missing, so
 * the timeline rendered empty.)
 *
 * `besichtigungsort` is the one input orderDetail() has no column for; the
 * appointment step then simply carries no address, which
 * getCustomerOrderFlowSteps() already handles.
 */
const customerFlowSteps = computed(() =>
    getCustomerOrderFlowSteps({
        orderStatus: props.order.order_status,
        orderCreatedAt: props.order.created_at,
        statusHistory: props.order.status_updates,
        reportDocuments: props.order.report_documents.map((document) => ({
            document_type: document.document_type,
            document_title: document.document_title,
            created_at: document.created_at,
            url: document.signed_url,
        })),
        offers: props.order.offers,
    }),
);

const customerHeadline = computed(() => getCustomerOrderHeadline(customerFlowSteps.value));

const timelineHeaderLabel = computed(
    () => `STATUS: ${(customerHeadline.value?.label ?? getOrderStatusLabel(props.order.order_status)).toUpperCase()}`,
);

const timelineEntries = computed(() => toOrderTimelineEntries(customerFlowSteps.value, props.order.order_status));

const specs = computed(() => [
    { label: 'Kennzeichen', value: props.order.license_plate, mono: true },
    { label: 'FIN', value: props.order.vin || '—', mono: true },
    { label: 'Partner', value: props.order.leasyback_partner },
    { label: 'Gesendet am', value: formatDate(props.order.sent_at) },
    { label: 'Bestätigt am', value: formatDate(props.order.confirmation_date) },
    { label: 'Angelegt am', value: formatDate(props.order.created_at) },
]);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('de-DE');
}
</script>

<template>
    <Head :title="order.auftragsnummer" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <BackButton :href="route('admin.orders.index')" label="Zurück zur Auftragsliste" />

                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-bold tracking-[0.12em] text-[#9bb0af] uppercase">
                        {{ order.user_type === 'Firmenkunde' ? 'Firmenkunde' : 'Privatkunde' }}
                    </p>
                    <h1 class="truncate text-[16px] leading-tight font-extrabold tracking-[-0.3px] text-[#10393b]">
                        {{ order.auftragsnummer }} · {{ order.license_plate }}
                    </h1>
                </div>

                <div class="mr-2 shrink-0">
                    <!--
                        No `stations` here on purpose: creating an order belongs on the
                        vehicle, not inside an existing order, so that entry stays disabled.
                    -->
                    <AdminOrderActionsMenu
                        :order-id="order.id"
                        :auftragsnummer="order.auftragsnummer"
                        :vehicle-id="order.vehicle_id"
                        :order-status="order.order_status"
                        :available-transitions="order.available_transitions"
                        :can-pull-documents="order.can_pull_documents"
                    />
                </div>
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
                                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[19px] border border-white/25 bg-white/15 text-white"
                                >
                                    <IconMdiFileDocumentOutline class="size-8" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-mono text-[19px] font-extrabold tracking-[-0.4px] text-white">
                                        {{ order.auftragsnummer }}
                                    </p>
                                    <p class="mt-1 truncate text-[12.5px] text-white/70">{{ vehicleTitle }}</p>

                                    <span
                                        class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/20 px-2.5 py-1 text-[11px] font-extrabold text-white"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ getStatus(order.order_status).label }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-auto grid grid-cols-2 gap-2 border-t border-white/20 pt-5">
                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">Angebote</p>
                                    <p class="mt-1 text-[15px] font-extrabold text-white">{{ order.offers.length }}</p>
                                </div>

                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">Dokumente</p>
                                    <p class="mt-1 text-[15px] font-extrabold text-white">{{ order.report_documents.length }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card flex flex-col">
                        <div class="mb-4 flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                                <IconMdiAccountOutline class="size-[17px]" />
                            </span>
                            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Halter</h2>
                        </div>

                        <p class="text-[14px] font-bold text-[#10393b]">{{ ownerLabel }}</p>
                        <p v-if="order.company_name && order.user_email" class="mt-1 truncate text-[12.5px] text-[#6f8585]">
                            {{ order.user_email }}
                        </p>

                        <div class="mt-auto flex flex-col gap-2 pt-4">
                            <Link
                                :href="route('admin.vehicles.show', order.vehicle_id)"
                                class="flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2.5 text-[13px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                            >
                                Fahrzeug öffnen
                                <IconMdiArrowTopRight class="size-4" />
                            </Link>

                            <Link
                                v-if="ownerRoute"
                                :href="ownerRoute"
                                class="flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2.5 text-[13px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                            >
                                Kundenprofil öffnen
                                <IconMdiArrowTopRight class="size-4" />
                            </Link>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="mb-4 flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                                <IconMdiClipboardTextOutline class="size-[17px]" />
                            </span>
                            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Auftragsdaten</h2>
                        </div>

                        <dl class="flex flex-col">
                            <div
                                v-for="spec in specs"
                                :key="spec.label"
                                class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2 last:border-0"
                            >
                                <dt class="text-[12px] font-medium text-[#9bb0af]">{{ spec.label }}</dt>
                                <dd class="truncate text-[12.5px] font-bold text-[#10393b]" :class="spec.mono ? 'font-mono' : ''">
                                    {{ spec.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </section>

                <section class="grid grid-cols-[1fr_1.15fr] gap-4 max-[1180px]:grid-cols-1">
                    <div class="content-card overflow-hidden p-0">
                        <OrderStatusTimeline :entries="timelineEntries" :header-label="timelineHeaderLabel">
                            <template #actions="{ entry }">
                                <a
                                    v-if="entry.docUrl"
                                    :href="entry.docUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[#01b990] hover:opacity-70"
                                    title="Gutachten öffnen"
                                >
                                    <IconMdiOpenInNew class="size-[18.5px] shrink-0" />
                                </a>
                                <a
                                    v-if="entry.invoiceUrl"
                                    :href="entry.invoiceUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[#01b990] hover:opacity-70"
                                    title="Rechnung ansehen"
                                >
                                    <IconMdiReceiptTextOutline class="size-[18.5px] shrink-0" />
                                </a>
                            </template>
                        </OrderStatusTimeline>
                    </div>

                    <div class="flex flex-col gap-4">
                        <AdminOffersCard :order-id="order.id" :offers="order.offers" />

                        <div class="content-card">
                            <div class="mb-4">
                                <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachten &amp; Rechnungen</h2>
                                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ order.report_documents.length }} Dokumente</p>
                            </div>

                            <p v-if="!order.report_documents.length" class="py-10 text-center text-[13px] text-[#9bb0af]">
                                Keine Dokumente vorhanden.
                            </p>

                            <div v-else class="flex flex-col gap-1">
                                <div
                                    v-for="doc in order.report_documents"
                                    :key="doc.id"
                                    class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                                >
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                                        <IconMdiFileDocumentOutline class="size-[17px]" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-bold text-[#10393b]">
                                            {{ doc.document_title || doc.document_type || 'Dokument' }}
                                        </p>
                                        <p class="truncate text-[11.5px] text-[#6f8585]">{{ formatDate(doc.created_at) }}</p>
                                    </div>

                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                        :class="doc.published ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#9bb0af]'"
                                    >
                                        {{ doc.published ? 'Veröffentlicht' : 'Entwurf' }}
                                    </span>

                                    <a
                                        v-if="doc.signed_url"
                                        :href="doc.signed_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#10393b] hover:text-white"
                                        title="Öffnen"
                                    >
                                        <IconMdiOpenInNew class="size-[15px]" />
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="content-card">
                    <div class="mb-4">
                        <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Statusverlauf</h2>
                        <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ order.status_updates.length }} Änderungen</p>
                    </div>

                    <p v-if="!order.status_updates.length" class="py-10 text-center text-[13px] text-[#9bb0af]">Keine Statusänderungen.</p>

                    <div v-else class="overflow-auto rounded-[18px] border border-[#eef3f2]">
                        <table class="w-full min-w-[640px] border-collapse">
                            <thead>
                                <tr class="bg-[#f8faf9]">
                                    <th class="admin-th">Von</th>
                                    <th class="admin-th">Nach</th>
                                    <th class="admin-th">Durch</th>
                                    <th class="admin-th">Zeitpunkt</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr v-for="update in order.status_updates" :key="update.id" class="border-b border-[#eef3f2] last:border-0">
                                    <td class="px-5 py-3 text-[12.5px] text-[#6f8585]">{{ getStatus(update.old_status).label }}</td>
                                    <td class="px-5 py-3">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                            :style="{
                                                background: getStatus(update.new_status).background,
                                                color: getStatus(update.new_status).color,
                                            }"
                                        >
                                            <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                            {{ getStatus(update.new_status).label }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[12.5px] text-[#5a6e6c]">{{ update.updated_by }}</td>
                                    <td class="px-5 py-3 text-[12px] text-[#9bb0af] tabular-nums">{{ formatDateTime(update.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
        </div>
    </AdminLayout>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
