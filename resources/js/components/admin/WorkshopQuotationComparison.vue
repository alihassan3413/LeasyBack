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
 */
import type { AdminWorkshopQuotation } from '@/types/admin';

defineProps<{ quotation: AdminWorkshopQuotation }>();

function formatEuro(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const amount = Number.parseFloat(value);

    return Number.isFinite(amount) ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(amount) : '—';
}
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
    </div>
</template>
