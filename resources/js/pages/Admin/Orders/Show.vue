<script setup lang="ts">
import AdminAppraisalPositionsCard from '@/components/admin/AdminAppraisalPositionsCard.vue';
import AdminBillingCard from '@/components/admin/AdminBillingCard.vue';
import AdminCollectionCard from '@/components/admin/AdminCollectionCard.vue';
import AdminOffersCard from '@/components/admin/AdminOffersCard.vue';
import AdminOrderActionsMenu from '@/components/admin/AdminOrderActionsMenu.vue';
import AdminOrderNotesCard from '@/components/admin/AdminOrderNotesCard.vue';
import AdminOrderTasksCard from '@/components/admin/AdminOrderTasksCard.vue';
import AdminRepairAppointmentCard from '@/components/admin/AdminRepairAppointmentCard.vue';
import AdminWorkshopCommissionCard from '@/components/admin/AdminWorkshopCommissionCard.vue';
import AdminWorkshopQuotationsCard from '@/components/admin/AdminWorkshopQuotationsCard.vue';
import MasonryGrid from '@/components/shared/MasonryGrid.vue';
import OrderMessages from '@/components/shared/OrderMessages.vue';
import OrderStatusTimeline from '@/components/shared/OrderStatusTimeline.vue';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import { getCustomerOrderFlowSteps, getCustomerOrderHeadline } from '@/lib/customerOrderFlow';
import { formatPortalDate, formatPortalDateTimeShort } from '@/lib/portalDate';
import { toOrderTimelineEntries } from '@/lib/timeline';
import { getOrderStatusLabel } from '@/lib/vehicleStatus';
import type { AdminOrderDetail, AdminOrderTaskAction } from '@/types/admin';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ order: AdminOrderDetail }>();

// This order only. The repair charge settles through a Stripe webhook long
// after the customer paid, and `confirm_pickup` stays shut until it lands —
// so without this the page waits for someone to press reload.
useLiveUpdates((notification) => notification.meta.order_id === props.order.id);

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
            published: document.published,
        })),
        offers: props.order.offers,
        collection: props.order.collection,
        channel: props.order.vehicle_belongs,
        // The same derived stage the customer's payload carries, rendered with
        // Admin's wording. `payable` is deliberately not passed: Admin is
        // refused by OrderPolicy::pay and must never be offered a pay action.
        repairPayment: {
            stage: props.order.repair_payment_stage,
            status: props.order.repair_payment?.status ?? null,
            amount_cents: props.order.repair_payment?.amount_cents ?? null,
        },
        audience: 'admin',
    }),
);

const customerHeadline = computed(() => getCustomerOrderHeadline(customerFlowSteps.value));

/**
 * The repair appointment only becomes relevant once the workshop is
 * commissioned, and stays visible afterwards so it can be rescheduled.
 */
const REPAIR_APPOINTMENT_STATUSES = new Set(['workshop_commissioned', 'workshop', 'repair_completed']);

/**
 * Shown only where there is something to say: a workshop that can be
 * commissioned, one already commissioned, or a blocker an admin has to act on
 * themselves. The server decides whether the action is legal; this only decides
 * whether the card earns its space.
 */
const showCommissionCard = computed(
    () =>
        props.order.workshop_commission.is_commissioned ||
        props.order.workshop_commission.can_commission ||
        props.order.workshop_commission.blocked_reason === 'manual_offer' ||
        props.order.workshop_commission.blocked_reason === 'no_workshop_contact',
);

const showRepairAppointment = computed(
    () => REPAIR_APPOINTMENT_STATUSES.has(props.order.order_status) || !!props.order.collection?.confirmed_repair_start_date,
);

const actionsMenu = ref<InstanceType<typeof AdminOrderActionsMenu> | null>(null);
const offersCard = ref<InstanceType<typeof AdminOffersCard> | null>(null);

/**
 * Where a task's `modal` action is carried out. Keyed by the resolver's UI
 * handler names, so adding a task means adding a definition on the server and —
 * only if it needs a new kind of UI — one entry here. Every handler drives a
 * component that already exists; none of them duplicates a workflow.
 */
const TASK_MODAL_HANDLERS: Record<string, (preset: Record<string, string>) => void> = {
    upload_report: (preset) => actionsMenu.value?.openUpload(preset.document_type ?? 'gutachten'),
    create_offer: () => offersCard.value?.openCreate() ?? actionsMenu.value?.openCreateOffer(),
};

function focusSection(section: string) {
    const target = document.getElementById(`order-section-${section}`);

    if (!target) {
        return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    target.classList.add('task-target');
    window.setTimeout(() => target.classList.remove('task-target'), 1600);

    // Put the cursor in the form rather than only showing it — that is the
    // difference between "here is the section" and "here is the task".
    const field = target.querySelector<HTMLElement>(
        'input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [role=combobox]',
    );

    field?.focus({ preventScroll: true });
}

function handleTaskAction(action: AdminOrderTaskAction) {
    if (action.type === 'inline') {
        focusSection(action.key);

        return;
    }

    if (action.type === 'modal') {
        TASK_MODAL_HANDLERS[action.key]?.(action.payload ?? {});
    }
}

/**
 * Billing becomes relevant once the vehicle is back with the leasing company,
 * and stays visible afterwards as the record the completion gate reads.
 */
const BILLING_STATUSES = new Set(['vehicle_returned', 'invoice_processed', 'completed']);

const showBilling = computed(() => BILLING_STATUSES.has(props.order.order_status) || !!props.order.billing?.is_processed);

/**
 * The quotation behind the offer that is actually going ahead — it seeds the
 * repair appointment form with that workshop's earliest start and duration.
 *
 * Resolved from the accepted offer, then a published one, and from nothing at
 * all otherwise. Taking the first offer that merely *had* a presentation meant
 * that once a customer rejected one and accepted the next, the form was
 * pre-filled with dates from the workshop that lost — plausible enough for an
 * admin to accept without noticing. A rejected-only order seeds nothing,
 * because no workshop has been agreed.
 */
const offerSourceQuotation = computed(() => {
    const presented = props.order.offers.filter((offer) => offer.presentation);
    const source =
        presented.find((offer) => offer.offer_status === 'selected') ?? presented.find((offer) => offer.offer_status === 'published') ?? null;
    const quotationId = source?.presentation?.workshop_quotation_id;

    return (quotationId && props.order.workshop_quotations.find((quotation) => quotation.id === quotationId)) || null;
});

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
    return formatPortalDate(value) || '—';
}

