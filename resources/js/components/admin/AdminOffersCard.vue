<script setup lang="ts">
/**
 * Admin counterpart to the customer-facing OffersCard.vue: shows every
 * offer (not just published/selected) and lets Admin create/publish/cancel
 * them, rather than a customer selecting one.
 *
 * Styled with the order page's own card vocabulary (content-card, #10393b /
 * #01B990, the [10.5px] status pill) rather than the shared shadcn Card —
 * sitting beside the Halter and Dokumente cards, the generic component read
 * as a different product.
 *
 * Each offer is labelled with where its numbers came from. An offer built from
 * a workshop quotation names the workshop; one typed into the fallback modal
 * says so. The two are told apart by the presence of a presentation row, not by
 * a flag — a hand-entered offer has no way to claim a source it does not have.
 *
 * B2B is priced net only (b2b.txt §9): a B2B offer's headline and repair figure
 * are net and labelled so, never gross. B2C keeps its gross headline.
 *
 * Create and publish follow `editable` (the order is `inspected` and no offer
 * has been accepted); cancel only ever applies to a draft or published offer.
 * A refusal comes back under the `offer` error key and is shown above the list.
 */
import CreateOfferModal from '@/components/admin/CreateOfferModal.vue';
import type { AdminOfferRow, AdminWorkshopQuotation } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiPlus from '~icons/mdi/plus';
import MdiTagOutline from '~icons/mdi/tag-outline';

/**
 * `quotations` is passed straight through to the create modal, so creating an
 * offer can start from a workshop's prices instead of an empty form.
 */
const props = withDefaults(
    defineProps<{
        orderId: string;
        offers: AdminOfferRow[];
        quotations?: AdminWorkshopQuotation[];
        vehicleBelongs: 'B2B' | 'B2C';
        /** AdminOrderDetail.editable.offers — gates creating and publishing. */
        editable: boolean;
    }>(),
    { quotations: () => [] },
);

const isB2b = computed(() => props.vehicleBelongs === 'B2B');

/** The refusal of the last publish/cancel (`offer` error key); the flash toast says it too. */
const offerError = ref<string | null>(null);

function rememberError(errors: Record<string, string>) {
    offerError.value = errors.offer ?? Object.values(errors)[0] ?? null;
}

const createModalOpen = ref(false);

/** Driven by the tasks card, so a task opens this card's own modal. */
defineExpose({
    /** True when the modal opened — false when offers can no longer be created. */
    openCreate: (): boolean => {
        if (props.editable) {
            createModalOpen.value = true;
        }

        return props.editable;
    },
});
const publishingId = ref<string | null>(null);
const cancellingId = ref<string | null>(null);
const confirmingCancelId = ref<string | null>(null);

function provenance(offer: AdminOfferRow): { label: string; backed: boolean } {
    if (offer.presentation == null) {
        return { label: 'Manuell erfasst', backed: false };
    }

    const workshop = offer.workshop?.company_name ?? offer.workshop?.label ?? offer.presentation.workshop_name;

    return { label: workshop ? `Werkstattangebot · ${workshop}` : 'Werkstattangebot', backed: true };
}

const STATUS_LABELS: Record<AdminOfferRow['offer_status'], string> = {
    draft: 'Entwurf',
    published: 'Veröffentlicht',
    selected: 'Angenommen',
    closed: 'Geschlossen',
    cancelled: 'Storniert',
    rejected: 'Abgelehnt',
};

const STATUS_PILLS: Record<AdminOfferRow['offer_status'], string> = {
    draft: 'bg-[#f4f7f6] text-[#6f8585]',
    published: 'bg-[#4FA3A6]/15 text-[#2c7a7d]',
    selected: 'bg-[#01B990]/10 text-[#00856a]',
    closed: 'bg-[#f4f7f6] text-[#9bb0af]',
    cancelled: 'bg-[#E5533D]/10 text-[#c0392b]',
    rejected: 'bg-[#E5533D]/10 text-[#c0392b]',
};

/** An offer the server still lets Admin withdraw — OfferService::cancelOffer(). */
function isCancellable(offer: AdminOfferRow): boolean {
    return offer.offer_status === 'draft' || offer.offer_status === 'published';
}

