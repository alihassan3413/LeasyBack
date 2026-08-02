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
 * that route already accepts an admin booking for any vehicle. "Dokumente
 * abrufen" posts to admin.vehicles.reports.pull, which syncs the TÜV SÜD
 * appraisal and copies its documents in server-side.
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
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import { getAdminDashboardStatus } from '@/lib/adminStatus';
import type { StationData } from '@/types/order';
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
        hasOpenOrder?: boolean;
        /** Only a TÜV SÜD order can have its appraisal documents pulled. */
        canPullDocuments?: boolean;
    }>(),
    {
        orderId: null,
        auftragsnummer: null,
        orderStatus: null,
        availableTransitions: () => [],
        align: 'end',
        stations: () => [],
        hasOpenOrder: false,
        canPullDocuments: false,
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

/** OrderService rejects a second order while one is still running (hasUnfinishedOrder). */
const canCreateOrder = computed(() => !props.hasOpenOrder && props.stations.length > 0);

const createOrderHint = computed(() => {
    if (props.hasOpenOrder) {
        return 'Für dieses Fahrzeug läuft bereits ein Auftrag';
    }

    return props.stations.length === 0 ? 'Keine aktive Begutachtungsstelle hinterlegt' : '';
});

const createOrderOpen = ref(false);
const pulling = ref(false);

function pullDocuments() {
    pulling.value = true;

    router.post(route('admin.vehicles.reports.pull', props.vehicleId), {}, { preserveScroll: true, onFinish: () => (pulling.value = false) });
}

const createOfferOpen = ref(false);
const uploadOpen = ref(false);
const uploadVariant = ref<'gutachten' | 'rechnung'>('gutachten');
const cancelDialogOpen = ref(false);
const busy = ref(false);

const uploadPreset = computed(() =>
    uploadVariant.value === 'rechnung'
        ? { documentType: 'rechnung', title: 'Rechnung hochladen', description: `Rechnung für Auftrag ${props.auftragsnummer} hochladen.` }
        : { documentType: 'gutachten', title: 'Gutachten hochladen', description: `Gutachten für Auftrag ${props.auftragsnummer} hochladen.` },
);

function openUpload(variant: 'gutachten' | 'rechnung') {
    uploadVariant.value = variant;
    uploadOpen.value = true;
}

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

            <DropdownMenuItem :disabled="!hasOrder" @select="createOfferOpen = true">
                <IconMdiTagPlusOutline />
                Angebot erstellen
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="!hasOrder" @select="openUpload('gutachten')">
                <IconMdiFileUploadOutline />
                Bericht hochladen
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

    <OrderCreationModal v-if="stations.length" v-model:open="createOrderOpen" :vehicle-id="vehicleId" :stations="stations" />

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
