<script setup lang="ts">
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import OfferComparison from '@/components/vehicle/OfferComparison.vue';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import OrderProgress from '@/components/vehicle/OrderProgress.vue';
import UploadDocumentModal from '@/components/vehicle/UploadDocumentModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { getCustomerOrderFlowSteps } from '@/lib/customerOrderFlow';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { StationData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiArrowLeft from '~icons/mdi/arrow-left';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';
import MdiOpenInNew from '~icons/mdi/open-in-new';
import MdiPencilOutline from '~icons/mdi/pencil-outline';
import MdiTrashCanOutline from '~icons/mdi/trash-can-outline';
import MdiTrayArrowUp from '~icons/mdi/tray-arrow-up';

const props = defineProps<{ vehicle: VehicleData; stations: StationData[] }>();

const editOpen = ref(false);
const orderOpen = ref(false);
const uploadOpen = ref(false);

const currentOrder = computed(() => props.vehicle.orders[0] ?? null);
const status = computed(() => getVehicleStatusDisplay(currentOrder.value?.order_status));

const offers = computed(() => props.vehicle.orders.flatMap((order) => order.offers));

const steps = computed(() => {
    const order = currentOrder.value;

    if (!order) {
        return null;
    }

    return getCustomerOrderFlowSteps({
        orderStatus: order.order_status,
        orderCreatedAt: order.created_at,
        statusHistory: order.status_updates,
        besichtigungsort: order.request_payload?.besichtigungsort ?? null,
        reportDocuments: order.report_documents,
        offers: order.offers,
    });
});

const STATUS_TONE: Record<string, string> = {
    success: '#01B990',
    warning: '#EF8450',
    default: '#4FA3A6',
    secondary: '#6f8585',
    outline: '#9aacac',
};

const statusColor = computed(() => STATUS_TONE[status.value.variant] ?? STATUS_TONE.secondary);

const specs = computed(() => [
    { label: 'Kennzeichen', value: props.vehicle.license_plate },
    { label: 'Marke', value: props.vehicle.make || '—' },
    { label: 'Modell', value: props.vehicle.model || '—' },
    { label: 'FIN', value: props.vehicle.vin || '—' },
    { label: 'Leasingende', value: formatDate(props.vehicle.leasing_end_date) },
    { label: 'Leasinggeber', value: props.vehicle.leasinggeber || '—' },
]);

const appointment = computed(() => currentOrder.value?.request_payload?.besichtigungsort ?? null);

const reportDocuments = computed(() =>
    props.vehicle.orders.flatMap((order) =>
        order.report_documents
            .filter((doc) => doc.published && doc.url)
            .map((doc) => ({ ...doc, auftragsnummer: order.auftragsnummer })),
    ),
);

const pastOrders = computed(() => props.vehicle.orders.slice(1));

const deletingId = ref<string | null>(null);

