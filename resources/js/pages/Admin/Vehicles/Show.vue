<script setup lang="ts">
import AdminOrderActionsMenu from '@/components/admin/AdminOrderActionsMenu.vue';
import UploadReportDocumentModal from '@/components/admin/UploadReportDocumentModal.vue';
import type { SelectFieldOption } from '@/components/form/SelectField.vue';
import VehicleExpandedPanel from '@/components/vehicle/VehicleExpandedPanel.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getAdminDashboardStatus as getStatus } from '@/lib/adminStatus';
import { toVehicleData } from '@/lib/adminVehicle';
import type { AdminVehicleRow } from '@/types/admin';
import type { StationData } from '@/types/order';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ vehicle: AdminVehicleRow; stations: StationData[] }>();

/**
 * The customer dashboard's expanded panel, rendered from the same vehicle
 * via a shape adapter — so Admin sees exactly what the customer sees
 * (timeline, Besichtigungsort, documents, offers) with no second copy of
 * that UI to keep in sync.
 */
const panelVehicle = computed(() => toVehicleData(props.vehicle));

const currentOrder = computed(() => props.vehicle.order_history.find((order) => order.id === props.vehicle.current_order_id) ?? null);

const ownerRoute = computed(() => {
    if (props.vehicle.vehicle_belongs === 'B2C' && props.vehicle.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: props.vehicle.user_id });
    }

    if (props.vehicle.vehicle_belongs === 'B2B' && props.vehicle.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: props.vehicle.b2b_id });
    }

    return null;
});

const ownerLabel = computed(() => props.vehicle.company_name || props.vehicle.user_email || 'Nicht zugeordnet');
const vehicleTitle = computed(() => [props.vehicle.make, props.vehicle.model].filter(Boolean).join(' ') || 'Ohne Marke');

const specs = computed(() => [
    { label: 'Kennzeichen', value: props.vehicle.license_plate, mono: true },
    { label: 'FIN', value: props.vehicle.vin || '—', mono: true },
    { label: 'Erstzulassung', value: formatDate(props.vehicle.first_registration_date) },
    { label: 'Leasingende', value: formatDate(props.vehicle.leasing_end_date) },
    { label: 'Leasinggeber', value: props.vehicle.leasinggeber || '—' },
    { label: 'Angelegt am', value: formatDate(props.vehicle.created_at) },
]);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const auftragsnummerOptions = computed<SelectFieldOption[]>(() =>
    props.vehicle.order_history.map((order) => ({ value: order.auftragsnummer, label: order.auftragsnummer })),
);

const reportDocuments = computed(() =>
    props.vehicle.order_history.flatMap((order) => order.report_documents.map((doc) => ({ ...doc, auftragsnummer: order.auftragsnummer }))),
);

const uploadModalOpen = ref(false);
const uploadDocumentType = ref('gutachten');

function openUpload(documentType: string) {
    uploadDocumentType.value = documentType;
    uploadModalOpen.value = true;
}
const publishingId = ref<string | null>(null);
const confirmingDeleteId = ref<string | null>(null);

function togglePublished(documentId: string, published: boolean) {
    publishingId.value = documentId;

    router.patch(
        route('admin.vehicles.reports.publish', documentId),
        { published: !published },
        { preserveScroll: true, onFinish: () => (publishingId.value = null) },
    );
}

function deleteDocument(documentId: string) {
    router.delete(route('admin.vehicles.reports.delete', documentId), {
        preserveScroll: true,
        onFinish: () => (confirmingDeleteId.value = null),
    });
}
</script>

