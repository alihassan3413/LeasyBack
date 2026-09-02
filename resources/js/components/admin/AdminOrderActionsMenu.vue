<script setup lang="ts">
/**
 * The single three-dots action menu for one leasyback order, shared by the
 * Admin vehicle detail page (one menu per order in the history) and the
 * Admin order list/detail pages — so "what can an admin do to an order" is
 * defined once.
 *
 * Every action reuses an endpoint/component that already exists:
 * status change → admin.orders.status, approve → admin.orders.approve,
 * create offer → CreateOfferModal, report/invoice upload →
 * UploadReportDocumentModal (the invoice variant is the same endpoint with
 * `document_type: rechnung`, which its `nullable|string` rule already
 * accepts and customerOrderFlow.ts already recognises).
 *
 * "Auftrag erstellen" reuses the customer's OrderCreationModal and its
 * orders.store route — VehicleScopeService's Admin branch is unfiltered, so
 * that route already accepts an admin booking for any vehicle. The modal
 * branches on `vehicleBelongs`, so a B2B vehicle gets the same collection
 * form (Wunschtermin + Abholadresse) the company user gets rather than the
 * B2C station/appointment form — which is also what `orders.store` validates
 * for a B2B vehicle. "Dokumente abrufen" posts to
 * admin.vehicles.reports.pull, which syncs the TÜV SÜD appraisal and copies
 * its documents in server-side.
 */
import CreateOfferModal from '@/components/admin/CreateOfferModal.vue';
import UploadReportDocumentModal from '@/components/admin/UploadReportDocumentModal.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AppModal } from '@/components/ui/modal';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import { getAdminDashboardStatus } from '@/lib/adminStatus';
import type { StationData } from '@/types/order';
import type { VehicleCollectionAddress } from '@/types/vehicle';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        vehicleId: string;
        /** Null on a vehicle that has no order yet — every order action is then disabled, as in v1. */
        orderId?: string | null;
        auftragsnummer?: string | null;
        orderStatus?: string | null;
        availableTransitions?: string[];
        align?: 'start' | 'center' | 'end';
        /** Inspection stations for the "Auftrag erstellen" picker; empty disables the action. */
        stations?: StationData[];
        /** True while the vehicle has an order that is neither delivered nor cancelled. */
        blocksNewOrder?: boolean;
        /** Only a TÜV SÜD order can have its appraisal documents pulled. */
        canPullDocuments?: boolean;
        /**
         * Which order-creation flow this vehicle uses. Left null where the
         * menu has no vehicle context (the order detail page, which disables
         * "Auftrag erstellen" anyway), and treated as B2C then.
         */
        vehicleBelongs?: 'B2B' | 'B2C' | null;
        /** The B2B vehicle's default pickup address, prefilled into the collection form. */
        collectionAddress?: VehicleCollectionAddress | null;
    }>(),
    {
        orderId: null,
        auftragsnummer: null,
        orderStatus: null,
        availableTransitions: () => [],
        align: 'end',
        stations: () => [],
        blocksNewOrder: false,
        canPullDocuments: false,
        vehicleBelongs: null,
        collectionAddress: null,
    },
);

const hasOrder = computed(() => !!props.orderId && !!props.auftragsnummer);

/**
 * `order_requested` is the only status approve() accepts — it is the
 * transition that fires the external TÜV SÜD call, which is why
 * available_transitions never contains `order_placed`.
 */
const canApprove = computed(() => hasOrder.value && props.orderStatus === 'order_requested');

const transitions = computed(() => (hasOrder.value ? props.availableTransitions.filter((status) => status !== 'cancelled') : []));
const canCancel = computed(() => hasOrder.value && props.availableTransitions.includes('cancelled'));

const auftragsnummerOptions = computed(() => (props.auftragsnummer ? [{ value: props.auftragsnummer, label: props.auftragsnummer }] : []));

const isB2bVehicle = computed(() => props.vehicleBelongs === 'B2B');

/** What OrderCreationModal needs to pick its flow — the B2C branch passes no vehicle at all. */
const orderCreationVehicle = computed(() =>
    props.vehicleBelongs === null ? null : { vehicle_belongs: props.vehicleBelongs, collection_address: props.collectionAddress },
);

/**
 * OrderService rejects a second order unless the vehicle's only orders were
 * called off (blocksNewOrder) — a running order and a completed one both bar
 * it. A B2B collection order books no inspection appointment, so it does not
 * need a station either — requiring one would disable the action on every B2B
 * vehicle.
 */
const canCreateOrder = computed(() => !props.blocksNewOrder && (isB2bVehicle.value || props.stations.length > 0));

const createOrderHint = computed(() => {
    if (props.blocksNewOrder) {
        // Deliberately not "läuft bereits ein Auftrag": the flag is also true
        // for a finished one, and that wording sent admins looking for an open
        // order that had closed weeks ago.
        return 'Für dieses Fahrzeug besteht bereits ein Auftrag';
    }

    return canCreateOrder.value ? '' : 'Keine aktive Begutachtungsstelle hinterlegt';
});

const createOrderOpen = ref(false);
const pulling = ref(false);

