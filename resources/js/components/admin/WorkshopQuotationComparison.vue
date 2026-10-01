<script setup lang="ts">
/**
 * One workshop's quotation set against the appraisal, position by position.
 *
 * Extracted so the Werkstattangebote card and the offer-creation modal show
 * the same comparison from the same code: an admin deciding which quotation to
 * send a customer is answering the same question in both places, and two
 * copies of this table would eventually answer it differently.
 *
 * Net throughout (b2b.txt §9). The gross a B2C customer sees is derived
 * server-side when the offer is built, never here.
 *
 * Additional damage the workshop reported is reviewed here: with `reviewable`
 * (the Werkstattangebote card, while the order is in the offer phase) each
 * pending one offers "Übernehmen" — which creates a Gutachten position from it
 * — and "Ablehnen". The offer-creation modal leaves `reviewable` off and only
 * shows the status.
 */
import DamageGallery from '@/components/shared/DamageGallery.vue';
import type { AdminWorkshopQuotation } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

type ReviewStatus = 'pending' | 'accepted' | 'rejected';

type AdditionalPosition = AdminWorkshopQuotation['additional_positions'][number] & {
    review_status?: ReviewStatus;
    reviewed_at?: string | null;
    appraisal_position_id?: string | null;
};

const props = withDefaults(defineProps<{ quotation: AdminWorkshopQuotation; reviewable?: boolean }>(), { reviewable: false });

const busyId = ref<string | null>(null);
const reviewError = ref<string | null>(null);

const reviewStyles: Record<ReviewStatus, { label: string; class: string }> = {
    pending: { label: 'Prüfung offen', class: 'bg-[#d9a441]/15 text-[#a9741b]' },
    accepted: { label: 'Als Gutachtenposition übernommen', class: 'bg-[#01B990]/10 text-[#00856a]' },
    rejected: { label: 'Abgelehnt', class: 'bg-[#c0392b]/10 text-[#c0392b]' },
};

function statusOf(position: AdditionalPosition): ReviewStatus {
    return position.review_status ?? 'pending';
}

function review(position: AdditionalPosition, decision: 'accept' | 'reject') {
    if (decision === 'reject' && !window.confirm(`Zusätzlichen Schaden „${position.component}" wirklich ablehnen?`)) {
        return;
    }

    reviewError.value = null;
    busyId.value = position.id;

    router.post(
        route(`admin.orders.workshop-additional-positions.${decision}`, position.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => (reviewError.value = errors.additional_position ?? Object.values(errors)[0] ?? null),
            onFinish: () => (busyId.value = null),
        },
    );
}

function formatEuro(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const amount = Number.parseFloat(value);

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount) : '—';
}

const additionalPositions = () => props.quotation.additional_positions as AdditionalPosition[];
</script>

