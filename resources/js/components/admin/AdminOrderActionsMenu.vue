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
 * Deliberately absent: "create order" and "pull TÜV SÜD documents" — no
 * backend endpoint exists for either (see the Admin order docs), and a menu
 * entry with nothing to call would be dead UI.
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
import { getAdminDashboardStatus } from '@/lib/adminStatus';
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
    }>(),
    { orderId: null, auftragsnummer: null, orderStatus: null, availableTransitions: () => [], align: 'end' },
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

            <!--
                v1 parity, intentionally inert: both actions exist as Sanctum
                bearer-token API routes (order/{provider}/create/{vehicleId},
                tim/appraisal/xml/sync/{bewertungId}) that a session-based
                Inertia page cannot call. They stay visible-but-disabled so the
                menu matches v1 and the gap is obvious, rather than silently
                missing. See the handover notes for the web-route wiring needed.
            -->
            <DropdownMenuItem disabled title="Backend: noch keine Session-Route für die Auftragserstellung">
                <IconMdiClipboardPlusOutline />
                Auftrag erstellen
            </DropdownMenuItem>

            <DropdownMenuItem disabled title="Backend: noch keine Session-Route für den TÜV-SÜD-Abruf">
                <IconMdiSync />
                Dokumente abrufen
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

    <CreateOfferModal v-model:open="createOfferOpen" :order-id="orderId" />

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
