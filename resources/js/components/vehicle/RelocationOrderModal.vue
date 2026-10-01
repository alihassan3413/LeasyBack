<script setup lang="ts">
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { BookableVehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

/**
 * The Überführung booking form. One submission books every selected vehicle
 * with the same addresses, time window, contacts, billing address and cost
 * centre — the server creates one order per vehicle.
 */
interface SavedAddressProfile {
    id: string;
    profile_name: string;
    details: {
        street?: string | null;
        number?: string | null;
        zip_code?: string | null;
        city?: string | null;
        country?: string | null;
    } | null;
}

const props = withDefaults(
    defineProps<{
        open: boolean;
        vehicles: BookableVehicleData[];
        addressProfiles?: SavedAddressProfile[];
        costCentres?: string[];
    }>(),
    { addressProfiles: () => [], costCentres: () => [] },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

/* ── Time window: 06:00–20:00 in half hours, at least 2 hours long ── */
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

const emptyAddress = () => ({ street: '', number: '', zip_code: '', city: '', country: 'Deutschland' });
const emptyContact = () => ({ name: '', phone: '', email: '' });

const form = useForm({
    vehicle_ids: [] as string[],
    pickup_address: emptyAddress(),
    destination_address: emptyAddress(),
    preferred_date: '',
    time_from: '',
    time_to: '',
    pickup_contact: emptyContact(),
    destination_contact: emptyContact(),
    billing_address: { name: '', ...emptyAddress() },
    cost_centre: { name: '', number: '' },
    vehicle_ready: false,
    notes: '',
});

const endTimes = computed(() => (form.time_from ? TIMES.filter((time) => toMinutes(time) >= toMinutes(form.time_from) + MIN_WINDOW_MINUTES) : []));

// A start change can make the chosen end too early — clear it rather than send an invalid window.
watch(
    () => form.time_from,
    () => {
        if (form.time_to && !endTimes.value.includes(form.time_to)) {
            form.time_to = '';
        }
    },
);

// `immediate`: the dashboard mounts this modal already open, so a plain
// watcher would never see the change to `true` and the form would never be
// initialised for the first booking.
watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.vehicle_ids = props.vehicles.map((vehicle) => vehicle.vehicle_id);
        }
    },
    { immediate: true },
);

const filled = (value: string) => value.trim() !== '';

const billingStarted = computed(() =>
    [form.billing_address.name, form.billing_address.street, form.billing_address.zip_code, form.billing_address.city].some(filled),
);

const billingComplete = computed(
    () =>
        !billingStarted.value || (filled(form.billing_address.street) && filled(form.billing_address.zip_code) && filled(form.billing_address.city)),
);

/** What still blocks the submit button, in the order the form asks for it. */
const missingFields = computed(() =>
    [
        props.vehicles.length === 0 ? 'Fahrzeug' : '',
        filled(form.pickup_address.street) ? '' : 'Abholadresse: Straße',
        filled(form.pickup_address.zip_code) ? '' : 'Abholadresse: PLZ',
        filled(form.pickup_address.city) ? '' : 'Abholadresse: Ort',
        filled(form.destination_address.street) ? '' : 'Zieladresse: Straße',
        filled(form.destination_address.zip_code) ? '' : 'Zieladresse: PLZ',
        filled(form.destination_address.city) ? '' : 'Zieladresse: Ort',
        filled(form.preferred_date) ? '' : 'Datum',
        filled(form.time_from) ? '' : 'Zeitfenster von',
        filled(form.time_to) ? '' : 'Zeitfenster bis',
        billingComplete.value ? '' : 'Rechnungsadresse vollständig',
    ].filter(Boolean),
);

const canSubmit = computed(() => missingFields.value.length === 0);

/** Nested validation errors arrive as "pickup_address.street" etc. */
function error(key: string): string | undefined {
    return (form.errors as Record<string, string>)[key];
}

type AddressTarget = 'pickup_address' | 'destination_address' | 'billing_address';

function applyProfile(target: AddressTarget, profileId: string) {
    const profile = props.addressProfiles.find((candidate) => candidate.id === profileId);
    const details = profile?.details;

    if (!profile || !details) {
        return;
    }

    Object.assign(form[target], {
        street: details.street ?? '',
        number: details.number ?? '',
        zip_code: details.zip_code ?? '',
        city: details.city ?? '',
        country: details.country || 'Deutschland',
    });

    if (target === 'billing_address') {
        form.billing_address.name = profile.profile_name;
    }
}

/**
 * The chosen profile per address block. Kept separate from the form: picking
 * one copies its fields in, after which the address is edited freely and the
 * dropdown is only a record of where it came from.
 */
const chosenProfile = reactive<Record<AddressTarget, string>>({
    pickup_address: '',
    destination_address: '',
    billing_address: '',
});

const profileOptions = computed<SelectFieldOption[]>(() =>
    props.addressProfiles.map((profile) => ({ label: profile.profile_name, value: profile.id })),
);

const costCentreOptions = computed<SelectFieldOption[]>(() => props.costCentres.map((name) => ({ label: name, value: name })));