function pullDocuments() {
    pulling.value = true;

    router.post(route('admin.vehicles.reports.pull', props.vehicleId), {}, { preserveScroll: true, onFinish: () => (pulling.value = false) });
}

const createOfferOpen = ref(false);
const noShowOpen = ref(false);
const markingNoShow = ref(false);

/**
 * Only where a TÜV appointment could actually have been missed: a B2C order
 * that has been confirmed but not yet inspected. The endpoint refuses anything
 * else; this decides whether the action is worth offering.
 */
const NO_SHOW_STATUSES = new Set(['confirmed']);

const canMarkNoShow = computed(() => !!props.orderId && props.vehicleBelongs !== 'B2B' && NO_SHOW_STATUSES.has(props.orderStatus ?? ''));

function markNoShow() {
    if (!props.orderId) {
        return;
    }

    markingNoShow.value = true;

    router.post(
        route('admin.orders.no-show', props.orderId),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                markingNoShow.value = false;
                noShowOpen.value = false;
            },
        },
    );
}
const uploadOpen = ref(false);
const uploadVariant = ref<UploadVariant>('gutachten');
const cancelDialogOpen = ref(false);
const busy = ref(false);

type UploadVariant = 'gutachten' | 'nachgutachten' | 'rechnung';

/**
 * `nachgutachten` was previously unreachable from this menu: both report
 * uploads opened titled "Gutachten hochladen" preset to `gutachten`, so the
 * follow-up report could only be filed correctly by noticing the type dropdown
 * inside the modal — and filing it wrong leaves upload_final_appraisal open
 * with no visible cause.
 */
const UPLOAD_VARIANTS: Record<UploadVariant, { documentType: string; title: string }> = {
    gutachten: { documentType: 'gutachten', title: 'Erstgutachten hochladen' },
    nachgutachten: { documentType: 'nachgutachten', title: 'Nachgutachten hochladen' },
    rechnung: { documentType: 'rechnung', title: 'Rechnung hochladen' },
};

const uploadPreset = computed(() => {
    const variant = UPLOAD_VARIANTS[uploadVariant.value] ?? UPLOAD_VARIANTS.gutachten;

    return { ...variant, description: `${variant.title} für Auftrag ${props.auftragsnummer}.` };
});

function openUpload(variant: UploadVariant) {
    uploadVariant.value = variant;
    uploadOpen.value = true;
}

/**
 * Driven by the tasks card so a task opens the modal it actually means,
 * already configured — rather than duplicating the uploader next to the card.
 */
defineExpose({
    openUpload,
    openCreateOffer: () => (createOfferOpen.value = true),
});

function transitionTo(status: string) {
    if (!props.orderId) {
        return;
    }

    busy.value = true;

    router.patch(
        route('admin.orders.status', props.orderId),
        { status },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                cancelDialogOpen.value = false;
            },
        },
    );
}

/**
 * Cancelling is irreversible and visible to the customer, so it asks in a
 * real dialog rather than the in-menu two-click confirm the lighter actions
 * use — a menu item that quietly turns into "click again" is too easy to
 * trigger by accident.
 */
function requestCancel() {
    cancelDialogOpen.value = true;
}

function confirmCancel() {
    transitionTo('cancelled');
}

