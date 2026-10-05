<script setup lang="ts">
/**
 * Gutachten: operations confirm or adjust the appointment (a date and a time
 * window of at least two hours), name the inspection site and confirm the
 * transport. Saving date and window is what schedules the order.
 */
import { formatPortalDate } from '@/lib/portalDate';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ScheduleCollection {
    confirmed_collection_date?: string | null;
    confirmed_collection_time_slot?: string | null;
    inspection_site_name?: string | null;
    inspection_site_address?: string | null;
    transport_confirmed?: boolean | null;
    internal_note?: string | null;
}

/** What the customer asked for in the booking form. */
interface ScheduleRequest {
    requested_date: string | null;
    time_from: string | null;
    time_to: string | null;
    pickup_requested: boolean;
    return_transport: boolean;
}

const props = defineProps<{
    orderId: string;
    collection: ScheduleCollection | null;
    request: ScheduleRequest | null;
    /** False once the order is completed or cancelled — the server refuses a change then. */
    editable: boolean;
}>();

function savedSlotPart(index: 0 | 1): string {
    return (props.collection?.confirmed_collection_time_slot ?? '').split('-')[index] ?? '';
}

const form = useForm({
    confirmed_collection_date: props.collection?.confirmed_collection_date ?? '',
    confirmed_time_from: savedSlotPart(0),
    confirmed_time_to: savedSlotPart(1),
    inspection_site_name: props.collection?.inspection_site_name ?? '',
    inspection_site_address: props.collection?.inspection_site_address ?? '',
    transport_confirmed: !!props.collection?.transport_confirmed,
    internal_note: props.collection?.internal_note ?? '',
});

const isScheduled = computed(() => !!props.collection?.confirmed_collection_date && !!props.collection?.confirmed_collection_time_slot);

const requestedText = computed(() => {
    const request = props.request;

    if (!request?.requested_date) {
        return '';
    }

    const window = request.time_from && request.time_to ? request.time_from + '–' + request.time_to + ' Uhr' : '';

    return [formatPortalDate(request.requested_date), window].filter(Boolean).join(', ');
});

const transportText = computed(() => {
    if (!props.request?.pickup_requested) {
        return 'Keine Abholung gewünscht — der Gutachter kommt zum Fahrzeugstandort.';
    }

    return props.request.return_transport
        ? 'Der Kunde wünscht die Abholung zur Prüfstelle (DEKRA/TÜV) und den Rücktransport.'
        : 'Der Kunde wünscht die Abholung zur Prüfstelle (DEKRA/TÜV), ohne Rücktransport.';
});

const savedRows = computed(() =>
    [
        {
            label: 'Termin',
            value: [formatPortalDate(props.collection?.confirmed_collection_date ?? null), props.collection?.confirmed_collection_time_slot]
                .filter(Boolean)
                .join(', '),
        },
        {
            label: 'Prüfstelle',
            value: [props.collection?.inspection_site_name, props.collection?.inspection_site_address].filter(Boolean).join(', '),
        },
        { label: 'Transport', value: props.request?.pickup_requested ? (props.collection?.transport_confirmed ? 'Bestätigt' : 'Noch offen') : '' },
        { label: 'Interne Notiz', value: props.collection?.internal_note ?? '' },
    ].filter((row) => !!row.value),
);

function adoptRequest() {
    form.confirmed_collection_date = props.request?.requested_date ?? '';
    form.confirmed_time_from = props.request?.time_from ?? '';
    form.confirmed_time_to = props.request?.time_to ?? '';
}

function save() {
    form.patch(route('admin.orders.appraisal.schedule', props.orderId), { preserveScroll: true });
}

const fieldClass =
    'h-10 w-full rounded-[11px] border border-[#e9efee] bg-white px-3 text-[13px] text-[#10393b] outline-none transition-colors focus:border-[#01B990]';