function formatCurrency(value: string | number | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function publish(offer: AdminOfferRow) {
    publishingId.value = offer.offer_id;
    offerError.value = null;
    router.patch(
        route('admin.orders.offers.publish', offer.offer_id),
        {},
        { preserveScroll: true, onError: rememberError, onFinish: () => (publishingId.value = null) },
    );
}

function cancel(offer: AdminOfferRow) {
    cancellingId.value = offer.offer_id;
    offerError.value = null;
    router.patch(
        route('admin.orders.offers.cancel', offer.offer_id),
        {},
        {
            preserveScroll: true,
            onError: rememberError,
            onFinish: () => {
                cancellingId.value = null;
                confirmingCancelId.value = null;
            },
        },
    );
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                    <MdiTagOutline class="size-[17px]" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Angebote</h2>
                    <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ offers.length }} Angebote</p>
                </div>
            </div>

            <button
                v-if="editable"
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-3.5 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                @click="createModalOpen = true"
            >
                <MdiPlus class="size-4" />
                Angebot
            </button>
        </div>

        <p
            v-if="offerError"
            role="alert"
            class="mb-3 rounded-[11px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-3 py-2 text-[12px] font-bold text-[#c0392b]"
        >
            {{ offerError }}
        </p>

        <p v-if="!offers.length" class="py-10 text-center text-[13px] text-[#9bb0af]">Noch keine Angebote.</p>

        <div v-else class="flex flex-col gap-2.5">
            <div
                v-for="offer in offers"
                :key="offer.offer_id"
                class="rounded-[16px] border border-[#eef3f2] p-4 transition-colors hover:border-[#dbe7e5]"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-[8px] bg-[#10393b] text-[11px] font-extrabold text-white tabular-nums"
                        >
                            {{ offer.offer_sequence }}
                        </span>
                        <span class="truncate text-[13px] font-bold text-[#10393b]">Angebot {{ offer.offer_sequence }}</span>
                    </div>

                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold" :class="STATUS_PILLS[offer.offer_status]">
                        {{ STATUS_LABELS[offer.offer_status] }}
                    </span>
                </div>

                <p
                    class="mt-2 inline-flex max-w-full items-center gap-1 truncate rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                    :class="provenance(offer).backed ? 'bg-[#4FA3A6]/12 text-[#2c7a7d]' : 'bg-[#f4f7f6] text-[#9bb0af]'"
                >
                    {{ provenance(offer).label }}
                </p>

                <div class="mt-3 flex items-baseline gap-2">
                    <p class="text-[22px] leading-none font-extrabold tracking-[-0.6px] text-[#10393b] tabular-nums">
                        {{ formatCurrency(isB2b ? offer.final_total_net : offer.final_total_gross) }}
                    </p>
                    <p class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">{{ isB2b ? 'netto' : 'brutto' }}</p>
                </div>

                <dl class="mt-3 grid gap-2" :class="isB2b ? 'grid-cols-1' : 'grid-cols-2'">
                    <div v-if="!isB2b" class="rounded-[11px] bg-[#f8faf9] px-3 py-2">
                        <dt class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Netto</dt>
                        <dd class="mt-0.5 text-[12.5px] font-bold text-[#10393b] tabular-nums">
                            {{ formatCurrency(offer.final_total_net) }}
                        </dd>
                    </div>
                    <div class="rounded-[11px] bg-[#f8faf9] px-3 py-2">
                        <dt class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">
                            {{ isB2b ? 'Reparatur netto' : 'Reparatur brutto' }}
                        </dt>
                        <dd class="mt-0.5 text-[12.5px] font-bold text-[#10393b] tabular-nums">
                            {{ formatCurrency(isB2b ? offer.repair_cost_net : offer.repair_cost_gross) }}
                        </dd>
                    </div>
                </dl>

                <p v-if="offer.additional_notes" class="mt-2.5 text-[12.5px] leading-relaxed text-[#6f8585]">
                    {{ offer.additional_notes }}
                </p>

                <p v-if="offer.cancellation_reason" class="mt-2.5 rounded-[11px] bg-[#E5533D]/8 px-3 py-2 text-[12px] text-[#c0392b]">
                    Grund: {{ offer.cancellation_reason }}
                </p>

                <div v-if="isCancellable(offer)" class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-[#f2f6f5] pt-3.5">
                    <template v-if="confirmingCancelId === offer.offer_id">
                        <p class="mr-auto text-[12px] font-bold text-[#10393b]">
                            {{ offer.offer_status === 'draft' ? 'Angebot verwerfen?' : 'Angebot zurückziehen?' }}
                        </p>
                        <button
                            type="button"
                            class="rounded-[11px] px-3 py-2 text-[12.5px] font-bold text-[#6f8585] transition-colors hover:bg-[#f4f7f6]"
                            @click="confirmingCancelId = null"
                        >
                            Abbrechen
                        </button>
                        <button
                            type="button"
                            :disabled="cancellingId === offer.offer_id"
                            class="rounded-[11px] bg-[#E5533D] px-3.5 py-2 text-[12.5px] font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                            @click="cancel(offer)"
                        >
                            {{ cancellingId === offer.offer_id ? 'Wird gespeichert …' : 'Bestätigen' }}
                        </button>
                    </template>

                    <template v-else>
                        <button
                            v-if="offer.offer_status === 'draft' && editable"
                            type="button"
                            :disabled="publishingId === offer.offer_id"
                            class="rounded-[11px] bg-[#01B990] px-3.5 py-2 text-[12.5px] font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                            @click="publish(offer)"
                        >
                            {{ publishingId === offer.offer_id ? 'Wird veröffentlicht …' : 'Veröffentlichen' }}
                        </button>

                        <button
                            type="button"
                            class="rounded-[11px] border border-[#f3d9d3] bg-white px-3.5 py-2 text-[12.5px] font-bold text-[#E5533D] transition-colors hover:bg-[#fdeeeb]"
                            @click="confirmingCancelId = offer.offer_id"
                        >
                            {{ offer.offer_status === 'draft' ? 'Verwerfen' : 'Zurückziehen' }}
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <CreateOfferModal v-model:open="createModalOpen" :order-id="orderId" :quotations="quotations" :vehicle-belongs="vehicleBelongs" />
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
