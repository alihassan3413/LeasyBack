<script setup lang="ts">
/**
 * One order, in full, addressed by its own id.
 *
 * The vehicle page shows the order that is running now; this shows *a
 * specific* order — usually a closed one reached from the Auftragsverlauf.
 * Everything on it is scoped to that record: its own timeline, status trail,
 * offers, documents, payment obligations and final outcome. Nothing here reads
 * a position out of a list, and nothing falls back to the vehicle's current
 * order when this one has little to show.
 */
import BackButton from '@/components/BackButton.vue';
import PaymentCheckoutPanel from '@/components/payment/PaymentCheckoutPanel.vue';
import OrderMessages from '@/components/shared/OrderMessages.vue';
import { AppModal } from '@/components/ui/modal';
import OfferComparison from '@/components/vehicle/OfferComparison.vue';
import OrderHistoryList from '@/components/vehicle/OrderHistoryList.vue';
import OrderProgress from '@/components/vehicle/OrderProgress.vue';
import { useLiveUpdates } from '@/composables/useLiveUpdates';
import AppLayout from '@/layouts/AppLayout.vue';
import { getCustomerOrderFlowSteps } from '@/lib/customerOrderFlow';
import { ORDER_OUTCOME_LABELS } from '@/lib/orderHistory';
import { formatPortalDate, formatPortalDateTime } from '@/lib/portalDate';
import { getOrderStatusLabel, getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import { formatCard, formatEuro } from '@/types/payment';
import type { OrderDetailVehicle, OrderHistoryEntry, VehicleOrderData } from '@/types/vehicle';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiCloseCircleOutline from '~icons/mdi/close-circle-outline';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';

const props = defineProps<{ vehicle: OrderDetailVehicle; order: VehicleOrderData }>();

useLiveUpdates((notification) => !notification.meta.vehicle_id || notification.meta.vehicle_id === props.vehicle.vehicle_id);

const isB2b = computed(() => props.vehicle.vehicle_belongs === 'B2B');

const status = computed(() => getVehicleStatusDisplay(props.order.order_status, props.order.payment?.repair_stage));

const STATUS_TONE: Record<string, string> = {
    success: '#01B990',
    warning: '#EF8450',
    default: '#4FA3A6',
    secondary: '#6f8585',
    outline: '#9aacac',
};

const statusColor = computed(() => STATUS_TONE[status.value.variant] ?? STATUS_TONE.secondary);

const CLOSING_STATUSES = ['completed', 'cancelled', 'discarded'] as const;

/**
 * How this order ended, or null while it is still running. Read off the
 * order's own status rather than from which half of the current/history split
 * it arrived in: the vehicle's newest order stays "current" after it closes,
 * so being current says nothing about being open.
 */
const outcome = computed<(typeof CLOSING_STATUSES)[number] | null>(() => {
    const found = CLOSING_STATUSES.find((closing) => closing === props.order.order_status);

    return found ?? null;
});

/** One palette per outcome, so the banner's border, text and icon cannot disagree. */
const outcomeTone = computed(() =>
    outcome.value === 'completed'
        ? { frame: 'border-[#01B990]/30 bg-[#01B990]/10', text: 'text-[#00856a]', icon: MdiCheckCircleOutline }
        : { frame: 'border-[#E5533D]/30 bg-[#E5533D]/10', text: 'text-[#b03b28]', icon: MdiCloseCircleOutline },
);

/** From this order's own trail, so the date is its closure and not the vehicle's latest. */
const closedAt = computed(
    () => props.order.status_updates.find((update) => CLOSING_STATUSES.some((closing) => closing === update.new_status))?.created_at ?? null,
);

const steps = computed(() =>
    getCustomerOrderFlowSteps({
        orderStatus: props.order.order_status,
        orderCreatedAt: props.order.created_at,
        statusHistory: props.order.status_updates,
        besichtigungsort: props.order.request_payload?.besichtigungsort ?? null,
        reportDocuments: props.order.report_documents,
        offers: props.order.offers,
        collection: props.order.collection,
        channel: props.vehicle.vehicle_belongs,
        repairPayment: {
            stage: props.order.payment?.repair_stage ?? 'none',
            status: props.order.payment?.repair?.status ?? null,
            amount_cents: props.order.payment?.repair?.amount_cents ?? null,
            // The pay action belongs to this page's Zahlung card, so the
            // timeline must not offer it a second time.
            payable: false,
        },
        audience: 'customer',
    }),
);

const appointment = computed(() => props.order.request_payload?.besichtigungsort ?? null);

const documents = computed(() => props.order.report_documents.filter((doc) => doc.published && doc.url));

/** The status trail as the customer may see it — no actor, only what changed and when. */
const statusTrail = computed(() =>
    props.order.status_updates.map((update) => ({
        id: update.id,
        label: getOrderStatusLabel(update.new_status ?? ''),
        from: update.old_status ? getOrderStatusLabel(update.old_status) : null,
        at: formatPortalDateTime(update.created_at),
    })),
);

const payment = computed(() => props.order.payment ?? null);

const paymentRows = computed(() => {
    const state = payment.value;

    if (!state) {
        return [];
    }

    const rows = [{ label: 'Zahlungsmethode', value: formatCard(state.card) || 'Keine hinterlegt' }];

    if (state.repair) {
        rows.push({ label: 'Reparaturkosten', value: obligationLabel(state.repair.amount_cents, state.repair.paid_at) });
    }

    if (state.cancellation_fee) {
        rows.push({ label: 'Stornogebühr', value: obligationLabel(state.cancellation_fee.amount_cents, state.cancellation_fee.paid_at) });
    }

    return rows;
});

function obligationLabel(amountCents: number, paidAt: string | null): string {
    return `${formatEuro(amountCents / 100)} · ${paidAt ? `bezahlt am ${formatPortalDate(paidAt)}` : 'offen'}`;
}

/**
 * Which obligation the checkout modal is settling. `payable` is the server's
 * answer — already false for Admin and for anything settled — so this only
 * decides whether the button is drawn.
 */
const settling = ref<'repair' | 'cancellation-fee' | null>(null);

function onSettled() {
    settling.value = null;
    // Re-read rather than patching local state: the obligation must disappear
    // from the server's answer, not from a guess about it.
    router.reload({ preserveScroll: true });
}

const collectionRows = computed(() => {
    const collection = props.order.collection;

    if (!isB2b.value || !collection) {
        return [];
    }

    return [
        { label: 'Wunschtermin', value: formatPortalDate(collection.requested_collection_date) },
        { label: 'Bestätigter Termin', value: formatPortalDate(collection.confirmed_collection_date) },
        { label: 'Hinweis', value: collection.collection_note ?? '' },
    ].filter((row) => !!row.value);
});

const orderFacts = computed(() => [
    { label: 'Auftragsnummer', value: props.order.auftragsnummer },
    { label: 'Status', value: getOrderStatusLabel(props.order.order_status) },
    { label: 'Partner', value: props.order.leasyback_partner || '—' },
    { label: 'Angelegt am', value: formatPortalDateTime(props.order.created_at) || '—' },
    { label: 'Übermittelt am', value: formatPortalDateTime(props.order.sent_at) || '—' },
]);

/**
 * The vehicle's orders in full, this one marked rather than linked.
 *
 * The current order is prepended because the payload deliberately keeps it out
 * of `order_history` — "history" means everything *behind* the current order —
 * so listing the vehicle's orders here means putting the two halves back
 * together.
 */
const siblingOrders = computed<OrderHistoryEntry[]>(() =>
    [props.vehicle.current_order, ...props.vehicle.order_history].filter((entry): entry is OrderHistoryEntry => entry !== null),
);
</script>

<template>
    <Head :title="order.auftragsnummer" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center gap-3">
                <BackButton :href="route('vehicles.show', vehicle.vehicle_id)" label="Zurück zum Fahrzeug" />

                <div class="flex min-w-0 items-center gap-2.5">
                    <h1 class="truncate text-[17px] leading-none font-extrabold tracking-tight text-[#10393b]">
                        {{ order.auftragsnummer }}
                    </h1>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold text-white" :style="{ backgroundColor: statusColor }">
                        {{ status.label }}
                    </span>
                </div>
            </div>
        </template>

        <div class="mx-auto flex max-w-[1100px] flex-col gap-5">
            <p class="text-[13.5px] text-[#00000080]">
                <Link :href="route('vehicles.show', vehicle.vehicle_id)" class="font-semibold text-[#10393b] hover:text-[#01B990]">
                    {{ vehicle.license_plate }}
                </Link>
                · {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke/Modell' }}
            </p>

            <!-- How the case ended, said once and up front: a closed timeline shows where it stopped, not that it is over. -->
            <section v-if="outcome" class="flex items-start gap-3 rounded-[16px] border px-5 py-4" :class="outcomeTone.frame">
                <component :is="outcomeTone.icon" class="mt-0.5 shrink-0 text-[20px]" :class="outcomeTone.text" />
                <div>
                    <p class="text-[14px] font-bold" :class="outcomeTone.text">
                        {{ ORDER_OUTCOME_LABELS[outcome] }}
                    </p>
                    <p class="text-[12.5px] text-[#00000080]">
                        <template v-if="closedAt">Dieser Auftrag wurde am {{ formatPortalDateTime(closedAt) }} beendet.</template>
                        <template v-else>Dieser Auftrag ist beendet.</template>
                    </p>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_320px] lg:items-start">
                <div class="flex flex-col gap-5">
                    <OfferComparison :offers="order.offers" />

                    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Vorgang</h2>
                            <p class="mt-0.5 text-[12.5px] text-[#00000080]">Der Verlauf dieses Auftrags.</p>
                        </header>

                        <div class="px-5 py-5">
                            <OrderProgress v-if="steps" :steps="steps" />
                            <p v-else class="py-2 text-[13px] text-[#9aacac]">
                                Für diesen Auftrag lässt sich kein Verlauf darstellen. Der Statusverlauf unten zeigt jede Änderung.
                            </p>
                        </div>
                    </section>

                    <section v-if="payment" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Zahlung</h2>
                        </header>

                        <dl class="divide-y divide-[#f1f5f5]">
                            <div v-for="row in paymentRows" :key="row.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                                <dt class="text-[12.5px] text-[#00000080]">{{ row.label }}</dt>
                                <dd class="text-right text-[13px] font-semibold text-[#10393b]">{{ row.value }}</dd>
                            </div>
                        </dl>

                        <div v-if="payment.repair?.payable || payment.cancellation_fee?.payable" class="flex flex-wrap gap-2 px-5 py-4">
                            <button
                                v-if="payment.repair?.payable"
                                type="button"
                                class="h-9 rounded-full px-5 text-[13px] font-semibold text-white shadow-lg"
                                style="background: #ef8450"
                                @click="settling = 'repair'"
                            >
                                Reparaturkosten bezahlen
                            </button>
                            <button
                                v-if="payment.cancellation_fee?.payable"
                                type="button"
                                class="h-9 rounded-full px-5 text-[13px] font-semibold text-white shadow-lg"
                                style="background: #ef8450"
                                @click="settling = 'cancellation-fee'"
                            >
                                Stornogebühr bezahlen
                            </button>
                        </div>
                    </section>

                    <section v-if="documents.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Gutachten &amp; Rechnungen</h2>
                            <p class="mt-0.5 text-[12.5px] text-[#00000080]">Dokumente zu diesem Auftrag.</p>
                        </header>

                        <ul>
                            <li
                                v-for="doc in documents"
                                :key="doc.id"
                                class="flex items-center gap-3 border-b border-[#f1f5f5] px-5 py-3 last:border-b-0"
                            >
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#01B990]">
                                    <MdiFileDocumentOutline class="text-[18px]" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-[#10393b]">
                                        {{ doc.document_title || doc.document_type || 'Dokument' }}
                                    </p>
                                    <p class="text-[11.5px] text-[#9aacac]">{{ formatPortalDate(doc.created_at) }}</p>
                                </div>
                                <a
                                    :href="doc.url as string"
                                    target="_blank"
                                    rel="noopener"
                                    class="shrink-0 rounded-full border border-[#d8e4e3] px-3 py-1 text-[11.5px] font-semibold text-[#10393b] transition hover:border-[#01B990] hover:text-[#01B990]"
                                >
                                    Öffnen
                                </a>
                            </li>
                        </ul>
                    </section>

                    <section v-if="statusTrail.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Statusverlauf</h2>
                        </header>

                        <ul>
                            <li
                                v-for="entry in statusTrail"
                                :key="entry.id"
                                class="flex items-baseline justify-between gap-4 border-b border-[#f1f5f5] px-5 py-2.5 last:border-b-0"
                            >
                                <span class="text-[13px] font-semibold text-[#10393b]">
                                    {{ entry.label }}
                                    <span v-if="entry.from" class="font-medium text-[#9aacac]">· zuvor {{ entry.from }}</span>
                                </span>
                                <span class="shrink-0 text-[11.5px] text-[#9aacac]">{{ entry.at }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- The thread belongs to this order, so it travels with it rather than with the vehicle. -->
                    <OrderMessages :order-id="order.id" :auftragsnummer="order.auftragsnummer" />
                </div>

                <div class="flex flex-col gap-5">
                    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Auftragsdaten</h2>
                        </header>

                        <dl class="divide-y divide-[#f1f5f5]">
                            <div v-for="fact in orderFacts" :key="fact.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                                <dt class="text-[12.5px] text-[#00000080]">{{ fact.label }}</dt>
                                <dd class="truncate text-right text-[13px] font-semibold text-[#10393b]">{{ fact.value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="appointment" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Termin</h2>
                        </header>

                        <div class="space-y-1 px-5 py-4">
                            <p class="text-[13px] font-semibold text-[#10393b]">{{ formatPortalDateTime(appointment.termin) || '—' }}</p>
                            <p v-if="appointment.name" class="text-[12.5px] text-[#00000080]">{{ appointment.name }}</p>
                            <p v-if="appointment.strasse" class="text-[12px] text-[#9aacac]">
                                {{ appointment.strasse }}, {{ appointment.plz }} {{ appointment.ort }}
                            </p>
                        </div>
                    </section>

                    <section v-if="collectionRows.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Abholung</h2>
                        </header>

                        <dl class="divide-y divide-[#f1f5f5]">
                            <div v-for="row in collectionRows" :key="row.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                                <dt class="text-[12.5px] text-[#00000080]">{{ row.label }}</dt>
                                <dd class="text-right text-[13px] font-semibold text-[#10393b]">{{ row.value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Customer-visible notes only (§16); a B2C order never carries any. -->
                    <section v-if="order.notes?.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Hinweise von LeasyBack</h2>
                        </header>

                        <ul>
                            <li v-for="note in order.notes" :key="note.id" class="border-b border-[#f1f5f5] px-5 py-3 last:border-b-0">
                                <p class="text-[13px] whitespace-pre-line text-[#10393b]">{{ note.body }}</p>
                                <p class="mt-1 text-[11.5px] text-[#9aacac]">{{ note.author_name }} · {{ formatPortalDate(note.created_at) }}</p>
                            </li>
                        </ul>
                    </section>

                    <OrderHistoryList
                        :entries="siblingOrders"
                        :active-id="order.id"
                        title="Alle Aufträge"
                        empty-text="Für dieses Fahrzeug gibt es keine weiteren Aufträge."
                    />
                </div>
            </div>
        </div>

        <AppModal
            :open="settling !== null"
            title="Offener Betrag"
            description="Begleichen Sie den offenen Betrag zu diesem Auftrag."
            :width="620"
            @update:open="(value) => (settling = value ? settling : null)"
        >
            <div v-if="settling" class="min-w-0 px-2">
                <PaymentCheckoutPanel :order-id="order.id" :purpose="settling" @paid="onSettled" />
            </div>
        </AppModal>
    </AppLayout>
</template>
