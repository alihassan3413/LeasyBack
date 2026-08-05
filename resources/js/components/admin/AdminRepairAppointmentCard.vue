<script setup lang="ts">
/**
 * Confirmed workshop repair appointment (b2b.txt §11).
 *
 * The form seeds from the workshop quotation the customer offer was built on
 * (`earliest_repair_start` / `processing_days`) — those are the workshop's own
 * figures, so confirming them unchanged is the common case and re-typing them
 * would be busywork.
 *
 * Saving from `workshop_commissioned` also starts the repair phase; the server
 * owns that rule, this only says so.
 */
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import type { AdminWorkshopQuotation } from '@/types/admin';
import type { OrderCollectionData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiWrenchClock from '~icons/mdi/wrench-clock';

const props = defineProps<{
    orderId: string;
    orderStatus: string;
    collection: OrderCollectionData | null;
    /** The quotation the presented offer came from, when there is one. */
    sourceQuotation: AdminWorkshopQuotation | null;
}>();

const form = useForm(() => ({
    confirmed_repair_start_date:
        props.collection?.confirmed_repair_start_date ?? props.sourceQuotation?.earliest_repair_start ?? '',
    estimated_processing_days: String(
        props.collection?.estimated_processing_days ?? props.sourceQuotation?.processing_days ?? '',
    ),
}));

const isConfirmed = computed(() => !!props.collection?.confirmed_repair_start_date);

const startsRepairPhase = computed(() => props.orderStatus === 'workshop_commissioned');

const seed = computed(() => {
    const quotation = props.sourceQuotation;

    if (!quotation || isConfirmed.value) {
        return null;
    }

    const parts = [
        quotation.earliest_repair_start ? `frühester Beginn ${formatDate(quotation.earliest_repair_start)}` : '',
        quotation.processing_days != null ? `${quotation.processing_days} Arbeitstage` : '',
    ].filter(Boolean);

    return parts.length ? `${quotation.company_name || quotation.workshop_label}: ${parts.join(' · ')}` : null;
});

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function submit() {
    form.transform((data) => ({
        ...data,
        estimated_processing_days: data.estimated_processing_days === '' ? null : Number(data.estimated_processing_days),
    })).patch(route('admin.orders.repair-appointment', props.orderId), { preserveScroll: true });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiWrenchClock class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Reparaturtermin</h2>
                <p class="mt-0.5 text-[11.5px] font-medium text-[#9bb0af]">
                    {{ isConfirmed ? 'Bestätigt · jederzeit verschiebbar' : 'Noch nicht bestätigt' }}
                </p>
            </div>
        </div>

        <p v-if="seed" class="mb-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            Aus dem Werkstattangebot übernommen — {{ seed }}
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Bestätigter Reparaturbeginn</label>
                <CalendarDateField v-model="form.confirmed_repair_start_date" allow-past :invalid="!!form.errors.confirmed_repair_start_date" />
                <InputError :message="form.errors.confirmed_repair_start_date" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-[12px] font-bold text-[#10393b]">Voraussichtliche Dauer (Arbeitstage)</label>
                <Input v-model="form.estimated_processing_days" type="number" min="0" max="365" step="1" />
                <InputError :message="form.errors.estimated_processing_days" />
            </div>

            <p v-if="startsRepairPhase" class="text-[11.5px] text-[#6f8585]">
                Mit dem Speichern wechselt der Auftrag in die Reparaturphase.
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert...' : isConfirmed ? 'Termin aktualisieren' : 'Termin bestätigen' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>
