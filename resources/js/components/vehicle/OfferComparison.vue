<script setup lang="ts">
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { useB2bPermissions } from '@/composables/useB2bPermissions';
import { formatPortalDate } from '@/lib/portalDate';
import type { OfferData } from '@/types/order';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCheck from '~icons/mdi/check';
import MdiInformationOutline from '~icons/mdi/information-outline';
import MdiTrendingDown from '~icons/mdi/trending-down';

const props = withDefaults(
    defineProps<{
        offers: OfferData[];
        /**
         * Accept on the customer's behalf via admin.orders.offers.select
         * instead of the customer's own offers.select, which
         * OfferPolicy::select() refuses for admins. Same split as
         * VehicleExpandedPanel's `admin` prop.
         */
        admin?: boolean;
        /** Drop the card chrome when the parent already provides a header (e.g. inside a modal). */
        bare?: boolean;
        /**
         * The order's channel, where the host knows it. Needed on Admin, whose
         * offer rows carry gross columns even for a B2B order; a customer's B2B
         * payload has no gross keys at all, which is detected without it.
         */
        vehicleBelongs?: 'B2B' | 'B2C' | null;
    }>(),
    { admin: false, bare: false, vehicleBelongs: null },
);

interface Row {
    net: keyof OfferData;
    gross: keyof OfferData;
    label: string;
}

const ROWS: Row[] = [
    { net: 'repair_cost_net', gross: 'repair_cost_gross', label: 'Reparaturkosten' },
    { net: 'depreciation_value_net', gross: 'depreciation_value_gross', label: 'Wertminderung' },
    { net: 'workshop_repair_quote_net', gross: 'workshop_repair_quote_gross', label: 'Werkstattkosten' },
    { net: 'missing_parts_cost_net', gross: 'missing_parts_cost_gross', label: 'Fehlteile' },
];

/**
 * Gross where the channel shows gross (B2C), net otherwise. B2B is priced net
 * only (b2b.txt §9) and its customer payload deliberately has no gross keys —
 * reading `*_gross` there printed "Gesamt (brutto) —", never awarded the
 * "Günstigster" badge and left the confirm dialog without an amount.
 */
const showsGross = computed(() => props.vehicleBelongs !== 'B2B' && props.offers.some((offer) => offer.final_total_gross != null));
const amountLabel = computed(() => (showsGross.value ? 'brutto' : 'netto'));

function rowAmount(offer: OfferData, row: Row): string | number | null {
    return (offer[showsGross.value ? row.gross : row.net] ?? null) as string | number | null;
}

/** The figure the table headlines, compares and asks the customer to confirm. */
function displayedTotal(offer: OfferData): string | number | null {
    return (showsGross.value ? offer.final_total_gross : offer.final_total_net) ?? null;
}

const selectingOfferId = ref<string | null>(null);

const sorted = computed(() => [...props.offers].sort((a, b) => a.offer_sequence - b.offer_sequence));
const hasSelected = computed(() => props.offers.some((offer) => offer.offer_status === 'selected'));

/**
 * "Günstigster" is advice about a decision the customer can still make, so it
 * is computed over the offers they could still choose — published, and not past
 * their validity date. A rejected offer, an already-closed sibling and an
 * expired one are all shown in the table for context and none of them can win
 * the badge.
 *
 * This used to compare every row in the table. Harmless while B2C offers were
 * always published and never expired; wrong the moment a B2C offer could be
 * rejected or carry a `valid_until`.
 */
const selectable = computed(() => sorted.value.filter((offer) => offer.offer_status === 'published' && !offer.presentation?.is_expired));

const bestTotal = computed(() => {
    const valid = selectable.value.map((offer) => toNumber(displayedTotal(offer))).filter((value): value is number => value !== null);

    return valid.length > 1 ? Math.min(...valid) : null;
});

/**
 * Rows every offer leaves at zero are hidden rather than printed as a column of
 * 0,00 €. A quotation-backed offer carries its whole amount in Reparaturkosten;
 * the other three exist for the manual fallback, which fills them in.
 */
const visibleRows = computed(() => ROWS.filter((row) => sorted.value.some((offer) => (toNumber(rowAmount(offer, row)) ?? 0) !== 0)));