<template>
    <Head :title="vehicle.license_plate" />

    <AdminLayout>
        <template #header>
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <BackButton :href="route('admin.vehicles.index')" label="Zurück zur Fahrzeugliste" />

                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-bold tracking-[0.12em] text-[#9bb0af] uppercase">
                        {{ vehicle.vehicle_belongs === 'B2B' ? 'Firmenkunde' : 'Privatkunde' }}
                    </p>
                    <h1 class="truncate text-[16px] leading-tight font-extrabold tracking-[-0.3px] text-[#10393b]">
                        {{ vehicle.license_plate }} · {{ vehicleTitle }}
                    </h1>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#01B990] hover:text-[#00856a]"
                        @click="openUpload('rechnung')"
                    >
                        <IconMdiReceiptTextOutline class="size-4" />
                        Rechnung hochladen
                    </button>

                    <button
                        type="button"
                        class="flex shrink-0 items-center gap-1.5 rounded-[13px] px-4 py-2 text-[12.5px] font-bold text-white transition-all hover:-translate-y-px"
                        style="background: linear-gradient(135deg, #10393b, #1a5052); box-shadow: 0 8px 20px rgba(16, 57, 59, 0.2)"
                        @click="openUpload('gutachten')"
                    >
                        <IconMdiFileUploadOutline class="size-4" />
                        Gutachten hochladen
                    </button>
                </div>

                <!-- Rendered even without an order: "Auftrag erstellen" is exactly the action needed then. -->
                <div class="mr-2 shrink-0">
                    <AdminOrderActionsMenu
                        :order-id="currentOrder?.id"
                        :auftragsnummer="currentOrder?.auftragsnummer"
                        :vehicle-id="vehicle.vehicle_id"
                        :order-status="currentOrder?.order_status"
                        :available-transitions="currentOrder?.available_transitions"
                        :stations="stations"
                        :has-open-order="vehicle.has_open_order"
                        :can-pull-documents="vehicle.can_pull_documents"
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
                                    <IconMdiCarOutline class="size-8" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[19px] font-extrabold tracking-[-0.4px] text-white">{{ vehicleTitle }}</p>
                                    <p class="mt-1 truncate font-mono text-[12.5px] text-white/70">{{ vehicle.license_plate }}</p>

                                    <span
                                        class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white/20 px-2.5 py-1 text-[11px] font-extrabold text-white"
                                    >
                                        <span class="h-[5px] w-[5px] rounded-full bg-current"></span>
                                        {{ getStatus(vehicle.current_order_status).label }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-auto grid grid-cols-2 gap-2 border-t border-white/20 pt-5">
                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">Aufträge</p>
                                    <p class="mt-1 text-[15px] font-extrabold text-white">{{ vehicle.order_history.length }}</p>
                                </div>

                                <div class="rounded-[13px] bg-white/10 px-3 py-2.5">
                                    <p class="text-[10px] font-bold tracking-[0.05em] text-white/55 uppercase">Aktueller Auftrag</p>
                                    <p class="mt-1 truncate font-mono text-[12px] font-bold text-white">
                                        {{ vehicle.current_auftragsnummer || '—' }}
                                    </p>
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
                        <p v-if="vehicle.company_name && vehicle.user_email" class="mt-1 truncate text-[12.5px] text-[#6f8585]">
                            {{ vehicle.user_email }}
                        </p>

                        <Link
                            v-if="ownerRoute"
                            :href="ownerRoute"
                            class="mt-auto flex items-center justify-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-4 py-2.5 text-[13px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                        >
                            Kundenprofil öffnen
                            <IconMdiArrowTopRight class="size-4" />
                        </Link>
                    </div>

                    <div class="content-card">
                        <div class="mb-4 flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                                <IconMdiClipboardTextOutline class="size-[17px]" />
                            </span>
                            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Fahrzeugdaten</h2>
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

                <section class="content-card">
                    <div class="mb-4">
                        <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Auftragsverlauf</h2>
                        <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ vehicle.order_history.length }} Aufträge</p>
                    </div>

                    <p v-if="!vehicle.order_history.length" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Aufträge vorhanden.</p>

                    <div v-else class="flex flex-col gap-1">
                        <div
                            v-for="order in vehicle.order_history"
                            :key="order.id"
                            class="group flex items-center gap-1 rounded-[13px] pr-2 transition-colors hover:bg-[#f6f9f8]"
                        >
                            <Link :href="route('admin.orders.show', order.id)" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#6366f1]/10 text-[#6366f1]">
                                    <IconMdiFileDocumentOutline class="size-[17px]" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="mb-0.5 flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-[13px] font-bold text-[#10393b]">{{ order.auftragsnummer }}</span>
                                        <span class="text-[11.5px] text-[#9bb0af]">{{ order.leasyback_partner }}</span>
                                    </div>

                                    <p class="text-[11.5px] text-[#6f8585]">
                                        Erstellt {{ formatDate(order.created_at) }}
                                        <template v-if="order.confirmation_date"> · Bestätigt {{ formatDate(order.confirmation_date) }} </template>
                                    </p>
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

                                    <span
                                        class="flex h-7 w-7 items-center justify-center rounded-[8px] text-[#bcccca] transition-all group-hover:bg-[#10393b] group-hover:text-white"
                                    >
                                        <IconMdiArrowTopRight class="size-[13px]" />
                                    </span>
                                </div>
                            </Link>

                            <AdminOrderActionsMenu
                                :order-id="order.id"
                                :auftragsnummer="order.auftragsnummer"
                                :vehicle-id="vehicle.vehicle_id"
                                :order-status="order.order_status"
                                :available-transitions="order.available_transitions"
                                :stations="stations"
                                :has-open-order="vehicle.has_open_order"
                                :can-pull-documents="vehicle.can_pull_documents"
                            />
                        </div>
                    </div>
                </section>

                <section class="content-card">
                    <div class="mb-4">
                        <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Kundenansicht</h2>
                        <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">
                            Der Auftragsverlauf, die Dokumente und die Angebote dieses Fahrzeugs so, wie der Kunde sie im Dashboard sieht.
                        </p>
                    </div>

                    <div class="-mx-6 -mb-6 overflow-hidden rounded-b-[24px]">
                        <VehicleExpandedPanel :vehicle="panelVehicle" admin embedded />
                    </div>
                </section>

                <section class="grid grid-cols-2 gap-4 max-[1180px]:grid-cols-1">
                    <div class="content-card">
                        <div class="mb-4 flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Gutachten &amp; Rechnungen</h2>
                                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ reportDocuments.length }} Dokumente</p>
                            </div>

                            <button
                                type="button"
                                class="flex shrink-0 items-center gap-1.5 rounded-[11px] border border-[#e9efee] bg-white px-3 py-2 text-[12px] font-bold text-[#10393b] transition-all hover:border-[#01B990] hover:bg-[#f0fbf8] hover:text-[#00856a]"
                                @click="openUpload('gutachten')"
                            >
                                <IconMdiPlus class="size-3.5" />
                                Hochladen
                            </button>
                        </div>

                        <p v-if="!reportDocuments.length" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Dokumente vorhanden.</p>

                        <div v-else class="flex flex-col gap-1">
                            <div
                                v-for="doc in reportDocuments"
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
                                    <p class="truncate font-mono text-[11.5px] text-[#6f8585]">{{ doc.auftragsnummer }}</p>
                                </div>

                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                    :class="doc.published ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#f4f7f6] text-[#9bb0af]'"
                                >
                                    {{ doc.published ? 'Veröffentlicht' : 'Entwurf' }}
                                </span>

                                <div class="flex shrink-0 items-center gap-1">
                                    <a
                                        v-if="doc.signed_url"
                                        :href="doc.signed_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="flex h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#10393b] hover:text-white"
                                        title="Öffnen"
                                    >
                                        <IconMdiOpenInNew class="size-[15px]" />
                                    </a>

                                    <!--
                                        Labelled, not a bare eye icon: uploads are drafts by
                                        default, so this is the step that actually reaches the
                                        customer (and notifies them). It needs to read as an
                                        action, not as a view toggle.
                                    -->
                                    <button
                                        type="button"
                                        class="flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[11px] font-bold transition-all disabled:opacity-40"
                                        :class="
                                            doc.published
                                                ? 'border-[#ececec] text-[#6f8585] hover:border-[#EF4444] hover:text-[#EF4444]'
                                                : 'border-[#01B990] text-[#00856a] hover:bg-[#01B990] hover:text-white'
                                        "
                                        :title="
                                            doc.published
                                                ? 'Dokument wieder als Entwurf verbergen'
                                                : 'Für den Kunden freigeben — der Kunde wird benachrichtigt'
                                        "
                                        :disabled="publishingId === doc.id"
                                        @click="togglePublished(doc.id, doc.published)"
                                    >
                                        <IconMdiEyeOffOutline v-if="doc.published" class="size-[14px]" />
                                        <IconMdiEyeOutline v-else class="size-[14px]" />
                                        {{ doc.published ? 'Zurückziehen' : 'Freigeben' }}
                                    </button>

                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-[9px] transition-all"
                                        :class="
                                            confirmingDeleteId === doc.id
                                                ? 'bg-[#EF4444] text-white'
                                                : 'text-[#bcccca] hover:bg-[#EF4444] hover:text-white'
                                        "
                                        :title="confirmingDeleteId === doc.id ? 'Zum Löschen erneut klicken' : 'Löschen'"
                                        @click="confirmingDeleteId === doc.id ? deleteDocument(doc.id) : (confirmingDeleteId = doc.id)"
                                    >
                                        <IconMdiDeleteOutline class="size-[15px]" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="mb-4">
                            <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Kundendokumente</h2>
                            <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ vehicle.documents.length }} Dokumente</p>
                        </div>

                        <p v-if="!vehicle.documents.length" class="py-12 text-center text-[13px] text-[#9bb0af]">Keine Dokumente vorhanden.</p>

                        <div v-else class="flex flex-col gap-1">
                            <div
                                v-for="doc in vehicle.documents"
                                :key="doc.document_id"
                                class="flex items-center gap-3 rounded-[13px] px-3 py-2.5 transition-colors hover:bg-[#f6f9f8]"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[11px] bg-[#f4f7f6] text-[#6f8585]">
                                    <IconMdiPaperclip class="size-[17px]" />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] font-bold text-[#10393b]">{{ doc.original_file_name }}</p>
                                    <p class="truncate text-[11.5px] text-[#6f8585]">{{ doc.document_type }} · {{ formatFileSize(doc.file_size) }}</p>
                                </div>

                                <span class="shrink-0 text-[11px] text-[#9bb0af] tabular-nums">{{ formatDate(doc.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </div>

        <UploadReportDocumentModal
            v-model:open="uploadModalOpen"
            :vehicle-id="vehicle.vehicle_id"
            :auftragsnummer-options="auftragsnummerOptions"
            :default-document-type="uploadDocumentType"
        />
    </AdminLayout>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