const startTimeOptions = computed<SelectFieldOption[]>(() => startTimes.map((time) => ({ label: time, value: time })));

const endTimeOptions = computed<SelectFieldOption[]>(() => endTimes.value.map((time) => ({ label: time, value: time })));

function onProfileSelected(target: AddressTarget, profileId: string) {
    applyProfile(target, profileId);
}

const chosenCostCentre = ref('');

function onCostCentreSelected(value: string) {
    if (value) {
        form.cost_centre.name = value;
    }
}

const submitLabel = computed(() => {
    if (form.processing) {
        return 'Wird gesendet …';
    }

    return form.vehicle_ids.length > 1 ? `${form.vehicle_ids.length} Überführungen beauftragen` : 'Auftrag erstellen';
});

function close() {
    emit('update:open', false);
}

function submit() {
    if (!canSubmit.value || form.processing) {
        return;
    }

    form.transform((data) => ({
        ...data,
        // Always the vehicles picked in the step before.
        vehicle_ids: props.vehicles.map((vehicle) => vehicle.vehicle_id),
        // Optional blocks travel as null when left empty.
        billing_address: billingStarted.value ? data.billing_address : null,
        cost_centre: filled(data.cost_centre.name) || filled(data.cost_centre.number) ? data.cost_centre : null,
    })).post('/orders/b2b/relocation', {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

const sectionTitle = 'text-[15px] font-semibold text-black';
const hint = 'text-muted-foreground text-xs';
const errorClass = 'mt-1 text-xs text-red-600';
</script>

<template>
    <AppModal
        :open="open"
        title="Überführung beauftragen"
        description="Fahrzeug von A nach B überführen lassen."
        :width="760"
        @update:open="(value) => emit('update:open', value)"
    >
        <form class="max-h-[70vh] space-y-6 overflow-y-auto px-2 pb-2" @submit.prevent="submit">
            <!-- Fahrzeuge -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">{{ vehicles.length > 1 ? `Fahrzeuge (${vehicles.length})` : 'Fahrzeug' }}</h3>
                <ul class="flex flex-wrap gap-2">
                    <li v-for="vehicle in vehicles" :key="vehicle.vehicle_id" class="border-border rounded-full border px-3 py-1 text-xs">
                        <span class="text-brand-teal font-semibold">{{ vehicle.license_plate }}</span>
                        <span class="text-muted-foreground"> · {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}</span>
                    </li>
                </ul>
                <p v-if="error('vehicle_ids')" :class="errorClass">{{ error('vehicle_ids') }}</p>
            </section>

            <!-- Abholadresse -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Abholadresse</h3>
                <p :class="hint">Wo soll das Fahrzeug abgeholt werden?</p>
                <SelectField
                    v-if="addressProfiles.length"
                    v-model="chosenProfile.pickup_address"
                    :options="profileOptions"
                    placeholder="Gespeicherte Adresse wählen …"
                    @update:model-value="(id) => onProfileSelected('pickup_address', id)"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Input v-model="form.pickup_address.street" placeholder="Straße *" />
                        <p v-if="error('pickup_address.street')" :class="errorClass">{{ error('pickup_address.street') }}</p>
                    </div>
                    <Input v-model="form.pickup_address.number" placeholder="Hausnummer" />
                    <div>
                        <Input v-model="form.pickup_address.zip_code" placeholder="PLZ *" />
                        <p v-if="error('pickup_address.zip_code')" :class="errorClass">{{ error('pickup_address.zip_code') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.pickup_address.city" placeholder="Ort *" />
                        <p v-if="error('pickup_address.city')" :class="errorClass">{{ error('pickup_address.city') }}</p>
                    </div>
                    <Input v-model="form.pickup_address.country" placeholder="Land" class="col-span-2" />
                </div>
            </section>

            <!-- Zieladresse -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Zieladresse</h3>
                <p :class="hint">Wohin soll das Fahrzeug gebracht werden?</p>
                <SelectField
                    v-if="addressProfiles.length"
                    v-model="chosenProfile.destination_address"
                    :options="profileOptions"
                    placeholder="Gespeicherte Adresse wählen …"
                    @update:model-value="(id) => onProfileSelected('destination_address', id)"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Input v-model="form.destination_address.street" placeholder="Straße *" />
                        <p v-if="error('destination_address.street')" :class="errorClass">{{ error('destination_address.street') }}</p>
                    </div>
                    <Input v-model="form.destination_address.number" placeholder="Hausnummer" />
                    <div>
                        <Input v-model="form.destination_address.zip_code" placeholder="PLZ *" />
                        <p v-if="error('destination_address.zip_code')" :class="errorClass">{{ error('destination_address.zip_code') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.destination_address.city" placeholder="Ort *" />
                        <p v-if="error('destination_address.city')" :class="errorClass">{{ error('destination_address.city') }}</p>
                    </div>
                    <Input v-model="form.destination_address.country" placeholder="Land" class="col-span-2" />
                </div>
            </section>

            <!-- Wunschtermin -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Wunschtermin</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium">Datum *</label>
                        <CalendarDateField v-model="form.preferred_date" />
                        <p v-if="error('preferred_date')" :class="errorClass">{{ error('preferred_date') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Zeitfenster von *</label>
                        <SelectField v-model="form.time_from" :options="startTimeOptions" placeholder="Startzeit wählen" />
                        <p v-if="error('time_from')" :class="errorClass">{{ error('time_from') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium">Zeitfenster bis *</label>
                        <SelectField v-model="form.time_to" :options="endTimeOptions" placeholder="Endzeit wählen" :disabled="!form.time_from" />
                        <p v-if="error('time_to')" :class="errorClass">{{ error('time_to') }}</p>
                    </div>
                </div>
                <p :class="hint">
                    Mindestens 2 Stunden nach Startzeit. Wir bestätigen die Verfügbarkeit schnellstmöglich oder schlagen Alternativen vor.
                </p>
            </section>

            <!-- Ansprechpartner -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Ansprechpartner Abholung</h3>
                <p :class="hint">Wer ist vor Ort für Rückfragen erreichbar?</p>
                <div class="grid grid-cols-3 gap-3">
                    <Input v-model="form.pickup_contact.name" placeholder="Name" />
                    <Input v-model="form.pickup_contact.phone" placeholder="Telefon" />
                    <div>
                        <Input v-model="form.pickup_contact.email" type="email" placeholder="E-Mail" />
                        <p v-if="error('pickup_contact.email')" :class="errorClass">{{ error('pickup_contact.email') }}</p>
                    </div>
                </div>
            </section>

            <section class="space-y-2">
                <h3 :class="sectionTitle">Ansprechpartner Ziel</h3>
                <p :class="hint">Wer nimmt das Fahrzeug am Zielort entgegen?</p>
                <div class="grid grid-cols-3 gap-3">
                    <Input v-model="form.destination_contact.name" placeholder="Name" />
                    <Input v-model="form.destination_contact.phone" placeholder="Telefon" />
                    <div>
                        <Input v-model="form.destination_contact.email" type="email" placeholder="E-Mail" />
                        <p v-if="error('destination_contact.email')" :class="errorClass">{{ error('destination_contact.email') }}</p>
                    </div>
                </div>
            </section>

            <!-- Rechnungsadresse -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Rechnungsadresse <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <SelectField
                    v-if="addressProfiles.length"
                    v-model="chosenProfile.billing_address"
                    :options="profileOptions"
                    placeholder="Gespeicherte Adresse wählen …"
                    @update:model-value="(id) => onProfileSelected('billing_address', id)"
                />
                <div class="grid grid-cols-2 gap-3">
                    <Input v-model="form.billing_address.name" placeholder="Name der Rechnungsadresse, z. B. Hauptverwaltung" class="col-span-2" />
                    <div>
                        <Input v-model="form.billing_address.street" placeholder="Straße" />
                        <p v-if="error('billing_address.street')" :class="errorClass">{{ error('billing_address.street') }}</p>
                    </div>
                    <Input v-model="form.billing_address.number" placeholder="Hausnummer" />
                    <div>
                        <Input v-model="form.billing_address.zip_code" placeholder="PLZ" />
                        <p v-if="error('billing_address.zip_code')" :class="errorClass">{{ error('billing_address.zip_code') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.billing_address.city" placeholder="Ort" />
                        <p v-if="error('billing_address.city')" :class="errorClass">{{ error('billing_address.city') }}</p>
                    </div>
                </div>
                <p v-if="!billingComplete" :class="errorClass">
                    Bitte Straße, PLZ und Ort der Rechnungsadresse vervollständigen oder alle Felder leeren.
                </p>
            </section>

            <!-- Kostenstelle -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Kostenstelle <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <SelectField
                    v-if="costCentres.length"
                    v-model="chosenCostCentre"
                    :options="costCentreOptions"
                    placeholder="Gespeicherte Kostenstelle wählen …"
                    @update:model-value="onCostCentreSelected"
                />
                <div class="grid grid-cols-2 gap-3">
                    <Input v-model="form.cost_centre.name" placeholder="Kostenstelle Name, z. B. Vertrieb" />
                    <Input v-model="form.cost_centre.number" placeholder="Kostenstelle Nummer, z. B. 12345" />
                </div>
            </section>

            <!-- Weitere Angaben -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Weitere Angaben</h3>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.vehicle_ready" type="checkbox" />
                    Fahrzeug ist fahrbereit
                </label>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    placeholder="Zusätzliche Informationen zur Überführung …"
                    class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                />
                <p v-if="error('notes')" :class="errorClass">{{ error('notes') }}</p>
            </section>

            <!-- Says why the button is still grey instead of leaving the user to guess. -->
            <p v-if="missingFields.length" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Noch auszufüllen: {{ missingFields.join(', ') }}
            </p>
        </form>

        <template #footer>
            <AppModalButton type="button" :disabled="!canSubmit || form.processing" @click="submit">
                {{ submitLabel }}
            </AppModalButton>
        </template>
    </AppModal>
</template>
