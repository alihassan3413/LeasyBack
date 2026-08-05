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
import type { VehicleCollectionAddress, VehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, useId, watch } from 'vue';

const props = defineProps<{
    open: boolean;
    vehicleId: string;
    stations: StationData[];
    vehicle?: VehicleData | null;
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

const isB2bOrder = computed(() => props.vehicle?.vehicle_belongs === 'B2B');

type CollectionAddressForm = Record<keyof VehicleCollectionAddress, string>;

function vehicleCollectionAddress(): CollectionAddressForm {
    const address = props.vehicle?.collection_address ?? null;

    return {
        street: address?.street ?? '',
        number: address?.number ?? '',
        additional_address: address?.additional_address ?? '',
        zip_code: address?.zip_code ?? '',
        city: address?.city ?? '',
        country: address?.country ?? '',
    };
}

// Function form on purpose — see CreateOfferModal: an object literal would
// make reset() restore the previously booked appointment instead of clearing.
const form = useForm(() => ({
    station_id: '',
    date: '',
    time: '',
    remarks: '',
    fee_acknowledged: false,
    requested_collection_date: '',
    collection_note: '',
    collection_address: vehicleCollectionAddress(),
}));

const selectedStation = computed(() => props.stations.find((station) => station.station_id === form.station_id) ?? null);

const canSubmit = computed(() => {
    if (!form.fee_acknowledged) {
        return false;
    }

    if (isB2bOrder.value) {
        return (
            form.requested_collection_date !== '' &&
            form.collection_address.street !== '' &&
            form.collection_address.zip_code !== '' &&
            form.collection_address.city !== ''
        );
    }

    return form.station_id !== '' && form.date !== '' && form.time !== '';
});

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
        form.collection_address = vehicleCollectionAddress();
        selectedBundesland.value = '';
        selectedOrt.value = '';
    },
);

function close() {
    emit('update:open', false);
}

function submit() {
    form.transform((data) =>
        isB2bOrder.value
            ? {
                  requested_collection_date: data.requested_collection_date,
                  collection_note: data.collection_note || null,
                  collection_address: data.collection_address,
              }
            : {
                  station_id: data.station_id,
                  termin: `${data.date}T${data.time}:00`,
                  remarks: data.remarks || null,
              },
    ).post(route('orders.store', props.vehicleId), {
        preserveScroll: true,
        onSuccess: close,
    });
}
</script>

<template>
    <AppModal
        :open="open"
        :title="isB2bOrder ? 'Abholung beauftragen' : 'Auftrag erstellen'"
        :description="
            isB2bOrder
                ? 'Bitte geben Sie Wunschtermin und Abholadresse an. Ihre Anfrage wird von Leasyback geprüft.'
                : 'Bitte füllen Sie alle Details im unten stehenden Formular aus.'
        "
        :width="isB2bOrder ? 720 : 920"
        @update:open="(value) => emit('update:open', value)"
    >
        <form class="px-2" @submit.prevent="submit">
            <InputError class="mb-3" :message="form.errors.appointment" />

            <div v-if="!isB2bOrder" class="grid grid-cols-1 gap-6 md:grid-cols-2 md:items-stretch">
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

            <template v-if="isB2bOrder">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-semibold text-black">Abholung</span>
                    <span class="h-px flex-1 bg-gray-200"></span>
                </div>

                <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Wunschtermin Abholung</label>
                        <CalendarDateField v-model="form.requested_collection_date" :invalid="!!form.errors.requested_collection_date" />
                        <InputError :message="form.errors.requested_collection_date" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">
                            Hinweis zur Abholung
                            <span class="ml-1 text-xs font-normal text-gray-400">(optional)</span>
                        </label>
                        <Input v-model="form.collection_note" placeholder="z. B. Schlüsselübergabe am Empfang" />
                        <InputError :message="form.errors.collection_note" />
                    </div>
                </div>

                <p class="mt-3 text-xs text-gray-400">
                    Vorbelegt mit der Abholadresse des Fahrzeugs. Änderungen gelten nur für diesen Auftrag. Es wird kein Prüfstationstermin benötigt
                    — Leasyback holt das Fahrzeug bei Ihnen ab.
                </p>

                <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Straße</label>
                        <Input v-model="form.collection_address.street" />
                        <InputError :message="form.errors['collection_address.street']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Hausnummer</label>
                        <Input v-model="form.collection_address.number" />
                        <InputError :message="form.errors['collection_address.number']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Adresszusatz</label>
                        <Input v-model="form.collection_address.additional_address" />
                        <InputError :message="form.errors['collection_address.additional_address']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">PLZ</label>
                        <Input v-model="form.collection_address.zip_code" />
                        <InputError :message="form.errors['collection_address.zip_code']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Ort</label>
                        <Input v-model="form.collection_address.city" />
                        <InputError :message="form.errors['collection_address.city']" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-semibold text-black">Land</label>
                        <Input v-model="form.collection_address.country" />
                        <InputError :message="form.errors['collection_address.country']" />
                    </div>
                </div>
            </template>

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