function toNumber(value: string | number | null): number | null {
    if (value === null || value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function currency(value: string | number | null): string {
    const parsed = toNumber(value);

    if (parsed === null) {
        return '—';
    }

    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(parsed);
}

function formatDate(value: string | null): string {
    return formatPortalDate(value);
}

function isBest(offer: OfferData): boolean {
    return bestTotal.value !== null && selectable.value.includes(offer) && toNumber(displayedTotal(offer)) === bestTotal.value;
}

/**
 * Accepting is binding and closes every competing offer, so it is confirmed
 * rather than fired straight off the table button — the same reason the admin
 * side confirms a charge. The dialog names the offer and the amount, so the
 * decision being confirmed is the one the customer thinks they are making.
 */
const pendingOffer = ref<OfferData | null>(null);

const { can } = useB2bPermissions();

/**
 * Accepting an offer commits the company to a repair bill, so it is the
 * `offers.select` right — the one a Standard User deliberately lacks.
 *
 * Admin is unaffected: this component is reached with `admin` set from the
 * Admin order page, and `can()` answers true for any account with no company
 * membership, which every Admin and Privatkunde is. The server refuses the
 * endpoint either way; this only stops offering a button that would 403.
 */
const canDecide = computed(() => props.admin || can('offers.select'));

function askToSelect(offer: OfferData) {
    pendingOffer.value = offer;
}

function confirmSelection() {
    const offer = pendingOffer.value;

    if (!offer || selectingOfferId.value !== null) {
        return;
    }

    selectingOfferId.value = offer.offer_id;

    const options = {
        preserveScroll: true,
        // Closed on finish rather than on success: a conflict redirects back
        // with fresh offers, and leaving the dialog open over a table that has
        // just changed underneath would invite a second wrong click.
        onFinish: () => {
            selectingOfferId.value = null;
            pendingOffer.value = null;
        },
    };

    if (props.admin) {
        router.patch(route('admin.orders.offers.select', offer.offer_id), {}, options);

        return;
    }

    router.post(route('offers.select', offer.offer_id), {}, options);
}
</script>

<template>
    <section v-if="sorted.length" :class="bare ? '' : 'overflow-hidden rounded-[16px] border border-[#e6eded] bg-white'">
        <header v-if="!bare" class="flex items-center justify-between gap-3 border-b border-[#f1f5f5] px-5 py-4">
            <div>
                <h2 class="text-[15px] font-bold text-[#10393b]">Angebote</h2>
                <p class="mt-0.5 text-[12.5px] text-[#00000080]">
                    {{ hasSelected ? 'Sie haben ein Angebot angenommen.' : 'Vergleichen Sie die Angebote und wählen Sie eines aus.' }}
                </p>
            </div>
            <span class="shrink-0 rounded-full bg-[#f1f5f5] px-2.5 py-1 text-[11px] font-bold text-[#6f8585]">
                {{ sorted.length }} {{ sorted.length === 1 ? 'Angebot' : 'Angebote' }}
            </span>
        </header>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[520px] border-collapse">
                <thead>
                    <tr>
                        <th class="w-[38%] px-5 py-3 text-left text-[12px] font-medium text-[#9aacac]">Position ({{ amountLabel }})</th>
                        <th
                            v-for="offer in sorted"
                            :key="offer.offer_id"
                            class="px-4 py-3 text-right align-bottom"
                            :class="offer.offer_status === 'selected' ? 'bg-[#01B990]/[0.06]' : ''"
                        >
                            <div class="flex flex-col items-end gap-1">
                                <span class="text-[13px] font-bold text-[#10393b]">Angebot {{ offer.offer_sequence }}</span>
                                <span v-if="offer.published_at" class="text-[11px] font-normal text-[#9aacac]">
                                    {{ formatDate(offer.published_at) }}
                                </span>
                                <span
                                    v-if="offer.offer_status === 'selected'"
                                    class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-[#01B990] px-2 py-0.5 text-[10px] font-bold text-white"
                                >
                                    <MdiCheck class="text-[11px]" />
                                    Angenommen
                                </span>
                                <span
                                    v-else-if="isBest(offer)"
                                    class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-[#01B990]/12 px-2 py-0.5 text-[10px] font-bold text-[#01B990]"
                                >
                                    <MdiTrendingDown class="text-[11px]" />
                                    Günstigster
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="row in visibleRows" :key="row.net" class="border-t border-[#f1f5f5]">
                        <td class="px-5 py-2.5 text-[12.5px] text-[#00000080]">{{ row.label }}</td>
                        <td
                            v-for="offer in sorted"
                            :key="offer.offer_id"
                            class="px-4 py-2.5 text-right text-[13px] text-[#10393b] tabular-nums"
                            :class="offer.offer_status === 'selected' ? 'bg-[#01B990]/[0.06]' : ''"
                        >
                            {{ currency(rowAmount(offer, row)) }}
                        </td>
                    </tr>

                    <tr class="border-t border-[#e6eded] bg-[#fbfdfd]">
                        <td class="px-5 py-3.5 text-[13px] font-bold text-[#10393b]">Gesamt ({{ amountLabel }})</td>
                        <td
                            v-for="offer in sorted"
                            :key="offer.offer_id"
                            class="px-4 py-3.5 text-right"
                            :class="offer.offer_status === 'selected' ? 'bg-[#01B990]/[0.06]' : ''"
                        >
                            <span class="text-[16px] font-extrabold tabular-nums" :class="isBest(offer) ? 'text-[#01B990]' : 'text-[#10393b]'">
                                {{ currency(displayedTotal(offer)) }}
                            </span>
                            <span v-if="showsGross && offer.final_total_net" class="mt-0.5 block text-[11px] text-[#9aacac]">
                                {{ currency(offer.final_total_net) }} netto
                            </span>
                        </td>
                    </tr>

                    <tr v-if="sorted.some((offer) => offer.additional_notes)" class="border-t border-[#f1f5f5]">
                        <td class="px-5 py-3 align-top text-[12.5px] text-[#00000080]">Anmerkungen</td>
                        <td
                            v-for="offer in sorted"
                            :key="offer.offer_id"
                            class="px-4 py-3 text-right align-top text-[12px] leading-[1.45] text-[#00000080]"
                            :class="offer.offer_status === 'selected' ? 'bg-[#01B990]/[0.06]' : ''"
                        >
                            {{ offer.additional_notes || '—' }}
                        </td>
                    </tr>

                    <tr v-if="!hasSelected && canDecide" class="border-t border-[#f1f5f5]">
                        <td class="px-5 py-4"></td>
                        <td v-for="offer in sorted" :key="offer.offer_id" class="px-4 py-4 text-right">
                            <button
                                v-if="offer.offer_status === 'published' && canDecide"
                                type="button"
                                class="h-9 w-full rounded-full px-4 text-[13px] font-semibold text-white shadow-lg transition-all duration-200 disabled:cursor-not-allowed"
                                :style="selectingOfferId ? 'background: #D9D9D9;' : 'background: #EF8450;'"
                                :disabled="selectingOfferId !== null"
                                @click="askToSelect(offer)"
                            >
                                {{ selectingOfferId === offer.offer_id ? 'Wird gewählt…' : 'Annehmen' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :open="pendingOffer !== null"
            :title="admin ? 'Angebot im Auftrag des Kunden annehmen' : 'Angebot verbindlich annehmen'"
            :description="
                admin
                    ? 'Die Annahme wird im Namen des Kunden protokolliert und kann nicht zurückgenommen werden.'
                    : 'Ihre Auswahl ist verbindlich und kann nicht zurückgenommen werden.'
            "
            :width="520"
            @update:open="(value) => !value && selectingOfferId === null && (pendingOffer = null)"
        >
            <div v-if="pendingOffer" class="flex flex-col gap-4 px-2 pb-1">
                <div class="rounded-[14px] border border-[#e6eded] bg-[#fbfdfd] px-4 py-3.5">
                    <p class="text-[12px] text-[#00000080]">Angebot {{ pendingOffer.offer_sequence }}</p>
                    <p class="mt-1 text-[22px] leading-none font-extrabold text-[#10393b] tabular-nums">
                        {{ currency(displayedTotal(pendingOffer)) }}
                        <span class="text-[12px] font-bold text-[#9aacac]">{{ amountLabel }}</span>
                    </p>
                    <p v-if="showsGross && pendingOffer.final_total_net" class="mt-1 text-[12px] text-[#9aacac]">
                        {{ currency(pendingOffer.final_total_net) }} netto
                    </p>
                </div>

                <div class="flex items-start gap-2.5 text-[13px] leading-normal text-[#00000099]">
                    <MdiInformationOutline class="mt-0.5 shrink-0 text-[16px] text-[#9aacac]" />
                    <p v-if="sorted.length > 1">
                        Mit der Annahme werden die {{ sorted.length - 1 }} übrigen {{ sorted.length - 1 === 1 ? 'Angebot' : 'Angebote' }} für diesen
                        Auftrag geschlossen.
                    </p>
                    <p v-else>Nach der Annahme beauftragen wir die Werkstatt für Sie.</p>
                </div>
            </div>

            <template #footer>
                <AppModalButton variant="secondary" :disabled="selectingOfferId !== null" @click="pendingOffer = null"> Abbrechen </AppModalButton>
                <AppModalButton :disabled="selectingOfferId !== null" @click="confirmSelection">
                    {{ selectingOfferId !== null ? 'Wird gewählt…' : 'Verbindlich annehmen' }}
                </AppModalButton>
            </template>
        </AppModal>
    </section>
</template>