<template>
    <div>
        <div v-if="quotation.contact_person || quotation.contact_email" class="mb-3 text-[11.5px] text-[#6f8585]">
            {{ quotation.contact_person }} · {{ quotation.contact_email }}
            <template v-if="quotation.contact_phone"> · {{ quotation.contact_phone }}</template>
        </div>

        <p v-if="quotation.cannot_repair_note" class="mb-3 rounded-[9px] bg-[#c0392b]/5 px-2.5 py-2 text-[11.5px] text-[#c0392b]">
            {{ quotation.cannot_repair_note }}
        </p>

        <!-- Two axes of overflow, each handled where it belongs: the table has a
             min width so its columns stay readable and scrolls sideways inside
             this box, and a long position list scrolls vertically rather than
             pushing the card past the bottom of its column. -->
        <div class="max-h-[280px] overflow-x-auto overflow-y-auto">
            <table class="w-full min-w-[420px] border-collapse text-left">
                <thead class="sticky top-0 bg-white">
                    <tr class="border-b border-[#e9efee]">
                        <th class="py-1.5 pr-2 text-[11px] font-bold text-[#9bb0af]">Position</th>
                        <th class="py-1.5 pr-2 text-right text-[11px] font-bold text-[#9bb0af]">Gutachten</th>
                        <th class="py-1.5 pr-2 text-right text-[11px] font-bold text-[#9bb0af]">Werkstatt</th>
                        <th class="py-1.5 text-right text-[11px] font-bold text-[#9bb0af]">Differenz</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in quotation.comparison" :key="row.appraisal_position_id" class="border-b border-[#f6f9f8]">
                        <td class="py-1.5 pr-2 text-[11.5px] text-[#10393b]">
                            {{ row.component }}
                            <span v-if="row.not_repairable" class="text-[10.5px] font-bold text-[#c0392b]"> · n. instandsetzbar</span>
                            <span v-if="row.repair_method" class="block text-[10.5px] text-[#9bb0af]">{{ row.repair_method }}</span>
                        </td>
                        <td class="py-1.5 pr-2 text-right text-[11.5px] text-[#6f8585]">{{ formatEuro(row.appraisal_amount_net) }}</td>
                        <td class="py-1.5 pr-2 text-right text-[11.5px] font-bold text-[#10393b]">
                            {{ formatEuro(row.workshop_amount_net) }}
                        </td>
                        <td
                            class="py-1.5 text-right text-[11.5px] font-bold"
                            :class="row.difference_net && Number.parseFloat(row.difference_net) >= 0 ? 'text-[#00856a]' : 'text-[#c0392b]'"
                        >
                            {{ formatEuro(row.difference_net) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="sticky bottom-0 bg-white">
                    <tr>
                        <td class="py-2 pr-2 text-[11.5px] font-extrabold text-[#10393b]">Summe</td>
                        <td class="py-2 pr-2 text-right text-[11.5px] font-bold text-[#6f8585]">
                            {{ formatEuro(quotation.appraisal_total_net) }}
                        </td>
                        <td class="py-2 pr-2 text-right text-[11.5px] font-extrabold text-[#10393b]">
                            {{ formatEuro(quotation.total_net) }}
                        </td>
                        <td class="py-2 text-right text-[11.5px] font-extrabold text-[#00856a]"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Outside the table on purpose: these have no appraisal amount to sit
             beside, and merging them into it would read as if the Gutachten had
             listed them. -->
        <div v-if="quotation.additional_positions.length" class="mt-4" data-testid="admin-additional-positions">
            <p class="mb-2 text-[11.5px] font-extrabold text-[#a9741b]">Zusätzlicher Schaden – von der Werkstatt gemeldet</p>

            <p
                v-if="reviewError"
                role="alert"
                class="mb-2 rounded-[11px] border border-[#c0392b]/25 bg-[#c0392b]/5 px-3 py-2 text-[12px] font-bold text-[#c0392b]"
                data-testid="additional-position-review-error"
            >
                {{ reviewError }}
            </p>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="position in additionalPositions()"
                    :key="position.id"
                    class="rounded-[13px] border border-dashed p-3"
                    :class="
                        statusOf(position) === 'accepted'
                            ? 'border-[#01B990]/40 bg-[#01B990]/5'
                            : statusOf(position) === 'rejected'
                              ? 'border-[#e9efee] bg-[#f6f9f8] opacity-75'
                              : 'border-[#d9a441] bg-[#fffaf0]'
                    "
                    data-testid="admin-additional-position"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[12.5px] font-bold text-[#10393b]">{{ position.component }}</p>
                            <p class="mt-0.5 text-[11.5px] text-[#6f8585]">{{ position.damage_description }}</p>
                            <p v-if="position.repair_method" class="mt-0.5 text-[11.5px] text-[#9bb0af]">
                                Reparaturweg: {{ position.repair_method }}
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span class="text-[12.5px] font-extrabold text-[#10393b]">{{ formatEuro(position.amount_net) }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                                :class="reviewStyles[statusOf(position)].class"
                                data-testid="additional-position-status"
                            >
                                {{ reviewStyles[statusOf(position)].label }}
                            </span>
                        </div>
                    </div>

                    <DamageGallery
                        v-if="position.images.length"
                        class="mt-2"
                        :images="position.images"
                        :label="`Schadenbilder: ${position.component}`"
                    />

                    <div v-if="reviewable && statusOf(position) === 'pending'" class="mt-3 flex items-center justify-end gap-2">
                        <button
                            type="button"
                            :disabled="busyId !== null"
                            class="rounded-[11px] px-3 py-1.5 text-[11.5px] font-bold text-[#c0392b] transition-colors hover:bg-[#c0392b]/10 disabled:opacity-50"
                            data-testid="additional-position-reject"
                            @click="review(position, 'reject')"
                        >
                            Ablehnen
                        </button>
                        <button
                            type="button"
                            :disabled="busyId !== null"
                            class="rounded-[11px] bg-[#10393b] px-3 py-1.5 text-[11.5px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                            data-testid="additional-position-accept"
                            @click="review(position, 'accept')"
                        >
                            {{ busyId === position.id ? 'Wird übernommen…' : 'Als Gutachtenposition übernehmen' }}
                        </button>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>