<script setup lang="ts">
/**
 * B2B collection appointment: the customer's requested date and address as
 * submitted, plus Admin's confirmation. Requested and confirmed dates stay
 * separate — confirming copies the requested date into the confirmed field
 * rather than overwriting it, so the original wish is always visible.
 *
 * Rendered only for B2B orders; the server refuses the endpoint for a B2C
 * order regardless of what this card offers.
 *
 * Editable only until the vehicle is collected (`editable`) — afterwards the
 * card stays as the record of what was agreed, read-only. A new confirmed date
 * cannot lie in the past; an unchanged existing one is left alone.
 *
 * With `relocation` set (an Überführung) the card becomes the appointment
 * entry of the traffic-light spec: date *and* a full time window (von/bis,
 * at least two hours). Saving both schedules the relocation. The customer's
 * wish comes from the booking itself, and the addresses live in the
 * Überführung card, so the address fields are left out.
 */
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { formatPortalDate } from '@/lib/portalDate';
import type { OrderCollectionData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import MdiTruckOutline from '~icons/mdi/truck-outline';

const props = defineProps<{
    orderId: string;
    collection: OrderCollectionData | null;
    editable: boolean;
    /** Set for an Überführung: the customer's wish from the booking. */
    relocation?: { requested_date: string | null; requested_time_slot: string | null } | null;
    /** Set for an Unfallschaden: the card records what was arranged, and when. */
    accident?: boolean;
}>();

const isRelocation = computed(() => !!props.relocation);
const isAccident = computed(() => !!props.accident);

/** Mirrors OrderCollectionService::ACCIDENT_ARRANGEMENTS. */
const ARRANGEMENTS = [
    { value: 'inspection', label: 'Begutachtung vor Ort' },
    { value: 'vehicle_access', label: 'Fahrzeugzugang' },
    { value: 'collection', label: 'Abholung' },
];

/* ── Time window (Überführung): 06:00–20:00 in half hours, at least 2 hours ── */
const MIN_WINDOW_MINUTES = 120;

const TIMES = Array.from({ length: 29 }, (_, index) => {
    const minutes = 6 * 60 + index * 30;

    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});

function toMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

const lastTime = toMinutes(TIMES[TIMES.length - 1]);
const startTimes = TIMES.filter((time) => toMinutes(time) + MIN_WINDOW_MINUTES <= lastTime);

/** "08:00-12:00" → ['08:00', '12:00'], or empty strings when it is not a window. */
function splitSlot(slot: string | null | undefined): [string, string] {
    const match = /^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/.exec((slot ?? '').trim());

    return match ? [match[1], match[2]] : ['', ''];
}

const [initialFrom, initialTo] = splitSlot(props.collection?.confirmed_collection_time_slot);

const form = useForm(() => ({
    confirmed_collection_date: props.collection?.confirmed_collection_date ?? '',
    confirmed_time_from: initialFrom,
    confirmed_time_to: initialTo,
    confirmed_arrangement: props.collection?.confirmed_arrangement ?? '',
    internal_note: props.collection?.internal_note ?? '',
    collection_address: {
        street: props.collection?.collection_address?.street ?? '',
        number: props.collection?.collection_address?.number ?? '',
        additional_address: props.collection?.collection_address?.additional_address ?? '',
        zip_code: props.collection?.collection_address?.zip_code ?? '',
        city: props.collection?.collection_address?.city ?? '',
        country: props.collection?.collection_address?.country ?? '',
    },
}));

const endTimes = computed(() =>
    form.confirmed_time_from ? TIMES.filter((time) => toMinutes(time) >= toMinutes(form.confirmed_time_from) + MIN_WINDOW_MINUTES) : [],
);

// A start change can make the chosen end too early — clear it rather than send an invalid window.
watch(
    () => form.confirmed_time_from,
    () => {
        if (form.confirmed_time_to && !endTimes.value.includes(form.confirmed_time_to)) {
            form.confirmed_time_to = '';
        }
    },
);

const requestedDate = computed(() =>
    isRelocation.value ? (props.relocation?.requested_date ?? null) : (props.collection?.requested_collection_date ?? null),
);

const requestedSlot = computed(() =>
    isRelocation.value ? (props.relocation?.requested_time_slot ?? null) : (props.collection?.requested_collection_time_slot ?? null),
);

const canAdoptRequested = computed(() => {
    if (requestedDate.value === null) {
        return false;
    }

    if (!isRelocation.value) {
        return form.confirmed_collection_date !== requestedDate.value;
    }

    const [from, to] = splitSlot(requestedSlot.value);

    return form.confirmed_collection_date !== requestedDate.value || form.confirmed_time_from !== from || form.confirmed_time_to !== to;
});

/** An Überführung is only scheduled by date plus a complete window; an Unfallschaden by date plus what was arranged. */
const relocationIncomplete = computed(
    () =>
        (isRelocation.value && (form.confirmed_collection_date === '' || form.confirmed_time_from === '' || form.confirmed_time_to === '')) ||
        (isAccident.value && (form.confirmed_collection_date === '' || form.confirmed_arrangement === '')),
);

function formatDate(value: string | null): string {
    return formatPortalDate(value) || '—';
}

function adoptRequested() {
    form.confirmed_collection_date = requestedDate.value ?? '';

    if (isRelocation.value) {
        const [from, to] = splitSlot(requestedSlot.value);

        // The customer's window only when it satisfies the 2-hour rule here.
        if (from && startTimes.includes(from)) {
            form.confirmed_time_from = from;
            form.confirmed_time_to = to && toMinutes(to) >= toMinutes(from) + MIN_WINDOW_MINUTES ? to : '';
        }
    }
}

function submit() {
    if (!props.editable || relocationIncomplete.value) {
        return;
    }

    form.transform((data) => {
        if (isAccident.value) {
            // The Unfallschaden's locations live in its report, not in this card.
            const { collection_address: _address, confirmed_time_from: _from, confirmed_time_to: _to, ...rest } = data;

            return rest;
        }

        if (!isRelocation.value) {
            // Unchanged for every other order: the time window and arrangement are not sent.
            const { confirmed_time_from: _from, confirmed_time_to: _to, confirmed_arrangement: _arrangement, ...rest } = data;

            return rest;
        }

        // The Überführung's addresses live in its booking, not in this card.
        const { collection_address: _address, confirmed_arrangement: _arrangement, ...rest } = data;

        return rest;
    }).patch(route('admin.orders.collection', props.orderId), { preserveScroll: true });
}

const selectClass = 'h-9 w-full rounded-md border border-[#e9efee] bg-transparent px-3 text-[12.5px] outline-none focus:border-[#01b990]';
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiTruckOutline class="size-[17px]" />
            </span>
            <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">
                {{ isAccident ? 'Nächster Schritt' : isRelocation ? 'Überführungstermin' : 'Abholung' }}
            </h2>
        </div>

        <p v-if="isAccident" class="mb-4 text-[12px] text-[#6f8585]">
            Vereinbaren Sie Begutachtung, Fahrzeugzugang oder Abholung. Mit Art und Datum ist der Auftrag terminiert.
        </p>

        <dl v-if="!isAccident" class="mb-4 flex flex-col">
            <div class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Wunschtermin Kunde</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ formatDate(requestedDate) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 border-b border-[#f2f6f5] py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Zeitraum</dt>
                <dd class="text-[12.5px] font-bold text-[#10393b]">{{ requestedSlot || '—' }}</dd>
            </div>
            <div v-if="!isRelocation" class="flex items-center justify-between gap-3 py-2">
                <dt class="text-[12px] font-medium text-[#9bb0af]">Hinweis Kunde</dt>
                <dd class="text-right text-[12.5px] font-bold text-[#10393b]">{{ collection?.collection_note || '—' }}</dd>
            </div>
        </dl>

        <p v-if="!editable" class="mb-3 rounded-[11px] bg-[#f6f9f8] px-3 py-2 text-[11.5px] text-[#6f8585]">
            {{
                isRelocation
                    ? 'Der Überführungstermin kann in diesem Auftragsstatus nicht mehr geändert werden.'
                    : 'Die Abholung kann in diesem Auftragsstatus nicht mehr geändert werden — sie ist nur bis zur Abholung des Fahrzeugs bearbeitbar.'
            }}
        </p>

        <form class="flex flex-col gap-3" @submit.prevent="submit">
            <fieldset :disabled="!editable" class="flex min-w-0 flex-col gap-3">
                <!-- Unfallschaden: what was arranged, required together with the date. -->
                <div v-if="isAccident" class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Vereinbart</label>
                    <select v-model="form.confirmed_arrangement" :class="selectClass">
                        <option value="">Bitte wählen</option>
                        <option v-for="option in ARRANGEMENTS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <InputError :message="(form.errors as Record<string, string>).confirmed_arrangement" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">{{ isRelocation || isAccident ? 'Termin (Datum)' : 'Bestätigter Abholtermin' }}</label>
                    <CalendarDateField
                        v-model="form.confirmed_collection_date"
                        :disabled="!editable"
                        :invalid="!!form.errors.confirmed_collection_date"
                    />
                    <InputError :message="form.errors.confirmed_collection_date" />
                    <button
                        v-if="editable && canAdoptRequested && !isAccident"
                        type="button"
                        class="self-start text-[11.5px] font-bold text-[#00856a] hover:opacity-70"
                        @click="adoptRequested"
                    >
                        Wunschtermin übernehmen
                    </button>
                </div>

                <!-- Überführung: the full time window, required together with the date. -->
                <div v-if="isRelocation" class="grid grid-cols-2 gap-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Zeitfenster von</label>
                        <select v-model="form.confirmed_time_from" :class="selectClass">
                            <option value="">Startzeit wählen</option>
                            <option v-for="time in startTimes" :key="time" :value="time">{{ time }}</option>
                        </select>
                        <InputError :message="form.errors.confirmed_time_from" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Zeitfenster bis</label>
                        <select v-model="form.confirmed_time_to" :class="selectClass" :disabled="!form.confirmed_time_from">
                            <option value="">Endzeit wählen</option>
                            <option v-for="time in endTimes" :key="time" :value="time">{{ time }}</option>
                        </select>
                        <InputError :message="form.errors.confirmed_time_to" />
                    </div>

                    <p class="col-span-2 text-[11px] text-[#9bb0af]">
                        Mindestens 2 Stunden. Mit Datum und Zeitfenster ist die Überführung terminiert.
                    </p>
                </div>

                <div v-if="!isRelocation && !isAccident" class="grid grid-cols-2 gap-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Straße</label>
                        <Input v-model="form.collection_address.street" />
                        <InputError :message="form.errors['collection_address.street']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Hausnummer</label>
                        <Input v-model="form.collection_address.number" />
                        <InputError :message="form.errors['collection_address.number']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Adresszusatz</label>
                        <Input v-model="form.collection_address.additional_address" />
                        <InputError :message="form.errors['collection_address.additional_address']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">PLZ</label>
                        <Input v-model="form.collection_address.zip_code" />
                        <InputError :message="form.errors['collection_address.zip_code']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Ort</label>
                        <Input v-model="form.collection_address.city" />
                        <InputError :message="form.errors['collection_address.city']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-[12px] font-bold text-[#10393b]">Land</label>
                        <Input v-model="form.collection_address.country" />
                        <InputError :message="form.errors['collection_address.country']" />
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-[12px] font-bold text-[#10393b]">Interne Notiz</label>
                    <textarea
                        v-model="form.internal_note"
                        rows="3"
                        class="w-full resize-none rounded-[13px] border border-[#e9efee] px-3 py-2 text-[12.5px] outline-none focus:border-[#01b990]"
                        placeholder="Nur für Leasyback sichtbar..."
                    />
                    <InputError :message="form.errors.internal_note" />
                </div>

                <button
                    v-if="editable"
                    type="submit"
                    :disabled="form.processing || relocationIncomplete"
                    class="self-end rounded-[13px] bg-[#10393b] px-4 py-2.5 text-[13px] font-bold text-white transition-all hover:opacity-90 disabled:opacity-50"
                >
                    {{ form.processing ? 'Speichert...' : isRelocation || isAccident ? 'Termin speichern' : 'Abholung speichern' }}
                </button>
            </fieldset>
        </form>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>