const labelClass = 'mb-1 block text-[11.5px] font-bold text-[#6f8585]';
const errorClass = 'mt-1 text-[11.5px] font-medium text-[#b03b28]';
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-[17px] font-extrabold tracking-[-0.3px] text-[#10393b]">Termin &amp; Prüfstelle</h2>
                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">
                    {{ isScheduled ? 'Der Termin ist bestätigt.' : 'Datum und Zeitfenster speichern — damit ist das Gutachten terminiert.' }}
                </p>
            </div>

            <span
                class="shrink-0 rounded-full px-2.5 py-1 text-[10.5px] font-bold"
                :class="isScheduled ? 'bg-[#01B990]/10 text-[#00856a]' : 'bg-[#ef8450]/10 text-[#c0622e]'"
            >
                {{ isScheduled ? 'Terminiert' : 'Offen' }}
            </span>
        </div>

        <div v-if="requestedText" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[13px] bg-[#f6f9f8] px-3 py-2.5">
            <p class="text-[12.5px] text-[#10393b]">
                <span class="font-medium text-[#9bb0af]">Wunschtermin des Kunden:</span>
                <span class="font-bold"> {{ requestedText }}</span>
            </p>
            <button
                v-if="editable"
                type="button"
                class="rounded-full border border-[#01B990] px-3 py-1 text-[11.5px] font-bold text-[#00856a] transition-all hover:bg-[#01B990] hover:text-white"
                @click="adoptRequest"
            >
                Wunschtermin übernehmen
            </button>
        </div>

        <form v-if="editable" class="flex flex-col gap-3" @submit.prevent="save">
            <div class="grid grid-cols-3 gap-3 max-[640px]:grid-cols-1">
                <div>
                    <label :class="labelClass" for="appraisal-date">Datum</label>
                    <input id="appraisal-date" v-model="form.confirmed_collection_date" type="date" :class="fieldClass" />
                    <p v-if="form.errors.confirmed_collection_date" :class="errorClass">{{ form.errors.confirmed_collection_date }}</p>
                </div>
                <div>
                    <label :class="labelClass" for="appraisal-from">Von</label>
                    <input id="appraisal-from" v-model="form.confirmed_time_from" type="time" :class="fieldClass" />
                    <p v-if="form.errors.confirmed_time_from" :class="errorClass">{{ form.errors.confirmed_time_from }}</p>
                </div>
                <div>
                    <label :class="labelClass" for="appraisal-to">Bis</label>
                    <input id="appraisal-to" v-model="form.confirmed_time_to" type="time" :class="fieldClass" />
                    <p v-if="form.errors.confirmed_time_to" :class="errorClass">{{ form.errors.confirmed_time_to }}</p>
                </div>
            </div>

            <div>
                <label :class="labelClass" for="appraisal-site">Prüfstelle (z. B. DEKRA Köln)</label>
                <input id="appraisal-site" v-model="form.inspection_site_name" type="text" maxlength="255" :class="fieldClass" />
                <p v-if="form.errors.inspection_site_name" :class="errorClass">{{ form.errors.inspection_site_name }}</p>
            </div>

            <div>
                <label :class="labelClass" for="appraisal-site-address">Adresse der Prüfstelle</label>
                <input id="appraisal-site-address" v-model="form.inspection_site_address" type="text" maxlength="500" :class="fieldClass" />
                <p v-if="form.errors.inspection_site_address" :class="errorClass">{{ form.errors.inspection_site_address }}</p>
            </div>

            <div class="rounded-[13px] border border-[#eef3f2] px-3 py-2.5">
                <p class="text-[12.5px] text-[#5a6e6c]">{{ transportText }}</p>
                <label v-if="request?.pickup_requested" class="mt-2 flex items-center gap-2 text-[12.5px] font-bold text-[#10393b]">
                    <input v-model="form.transport_confirmed" type="checkbox" class="size-4 accent-[#01B990]" />
                    Transport ist organisiert und bestätigt
                </label>
            </div>

            <div>
                <label :class="labelClass" for="appraisal-note">Interne Notiz (für den Kunden nicht sichtbar)</label>
                <textarea
                    id="appraisal-note"
                    v-model="form.internal_note"
                    rows="2"
                    maxlength="2000"
                    class="w-full resize-none rounded-[11px] border border-[#e9efee] px-3 py-2 text-[13px] text-[#10393b] outline-none focus:border-[#01B990]"
                />
                <p v-if="form.errors.internal_note" :class="errorClass">{{ form.errors.internal_note }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start rounded-[13px] bg-[#10393b] px-5 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
            >
                {{ form.processing ? 'Wird gespeichert…' : isScheduled ? 'Änderungen speichern' : 'Termin bestätigen' }}
            </button>
        </form>

        <template v-else>
            <p v-if="!savedRows.length" class="py-4 text-[13px] text-[#9bb0af]">Es wurde kein Termin gespeichert.</p>
            <dl v-else class="flex flex-col">
                <div
                    v-for="row in savedRows"
                    :key="row.label"
                    class="flex items-start justify-between gap-3 border-b border-[#f2f6f5] py-2 last:border-0"
                >
                    <dt class="shrink-0 text-[12px] font-medium text-[#9bb0af]">{{ row.label }}</dt>
                    <dd class="text-right text-[12.5px] font-bold text-[#10393b]">{{ row.value }}</dd>
                </div>
            </dl>
        </template>
    </div>
</template>
