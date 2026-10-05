<script setup lang="ts">
/**
 * A Gutachten order on the customer's pages (order page and vehicle page):
 * every vehicle the order covers, what was booked, the appointment, and the
 * final report once the order is completed.
 */
import { appraisalDetailRows, type AppraisalDetails, type AppraisalVehicle, type CustomerOrderCollection } from '@/lib/customerOrderFlow';
import type { OrderAttachmentData } from '@/types/order';
import { computed } from 'vue';
import MdiFileDocumentOutline from '~icons/mdi/file-document-outline';

const props = defineProps<{
    details: AppraisalDetails | null;
    collection?: CustomerOrderCollection | null;
    vehicles: AppraisalVehicle[];
    /** The final report. The server sends it only once the order is completed. */
    reports: OrderAttachmentData[];
    completed: boolean;
}>();

const rows = computed(() => (props.details ? appraisalDetailRows(props.details, props.collection ?? null) : []));

function vehicleLine(vehicle: AppraisalVehicle): string {
    return [[vehicle.make, vehicle.model].filter(Boolean).join(' '), vehicle.vin ? 'FIN ' + vehicle.vin : ''].filter(Boolean).join(' · ');
}
</script>

<template>
    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Fahrzeuge im Auftrag</h2>
            <p class="mt-0.5 text-[12.5px] text-[#00000080]">
                {{ vehicles.length }} {{ vehicles.length === 1 ? 'Fahrzeug' : 'Fahrzeuge' }} unter einer Auftragsnummer.
            </p>
        </header>

        <ul>
            <li
                v-for="(item, index) in vehicles"
                :key="item.vehicle_id ?? index"
                class="border-b border-[#f1f5f5] px-5 py-2.5 last:border-b-0"
            >
                <p class="text-[13px] font-semibold text-[#10393b]">{{ item.license_plate || '—' }}</p>
                <p v-if="vehicleLine(item)" class="truncate text-[11.5px] text-[#9aacac]">{{ vehicleLine(item) }}</p>
            </li>
        </ul>
    </section>

    <section v-if="rows.length" class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Gutachten</h2>
        </header>

        <dl class="divide-y divide-[#f1f5f5]">
            <div v-for="row in rows" :key="row.label" class="flex items-baseline justify-between gap-4 px-5 py-2.5">
                <dt class="shrink-0 text-[12.5px] text-[#00000080]">{{ row.label }}</dt>
                <dd class="text-right text-[13px] font-semibold text-[#10393b]">{{ row.value }}</dd>
            </div>
        </dl>
    </section>

    <section class="overflow-hidden rounded-[16px] border border-[#e6eded] bg-white">
        <header class="border-b border-[#f1f5f5] px-5 py-4">
            <h2 class="text-[15px] font-bold text-[#10393b]">Abschlussgutachten</h2>
        </header>

        <p v-if="!reports.length" class="px-5 py-4 text-[12.5px] text-[#9aacac]">
            {{ completed ? 'Für diesen Auftrag ist kein Gutachten hinterlegt.' : 'Steht bereit, sobald der Auftrag abgeschlossen ist.' }}
        </p>

        <ul v-else>
            <li v-for="file in reports" :key="file.id" class="flex items-center gap-3 border-b border-[#f1f5f5] px-5 py-3 last:border-b-0">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#01B990]">
                    <MdiFileDocumentOutline class="text-[18px]" />
                </span>
                <span class="min-w-0 flex-1 truncate text-[13px] font-semibold text-[#10393b]">{{ file.original_name }}</span>
                <a
                    v-if="file.url"
                    :href="file.url"
                    target="_blank"
                    rel="noopener"
                    class="shrink-0 rounded-full border border-[#d8e4e3] px-3 py-1 text-[11.5px] font-semibold text-[#10393b] transition hover:border-[#01B990] hover:text-[#01B990]"
                >
                    Öffnen
                </a>
            </li>
        </ul>
    </section>
</template>
