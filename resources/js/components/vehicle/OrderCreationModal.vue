<script setup lang="ts">
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import SearchableSelectField, { type SearchableOption } from '@/components/form/SearchableSelectField.vue';
import StationMap from '@/components/form/StationMap.vue';
import StationSelectField from '@/components/form/StationSelectField.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { StationData } from '@/types/order';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicleId: string;
    stations: StationData[];
}>();

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const uid = useId();
const feeAcknowledgedId = `${uid}-fee-acknowledged`;

const selectedBundesland = ref('');
const selectedOrt = ref('');

const ALL = '__all__';

const bundeslandOptions = computed<SearchableOption[]>(() => [
    { value: ALL, label: 'Alle Bundesländer' },
    ...[...new Set(props.stations.map((station) => station.bundesland).filter((value): value is string => !!value))]
        .sort((a, b) => a.localeCompare(b, 'de'))
        .map((value) => ({ value, label: value })),
]);

const ortOptions = computed<SearchableOption[]>(() => [
    { value: ALL, label: 'Alle Orte' },
    ...[
        ...new Set(
            props.stations
                .filter((station) => !selectedBundesland.value || station.bundesland === selectedBundesland.value)
                .map((station) => station.ort)
                .filter(Boolean),
        ),
    ]
        .sort((a, b) => a.localeCompare(b, 'de'))
        .map((value) => ({ value, label: value })),
]);

function setBundesland(value: string) {
    selectedBundesland.value = value === ALL ? '' : value;
}

function setOrt(value: string) {
    selectedOrt.value = value === ALL ? '' : value;
}

const filteredStations = computed(() =>
    props.stations.filter(
        (station) =>
            (!selectedBundesland.value || station.bundesland === selectedBundesland.value) &&
            (!selectedOrt.value || station.ort === selectedOrt.value),
    ),
);

// Function form on purpose — see CreateOfferModal: an object literal would
// make reset() restore the previously booked appointment instead of clearing.
const form = useForm(() => ({
    station_id: '',
    date: '',
    time: '',
    remarks: '',
    fee_acknowledged: false,
}));

const selectedStation = computed(() => props.stations.find((station) => station.station_id === form.station_id) ?? null);

const canSubmit = computed(() => form.station_id !== '' && form.date !== '' && form.time !== '' && form.fee_acknowledged);

watch(selectedBundesland, () => {
    selectedOrt.value = '';
});

watch([selectedBundesland, selectedOrt], () => {
    if (form.station_id && !filteredStations.value.some((station) => station.station_id === form.station_id)) {
        form.station_id = '';
    }
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }
        form.reset();
        form.clearErrors();
        selectedBundesland.value = '';
        selectedOrt.value = '';
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.transform((data) => ({
        station_id: data.station_id,
        termin: `${data.date}T${data.time}:00`,
        remarks: data.remarks || null,
    })).post(route('orders.store', props.vehicleId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        title="Auftrag erstellen"
        description="Bitte füllen Sie alle Details im unten stehenden Formular aus."
        :width="920"
        @update:open="(value) => emit('update:open', value)"
    >
        <form class="px-2" @submit.prevent="submit">
            <InputError class="mb-3" :message="form.errors.appointment" />

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 md:items-stretch">
                <div class="flex h-full flex-col gap-3">
                    <div class="grid grid-cols-2 gap-x-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-semibold text-black">Bundesland</label>
                            <SearchableSelectField
                                :model-value="selectedBundesland"
                                :options="bundeslandOptions"
                                placeholder="Bundesland wählen"
                                search-placeholder="Bundesland suchen..."
                                @update:model-value="setBundesland"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-semibold text-black">Ort</label>
                            <SearchableSelectField
                                :model-value="selectedOrt"
                                :options="ortOptions"
                                placeholder="Ort wählen"
                                search-placeholder="Ort suchen..."
                                @update:model-value="setOrt"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Station</label>
                        <StationSelectField v-model="form.station_id" :stations="filteredStations" :invalid="!!form.errors.station_id" />
                        <InputError :message="form.errors.station_id" />
                    </div>

                    <div class="grid grid-cols-2 gap-x-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-semibold text-black">Datum</label>
                            <CalendarDateField v-model="form.date" :min-days-ahead="3" block-weekends :invalid="!!form.errors.termin" />
                            <InputError :message="form.errors.termin" />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-sm font-semibold text-black">Uhrzeit</label>
                            <Input v-model="form.time" type="time" />
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-1">
                        <label class="text-sm font-semibold text-black">
                            Bemerkungen
                            <span class="ml-1 text-xs font-normal text-gray-400">(optional)</span>
                        </label>
                        <div
                            class="relative flex min-h-[110px] flex-1 items-start rounded-[28px] border border-gray-300 px-4 py-3 focus-within:border-emerald-500"
                        >
                            <textarea
                                v-model="form.remarks"
                                class="h-full w-full resize-none bg-transparent text-sm outline-none"
                                placeholder="Bemerkungen hinzufügen..."
                            />
                        </div>
                        <InputError :message="form.errors.remarks" />
                    </div>
                </div>

                <div class="aspect-square w-full md:aspect-auto md:h-[430px]">
                    <StationMap :station="selectedStation" />
                </div>
            </div>

            <div class="mt-4 rounded-2xl bg-gray-50 p-4 text-sm">
                <p class="text-[#00000080]">
                    Mit dem Buchen eines Termins starten Sie den Leasyback-Prozess. Wird der Prozess nicht abgeschlossen, kann eine Bearbeitungsgebühr
                    anfallen.
                </p>
                <Label :for="feeAcknowledgedId" class="mt-3 flex cursor-pointer items-start gap-2 font-normal text-black">
                    <Checkbox
                        :id="feeAcknowledgedId"
                        v-model="form.fee_acknowledged"
                        class="mt-0.5 size-4 shrink-0 rounded-[4px] border-gray-300 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                    />
                    <span class="text-xs leading-[1.45] text-[#00000099]">
                        Ich habe die Information gelesen und akzeptiere die Bearbeitungsgebühr bei Nichtabschluss des Prozesses.
                    </span>
                </Label>
            </div>
        </form>

        <template #footer>
            <AppModalButton :disabled="!canSubmit || form.processing" @click="submit">
                {{ form.processing ? 'Lädt...' : 'Bestätigen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>