function approve() {
    if (!props.orderId) {
        return;
    }

    busy.value = true;

    router.post(route('admin.orders.approve', props.orderId), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
}

function statusLabel(status: string): string {
    return getAdminDashboardStatus(status).label;
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                :disabled="busy"
                class="flex h-8 w-8 items-center justify-center rounded-[9px] text-[#bcccca] transition-all hover:bg-[#10393b] hover:text-white disabled:opacity-40"
                :title="`Aktionen für ${auftragsnummer}`"
                :aria-label="`Aktionen für ${auftragsnummer}`"
                @click.stop
            >
                <IconMdiDotsVertical class="size-[17px]" />
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent :align="align" class="w-64" @click.stop>
            <DropdownMenuLabel v-if="hasOrder" class="font-mono text-[11px] text-[#9bb0af]">{{ auftragsnummer }}</DropdownMenuLabel>
            <DropdownMenuItem v-else disabled>
                <IconMdiInformationOutline />
                Kein Auftrag vorhanden
            </DropdownMenuItem>
            <DropdownMenuSeparator />

            <DropdownMenuItem v-if="canApprove" @select="approve">
                <IconMdiCheckDecagramOutline />
                Freigeben (an TÜV SÜD senden)
            </DropdownMenuItem>

            <DropdownMenuSub v-if="transitions.length">
                <DropdownMenuSubTrigger>
                    <IconMdiSwapHorizontal />
                    Status aktualisieren
                </DropdownMenuSubTrigger>
                <DropdownMenuSubContent>
                    <DropdownMenuItem v-for="status in transitions" :key="status" @select="transitionTo(status)">
                        {{ statusLabel(status) }}
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuSub>

            <DropdownMenuSeparator v-if="canApprove || transitions.length" />

            <DropdownMenuItem v-if="canMarkNoShow" class="text-[#c0392b] focus:text-[#c0392b]" @select="noShowOpen = true">
                <IconMdiAccountCancelOutline />
                Termin nicht wahrgenommen
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="!hasOrder" @select="createOfferOpen = true">
                <IconMdiTagPlusOutline />
                Angebot erstellen
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="!hasOrder" @select="openUpload('gutachten')">
                <IconMdiFileUploadOutline />
                Erstgutachten hochladen
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="!hasOrder" @select="openUpload('nachgutachten')">
                <IconMdiFileUploadOutline />
                Nachgutachten hochladen
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="!hasOrder" @select="openUpload('rechnung')">
                <IconMdiReceiptTextOutline />
                Rechnung hochladen
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem :disabled="!canCreateOrder" :title="createOrderHint" @select="createOrderOpen = true">
                <IconMdiClipboardPlusOutline />
                Auftrag erstellen
            </DropdownMenuItem>

            <DropdownMenuItem
                :disabled="!canPullDocuments || pulling"
                :title="canPullDocuments ? 'Gutachten-Dokumente von TÜV SÜD übernehmen' : 'Nur für TÜV SÜD Aufträge mit Gutachtennummer verfügbar'"
                @select="pullDocuments"
            >
                <IconMdiSync :class="pulling ? 'animate-spin' : ''" />
                {{ pulling ? 'Dokumente werden abgerufen…' : 'Dokumente abrufen' }}
            </DropdownMenuItem>

            <template v-if="$slots.extra">
                <DropdownMenuSeparator />
                <slot name="extra" />
            </template>

            <template v-if="canCancel">
                <DropdownMenuSeparator />
                <DropdownMenuItem class="text-[#b91c1c] focus:bg-[#fee2e2] focus:text-[#991b1b]" @select="requestCancel">
                    <IconMdiCloseCircleOutline />
                    Auftrag stornieren
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <div v-if="cancelDialogOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/20 p-4" @click="cancelDialogOpen = false">
        <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" @click.stop>
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#fee2e2] text-[#b91c1c]">
                    <IconMdiAlertOutline class="size-5" />
                </span>

                <div class="min-w-0">
                    <h3 class="text-[17px] font-bold text-[#10393b]">Auftrag stornieren</h3>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-[#5a6b7a]">
                        Auftrag <span class="font-mono font-bold text-[#10393b]">{{ auftragsnummer }}</span> wird storniert. Der Kunde sieht die
                        Stornierung in seiner Auftragsübersicht. Dieser Schritt kann nicht rückgängig gemacht werden.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-full border border-[#e9efee] px-5 py-2.5 text-[13.5px] font-bold text-[#10393b] transition-all hover:bg-[#f4f7f6] disabled:opacity-50"
                    @click="cancelDialogOpen = false"
                >
                    Abbrechen
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-full bg-[#EF4444] px-5 py-2.5 text-[13.5px] font-bold text-white transition-all hover:bg-[#dc2626] disabled:opacity-50"
                    @click="confirmCancel"
                >
                    {{ busy ? 'Wird storniert…' : 'Auftrag stornieren' }}
                </button>
            </div>
        </div>
    </div>

    <OrderCreationModal
        v-if="canCreateOrder"
        v-model:open="createOrderOpen"
        :vehicle-id="vehicleId"
        :stations="stations"
        :vehicle="orderCreationVehicle"
    />

    <AppModal :open="noShowOpen" title="Termin als nicht wahrgenommen markieren?" :width="560" @update:open="(v) => (noShowOpen = v)">
        <div class="min-w-0 space-y-4 px-2">
            <p class="text-sm leading-relaxed text-black">
                Damit wird festgehalten, dass der Kunde den TÜV-Termin nicht wahrgenommen hat. Dadurch wird eine Gebühr von
                <span class="font-bold">200,00 €</span> ausgelöst und der hinterlegten Zahlungsmethode des Kunden belastet.
            </p>
            <p class="text-muted-foreground text-sm">Dieser Schritt kann nicht rückgängig gemacht werden.</p>
        </div>

        <template #footer>
            <button
                type="button"
                class="rounded-[5px] border border-[#e9efee] bg-white px-8 py-2.5 text-sm font-bold text-[#10393b] hover:bg-[#f4f7f6]"
                @click="noShowOpen = false"
            >
                Abbrechen
            </button>
            <button
                type="button"
                :disabled="markingNoShow"
                class="rounded-[5px] bg-[#E5533D] px-8 py-2.5 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                @click="markNoShow"
            >
                {{ markingNoShow ? 'Wird gespeichert …' : 'Nicht wahrgenommen — 200,00 € berechnen' }}
            </button>
        </template>
    </AppModal>

    <CreateOfferModal v-if="orderId" v-model:open="createOfferOpen" :order-id="orderId" />

    <UploadReportDocumentModal
        v-model:open="uploadOpen"
        :vehicle-id="vehicleId"
        :auftragsnummer-options="auftragsnummerOptions"
        :default-auftragsnummer="auftragsnummer"
        :default-document-type="uploadPreset.documentType"
        :title="uploadPreset.title"
        :description="uploadPreset.description"
    />
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