function deleteDocument(documentId: string) {
    deletingId.value = documentId;

    router.delete(route('vehicles.documents.destroy', [props.vehicle.vehicle_id, documentId]), {
        preserveScroll: true,
        onFinish: () => (deletingId.value = null),
    });
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateTime(value: string | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })} · ${date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr`;
}
</script>

<template>
    <Head :title="vehicle.license_plate" />

    <AppLayout>
        <div class="mx-auto flex max-w-[1100px] flex-col gap-5">
            <Link
                :href="route('dashboard')"
                class="inline-flex w-fit items-center gap-1.5 text-[13px] font-semibold text-[#6f8585] transition-colors hover:text-[#10393b]"
            >
                <MdiArrowLeft class="text-[16px]" />
                Zurück zum Dashboard
            </Link>

            <section class="overflow-hidden rounded-[18px]" style="background: linear-gradient(180deg, #10393b 0%, #0d3133 100%)">
                <div class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-[26px] leading-none font-extrabold tracking-tight text-white md:text-[30px]">
                                {{ vehicle.license_plate }}
                            </h1>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11.5px] font-bold text-white"
                                :style="{ backgroundColor: statusColor }"
                            >
                                {{ status.label }}
                            </span>
                        </div>
                        <p class="mt-2 text-[13.5px] text-white/55">
                            {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke/Modell' }}
                            <span v-if="currentOrder"> · Auftrag {{ currentOrder.auftragsnummer }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="!currentOrder"
                            type="button"
                            class="h-10 rounded-full px-5 text-[13px] font-semibold text-white shadow-lg transition-all"
                            style="background: #ef8450"
                            @click="orderOpen = true"
                        >
                            Vorgang starten
                        </button>
                        <button
                            type="button"
                            class="flex h-10 items-center gap-2 rounded-full border border-white/15 px-4 text-[13px] font-semibold text-white/85 transition hover:border-white/35 hover:text-white"
                            @click="uploadOpen = true"
                        >
                            <MdiTrayArrowUp class="text-[16px]" />
                            Dokument
                        </button>
                        <button
                            type="button"
                            class="flex h-10 items-center gap-2 rounded-full border border-white/15 px-4 text-[13px] font-semibold text-white/85 transition hover:border-white/35 hover:text-white"
                            @click="editOpen = true"
                        >
                            <MdiPencilOutline class="text-[16px]" />
                            Bearbeiten
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_320px] lg:items-start">
                <div class="flex flex-col gap-5">
                    <OfferComparison :offers="offers" />

                    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Vorgang</h2>
                            <p class="mt-0.5 text-[12.5px] text-[#00000080]">
                                {{ steps ? 'Der aktuelle Stand Ihres Leasyback-Prozesses.' : 'Für dieses Fahrzeug läuft noch kein Vorgang.' }}
                            </p>
                        </header>

                        <div class="px-5 py-5">
                            <OrderProgress v-if="steps" :steps="steps" />

                            <div v-else class="flex flex-col items-start gap-3 py-2">
                                <p class="text-[13px] text-[#00000080]">
                                    Starten Sie den Vorgang, um einen Begutachtungstermin zu buchen.
                                </p>
                                <button
                                    type="button"
                                    class="h-9 rounded-full px-5 text-[13px] font-semibold text-white shadow-lg"
                                    style="background: #ef8450"
                                    @click="orderOpen = true"
                                >
                                    Vorgang starten
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="flex items-center justify-between border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Dokumente</h2>
                            <button
                                type="button"
                                class="text-[12px] font-semibold text-[#01B990] transition-opacity hover:opacity-70"
                                @click="uploadOpen = true"
                            >
                                Hochladen
                            </button>
                        </header>

                        <p v-if="!vehicle.documents.length" class="px-5 py-8 text-center text-[13px] text-[#9aacac]">
                            Noch keine Dokumente hochgeladen.
                        </p>

                        <ul v-else>
                            <li
                                v-for="doc in vehicle.documents"
                                :key="doc.document_id"
                                class="group flex items-center gap-3 border-b border-[#f1f5f5] px-5 py-3 last:border-b-0"
                            >
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-[#f1f5f5] text-[#6f8585]">
                                    <MdiFileDocumentOutline class="text-[18px]" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-semibold text-[#10393b]">{{ doc.original_file_name }}</p>
                                    <p class="text-[11.5px] text-[#9aacac]">{{ doc.document_type }} · {{ formatDate(doc.created_at) }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <a
                                        v-if="doc.url"
                                        :href="doc.url"
                                        target="_blank"
                                        rel="noopener"
                                        title="Öffnen"
                                        aria-label="Öffnen"
                                        class="rounded-full p-1.5 text-[#c3d0d0] transition hover:bg-[#eef3f3] hover:text-[#10393b]"
                                    >
                                        <MdiOpenInNew class="text-[16px]" />
                                    </a>
                                    <button
                                        type="button"
                                        title="Löschen"
                                        aria-label="Löschen"
                                        :disabled="deletingId === doc.document_id"
                                        class="rounded-full p-1.5 text-[#c3d0d0] transition hover:bg-[#fdeeeb] hover:text-[#E5533D] disabled:opacity-40"
                                        @click="deleteDocument(doc.document_id)"
                                    >
                                        <MdiTrashCanOutline class="text-[16px]" />
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <section v-if="reportDocuments.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Gutachten &amp; Rechnungen</h2>
                            <p class="mt-0.5 text-[12.5px] text-[#00000080]">Von LeasyBack bereitgestellte Dokumente.</p>
                        </header>

                        <ul>
                            <li
                                v-for="doc in reportDocuments"
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
                                    <p class="text-[11.5px] text-[#9aacac]">{{ doc.auftragsnummer }} · {{ formatDate(doc.created_at) }}</p>
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

                    <section v-if="pastOrders.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Frühere Vorgänge</h2>
                        </header>

                        <ul>
                            <li
                                v-for="order in pastOrders"
                                :key="order.id"
                                class="flex items-center justify-between gap-3 border-b border-[#f1f5f5] px-5 py-3 last:border-b-0"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-semibold text-[#10393b]">{{ order.auftragsnummer }}</p>
                                    <p class="text-[11.5px] text-[#9aacac]">{{ formatDate(order.created_at) }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-[#f1f5f5] px-2.5 py-1 text-[11px] font-bold text-[#6f8585]">
                                    {{ getVehicleStatusDisplay(order.order_status).label }}
                                </span>
                            </li>
                        </ul>
                    </section>
                </div>

                <div class="flex flex-col gap-5">
                    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Fahrzeugdaten</h2>
                        </header>

                        <dl class="divide-y divide-[#f1f5f5]">
                            <div v-for="spec in specs" :key="spec.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                                <dt class="text-[12.5px] text-[#00000080]">{{ spec.label }}</dt>
                                <dd class="truncate text-right text-[13px] font-semibold text-[#10393b]">{{ spec.value }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section v-if="appointment" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
                        <header class="border-b border-[#f1f5f5] px-5 py-4">
                            <h2 class="text-[15px] font-bold text-[#10393b]">Termin</h2>
                        </header>

                        <div class="space-y-1 px-5 py-4">
                            <p class="text-[13px] font-semibold text-[#10393b]">{{ formatDateTime(appointment.termin) }}</p>
                            <p v-if="appointment.name" class="text-[12.5px] text-[#00000080]">{{ appointment.name }}</p>
                            <p v-if="appointment.strasse" class="text-[12px] text-[#9aacac]">
                                {{ appointment.strasse }}, {{ appointment.plz }} {{ appointment.ort }}
                            </p>
                            <a
                                v-if="appointment.strasse"
                                :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
                                    `${appointment.strasse}, ${appointment.plz} ${appointment.ort}`,
                                )}`"
                                target="_blank"
                                rel="noopener"
                                class="mt-2 inline-block text-[12px] font-semibold text-[#01B990] hover:underline"
                            >
                                Route planen
                            </a>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <AddVehicleModal v-model:open="editOpen" :vehicle="vehicle" />
        <OrderCreationModal v-model:open="orderOpen" :vehicle-id="vehicle.vehicle_id" :stations="stations" />
        <UploadDocumentModal v-model:open="uploadOpen" :vehicle-id="vehicle.vehicle_id" :documents="vehicle.documents" />
    </AppLayout>
</template>
