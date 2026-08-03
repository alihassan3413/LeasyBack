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
 */
import CreateOfferModal from '@/components/admin/CreateOfferModal.vue';
import type { AdminOfferRow } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import MdiPlus from '~icons/mdi/plus';
import MdiTagOutline from '~icons/mdi/tag-outline';

defineProps<{ orderId: string; offers: AdminOfferRow[] }>();

const createModalOpen = ref(false);
const publishingId = ref<string | null>(null);
const cancellingId = ref<string | null>(null);
const confirmingCancelId = ref<string | null>(null);

const STATUS_LABELS: Record<AdminOfferRow['offer_status'], string> = {
    draft: 'Entwurf',
    published: 'Veröffentlicht',
    selected: 'Angenommen',
    closed: 'Geschlossen',
    cancelled: 'Storniert',
};

const STATUS_PILLS: Record<AdminOfferRow['offer_status'], string> = {
    draft: 'bg-[#f4f7f6] text-[#6f8585]',
    published: 'bg-[#4FA3A6]/15 text-[#2c7a7d]',
    selected: 'bg-[#01B990]/10 text-[#00856a]',
    closed: 'bg-[#f4f7f6] text-[#9bb0af]',
    cancelled: 'bg-[#E5533D]/10 text-[#c0392b]',
};

function formatCurrency(value: string | number | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value));
}

function publish(offer: AdminOfferRow) {
    publishingId.value = offer.offer_id;
    router.patch(route('admin.orders.offers.publish', offer.offer_id), {}, { preserveScroll: true, onFinish: () => (publishingId.value = null) });
}

function cancel(offer: AdminOfferRow) {
    cancellingId.value = offer.offer_id;
    router.patch(
        route('admin.orders.offers.cancel', offer.offer_id),
        {},
        {
            preserveScroll: true,
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
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-[13px] border border-[#e9efee] bg-white px-3.5 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                @click="createModalOpen = true"
            >
                <MdiPlus class="size-4" />
                Angebot
            </button>
        </div>

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

                <div class="mt-3 flex items-baseline gap-2">
                    <p class="text-[22px] leading-none font-extrabold tracking-[-0.6px] text-[#10393b] tabular-nums">
                        {{ formatCurrency(offer.final_total_gross) }}
                    </p>
                    <p class="text-[11px] font-bold tracking-[0.04em] text-[#9bb0af] uppercase">brutto</p>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-2">
                    <div class="rounded-[11px] bg-[#f8faf9] px-3 py-2">
                        <dt class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Netto</dt>
                        <dd class="mt-0.5 text-[12.5px] font-bold text-[#10393b] tabular-nums">
                            {{ formatCurrency(offer.final_total_net) }}
                        </dd>
                    </div>
                    <div class="rounded-[11px] bg-[#f8faf9] px-3 py-2">
                        <dt class="text-[10px] font-bold tracking-[0.05em] text-[#9bb0af] uppercase">Reparatur</dt>
                        <dd class="mt-0.5 text-[12.5px] font-bold text-[#10393b] tabular-nums">
                            {{ formatCurrency(offer.repair_cost_gross) }}
                        </dd>
                    </div>
                </dl>

                <p v-if="offer.additional_notes" class="mt-2.5 text-[12.5px] leading-relaxed text-[#6f8585]">
                    {{ offer.additional_notes }}
                </p>

                <p v-if="offer.cancellation_reason" class="mt-2.5 rounded-[11px] bg-[#E5533D]/8 px-3 py-2 text-[12px] text-[#c0392b]">
                    Grund: {{ offer.cancellation_reason }}
                </p>

                <div
                    v-if="offer.offer_status === 'draft' || offer.offer_status === 'published'"
                    class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-[#f2f6f5] pt-3.5"
                >
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
                            v-if="offer.offer_status === 'draft'"
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

    <CreateOfferModal v-model:open="createModalOpen" :order-id="orderId" />
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