function formatDateTime(value: string | null): string {
    return formatPortalDateTimeShort(value) || '—';
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
                        ref="actionsMenu"
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

        <div class="flex flex-col gap-5">
            <main class="flex flex-col gap-5 pb-4">
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

                <!--
                    Packed rather than split into two fixed columns. Almost every card
                    below is conditional — B2B-only, or gated on the order having reached
                    a status — so no static left/right split balances for both audiences.
                    MasonryGrid drops each card into the first slot it fits, so a short
                    card backfills the gap a tall neighbour leaves, and the section
                    collapses to one column below 1180px.
                -->
                <MasonryGrid class="grid-cols-1 min-[1180px]:grid-cols-2">
                    <div id="order-section-status" class="content-card overflow-hidden p-0">
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

                    <AdminOrderTasksCard :tasks="order.tasks" @action="handleTaskAction" />

                    <OrderMessages :order-id="order.id" :auftragsnummer="order.auftragsnummer" container-class="content-card overflow-hidden p-0" />

                    <AdminCollectionCard
                        v-if="order.vehicle_belongs === 'B2B'"
                        id="order-section-abholung"
                        :order-id="order.id"
                        :collection="order.collection"
                    />

                    <AdminOrderNotesCard
                        v-if="order.vehicle_belongs === 'B2B' && order.notes"
                        id="order-section-notizen"
                        :order-id="order.id"
                        :notes="order.notes"
                    />

                    <AdminBillingCard
                        v-if="order.vehicle_belongs === 'B2B' && order.billing && showBilling"
                        id="order-section-abrechnung"
                        :order-id="order.id"
                        :billing="order.billing"
                        :report-documents="order.report_documents"
                    />

                    <!--
                        Commissioning and the repair appointment are the two halves of the
                        same step — who was instructed, and when they start — so they sit
                        together. Both channels: the commission card works out for itself
                        whether this order has a workshop to commission.
                    -->
                    <AdminWorkshopCommissionCard
                        v-if="showCommissionCard"
                        id="order-section-beauftragung"
                        :order-id="order.id"
                        :commission="order.workshop_commission"
                    />

                    <AdminRepairAppointmentCard
                        v-if="showRepairAppointment"
                        id="order-section-reparatur"
                        :order-id="order.id"
                        :order-status="order.order_status"
                        :collection="order.collection"
                        :source-quotation="offerSourceQuotation"
                    />

                    <div id="order-section-dokumente" class="content-card">
                        <div class="mb-4">
                            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachten &amp; Rechnungen</h2>
                            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ order.report_documents.length }} Dokumente</p>
                        </div>

                        <p v-if="!order.report_documents.length" class="py-10 text-center text-[13px] text-[#9bb0af]">Keine Dokumente vorhanden.</p>

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
                    <!--
                        The status log is a reference card like the rest, not a page
                        footer: four short columns spread over the full width of the
                        page read as an almost empty table.
                    -->
                    <div class="content-card">
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
                    </div>
                </MasonryGrid>

                <!--
                    Positions sit full width, outside the packing grid above: they are by
                    far the tallest card on the page, and packing one card that outgrows
                    every other card put together only moves the dead space around.
                    Full width is also what the card wants — each position lays out as a
                    row instead of a stack of six fields.
                -->
                <AdminAppraisalPositionsCard
                    id="order-section-positionen"
                    :order-id="order.id"
                    :positions="order.appraisal_positions"
                    :totals="order.appraisal_totals"
                    :report-documents="order.report_documents"
                />

                <!--
                    Quotations and offers are the two halves of one step — a quotation is
                    what an offer is built from — so they stay under a single
                    `order-section-angebote` anchor, which is what the task card scrolls
                    to.

                    They sit outside the masonry above, side by side. Inside it they were
                    one unbreakable column item, and a multi-column layout clips an item
                    taller than its column instead of pushing it down: a handful of
                    quotations plus an open comparison plus a few offers, and the bottom
                    of the card simply vanished. Full width also gives the comparison
                    table its `min-w-[420px]` without forcing a sideways scroll.
                -->
                <section id="order-section-angebote" class="grid grid-cols-1 items-start gap-4 xl:grid-cols-2">
                    <AdminWorkshopQuotationsCard
                        :order-id="order.id"
                        :quotations="order.workshop_quotations"
                        :has-positions="!!order.appraisal_positions.length"
                    />

                    <AdminOffersCard ref="offersCard" :order-id="order.id" :offers="order.offers" :quotations="order.workshop_quotations" />
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
