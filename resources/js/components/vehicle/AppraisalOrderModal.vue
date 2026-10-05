<script setup lang="ts">
import CalendarDateField from '@/components/form/CalendarDateField.vue';
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import { formatPortalDate } from '@/lib/portalDate';
import type { BookableVehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * The Vehicle Condition Appraisal Order Form (brief of 17 September 2026).
 *
 * One order for every selected vehicle, with the same location, logistics,
 * leasing company and appointment for all of them. Billing is mandatory and
 * the company's default billing address is preselected; a new address or
 * cost centre is only saved when the customer ticks "speichern". Return
 * transport can only be requested together with pickup. The appointment is a
 * date (not in the past) and a time window of at least two hours.
 *
 * After submitting, the server sends the customer to the confirmation page.
 */
interface AddressDetails {
    street?: string | null;
    number?: string | null;
    zip_code?: string | null;
    city?: string | null;
    country?: string | null;
}

interface SavedAddressProfile {
    id: string;
    profile_name: string;
    details: AddressDetails | null;
}

interface SavedBillingAddress {
    id: string;
    name: string;
    details: AddressDetails | null;
    is_default: boolean;
}

interface SavedCostCentre {
    id: string;
    name: string;
    number: string | null;
}

type AppraisalVehicle = BookableVehicleData & { leasinggeber?: string | null };

const props = withDefaults(
    defineProps<{
        open: boolean;
        vehicles: AppraisalVehicle[];
        addressProfiles?: SavedAddressProfile[];
        billingAddresses?: SavedBillingAddress[];
        savedCostCentres?: SavedCostCentre[];
        costCentres?: string[];
    }>(),
    { addressProfiles: () => [], billingAddresses: () => [], savedCostCentres: () => [], costCentres: () => [] },
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
const startTimeOptions: SelectFieldOption[] = TIMES.filter((time) => toMinutes(time) + MIN_WINDOW_MINUTES <= lastTime).map((time) => ({
    label: time,
    value: time,
}));

const emptyAddress = () => ({ street: '', number: '', zip_code: '', city: '', country: 'Deutschland' });

const form = useForm({
    billing_address: { name: '', ...emptyAddress() },
    save_billing_address: false,
    billing_address_default: false,
    cost_centre: { name: '', number: '' },
    save_cost_centre: false,
    vehicle_location: emptyAddress(),
    pickup_requested: false,
    return_transport: false,
    leasing_company: '',
    preferred_date: '',
    time_from: '',
    time_to: '',
    location_contact: { name: '', phone: '', email: '' },
    notes: '',
});

const endTimeOptions = computed<SelectFieldOption[]>(() =>
    form.time_from
        ? TIMES.filter((time) => toMinutes(time) >= toMinutes(form.time_from) + MIN_WINDOW_MINUTES).map((time) => ({ label: time, value: time }))
        : [],
);

// A start change can make the chosen end too early — clear it rather than send an invalid window.
watch(
    () => form.time_from,
    () => {
        if (form.time_to && !endTimeOptions.value.some((option) => option.value === form.time_to)) {
            form.time_to = '';
        }
    },
);

// Return transport only exists together with pickup (brief).
watch(
    () => form.pickup_requested,
    (pickup) => {
        if (!pickup) {
            form.return_transport = false;
        }
    },
);

/* ── Saved data ── */
const chosenBilling = ref('');
const chosenLocation = ref('');
const chosenCostCentre = ref('');

const billingOptions = computed<SelectFieldOption[]>(() =>
    props.billingAddresses.map((address) => ({ label: address.is_default ? `${address.name} (Standard)` : address.name, value: address.id })),
);

const profileOptions = computed<SelectFieldOption[]>(() => props.addressProfiles.map((profile) => ({ label: profile.profile_name, value: profile.id })));

const costCentreOptions = computed<SelectFieldOption[]>(() => {
    const saved = props.savedCostCentres.map((centre) => ({
        label: centre.number ? `${centre.name} (${centre.number})` : centre.name,
        value: `saved:${centre.id}`,
    }));
    const savedNames = new Set(props.savedCostCentres.map((centre) => centre.name));
    const fromVehicles = props.costCentres.filter((name) => !savedNames.has(name)).map((name) => ({ label: name, value: `name:${name}` }));

    return [...saved, ...fromVehicles];
});

function copyAddress(target: { street: string; number: string; zip_code: string; city: string; country: string }, details: AddressDetails | null) {
    if (!details) {
        return;
    }

    Object.assign(target, {
        street: details.street ?? '',
        number: details.number ?? '',
        zip_code: details.zip_code ?? '',
        city: details.city ?? '',
        country: details.country || 'Deutschland',
    });
}

function applyBilling(id: string) {
    const address = props.billingAddresses.find((candidate) => candidate.id === id);

    if (!address) {
        return;
    }

    form.billing_address.name = address.name;
    copyAddress(form.billing_address, address.details);
    // A saved address is used as it is; saving it again would duplicate it.
    form.save_billing_address = false;
}

function applyLocation(id: string) {
    copyAddress(form.vehicle_location, props.addressProfiles.find((profile) => profile.id === id)?.details ?? null);
}

function applyCostCentre(value: string) {
    if (value.startsWith('saved:')) {
        const centre = props.savedCostCentres.find((candidate) => candidate.id === value.slice(6));

        if (centre) {
            form.cost_centre.name = centre.name;
            form.cost_centre.number = centre.number ?? '';
            form.save_cost_centre = false;
        }

        return;
    }

    if (value.startsWith('name:')) {
        form.cost_centre.name = value.slice(5);
    }
}

/** The leasing company every selected vehicle shares, if they share one. */
const sharedLeasingCompany = computed(() => {
    const names = [...new Set(props.vehicles.map((vehicle) => (vehicle.leasinggeber ?? '').trim()).filter(Boolean))];

    return names.length === 1 ? names[0] : '';
});

// `immediate`: the dashboard mounts this modal already open.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        chosenLocation.value = '';
        chosenCostCentre.value = '';
        form.leasing_company = sharedLeasingCompany.value;

        // The company's default billing address is preselected (brief).
        const defaultBilling = props.billingAddresses.find((address) => address.is_default) ?? null;
        chosenBilling.value = defaultBilling?.id ?? '';

        if (defaultBilling) {
            applyBilling(defaultBilling.id);
        }
    },
    { immediate: true },
);

/* ── Validation (mirrors the server) ── */
const filled = (value: string) => value.trim() !== '';
const PHONE = /^\+?[0-9 ()/.-]{5,30}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function todayIso(): string {
    const date = new Date();

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** What still blocks the submit button, in the order the form asks for it. */
const missingFields = computed(() =>
    [
        props.vehicles.length === 0 ? 'Fahrzeug' : '',
        filled(form.billing_address.name) ? '' : 'Rechnungsadresse: Name',
        filled(form.billing_address.street) ? '' : 'Rechnungsadresse: Straße',
        filled(form.billing_address.zip_code) ? '' : 'Rechnungsadresse: PLZ',
        filled(form.billing_address.city) ? '' : 'Rechnungsadresse: Ort',
        filled(form.billing_address.country) ? '' : 'Rechnungsadresse: Land',
        filled(form.vehicle_location.street) ? '' : 'Fahrzeugstandort: Straße',
        filled(form.vehicle_location.zip_code) ? '' : 'Fahrzeugstandort: PLZ',
        filled(form.vehicle_location.city) ? '' : 'Fahrzeugstandort: Ort',
        filled(form.leasing_company) ? '' : 'Leasinggeber',
        !filled(form.preferred_date) ? 'Wunschtermin: Datum' : form.preferred_date < todayIso() ? 'Wunschtermin liegt in der Vergangenheit' : '',
        filled(form.time_from) ? '' : 'Zeitfenster von',
        filled(form.time_to) ? '' : 'Zeitfenster bis',
        filled(form.location_contact.phone) && !PHONE.test(form.location_contact.phone.trim()) ? 'Ansprechpartner: Telefonnummer ungültig' : '',
        filled(form.location_contact.email) && !EMAIL.test(form.location_contact.email.trim()) ? 'Ansprechpartner: E-Mail ungültig' : '',
    ].filter(Boolean),
);

const canSubmit = computed(() => missingFields.value.length === 0);

/** Nested validation errors arrive as "billing_address.street" etc. */
function error(key: string): string | undefined {
    return (form.errors as Record<string, string>)[key];
}

function close() {
    emit('update:open', false);
}

function submit() {
    // `processing` keeps a double click from sending the order twice; the
    // server refuses a second order for the same vehicles anyway.
    if (!canSubmit.value || form.processing) {
        return;
    }

    const anyFilled = (block: Record<string, string>) => Object.values(block).some((value) => filled(value));

    form.transform((data) => ({
        ...data,
        vehicle_ids: props.vehicles.map((vehicle) => vehicle.vehicle_id),
        return_transport: data.pickup_requested && data.return_transport,
        cost_centre: anyFilled(data.cost_centre) ? data.cost_centre : null,
        location_contact: anyFilled(data.location_contact) ? data.location_contact : null,
    })).post('/orders/b2b/appraisal', {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

const sectionTitle = 'text-[15px] font-semibold text-black';
const hint = 'text-muted-foreground text-xs';
const errorClass = 'mt-1 text-xs text-red-600';
const labelClass = 'mb-1 block text-xs font-medium';
</script>

<template>
    <AppModal
        :open="open"
        title="Gutachten beauftragen"
        description="Fahrzeugzustand dokumentieren lassen — vor Ort oder bei einer DEKRA/TÜV-Prüfstelle."
        :width="760"
        @update:open="(value) => emit('update:open', value)"
    >
        <form class="max-h-[70vh] space-y-6 overflow-y-auto px-2 pb-2" @submit.prevent="submit">
            <!-- Fahrzeuge -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">{{ vehicles.length > 1 ? `Fahrzeuge (${vehicles.length})` : 'Fahrzeug' }}</h3>
                <ul class="border-border divide-border divide-y rounded-xl border">
                    <li v-for="vehicle in vehicles" :key="vehicle.vehicle_id" class="px-4 py-2.5 text-sm">
                        <span class="text-brand-teal font-semibold">{{ vehicle.license_plate }}</span>
                        <span class="text-muted-foreground"> · {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}</span>
                        <span class="text-muted-foreground block text-xs">
                            <template v-if="vehicle.vin">FIN {{ vehicle.vin }}</template>
                            <template v-if="vehicle.leasing_end_date"> · Rückgabe {{ formatPortalDate(vehicle.leasing_end_date) }}</template>
                        </span>
                    </li>
                </ul>
                <p v-if="error('vehicle_ids')" :class="errorClass">{{ error('vehicle_ids') }}</p>
            </section>

            <!-- Rechnungsadresse (Pflicht) -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Rechnungsadresse *</h3>
                <SelectField
                    v-if="billingAddresses.length"
                    v-model="chosenBilling"
                    :options="billingOptions"
                    placeholder="Gespeicherte Rechnungsadresse wählen …"
                    @update:model-value="applyBilling"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label :class="labelClass">Name der Rechnungsadresse *</label>
                        <Input v-model="form.billing_address.name" placeholder="z. B. Hauptverwaltung, Filiale München" />
                        <p v-if="error('billing_address.name')" :class="errorClass">{{ error('billing_address.name') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.billing_address.street" placeholder="Straße *" />
                        <p v-if="error('billing_address.street')" :class="errorClass">{{ error('billing_address.street') }}</p>
                    </div>
                    <Input v-model="form.billing_address.number" placeholder="Hausnummer" />
                    <div>
                        <Input v-model="form.billing_address.zip_code" placeholder="PLZ *" />
                        <p v-if="error('billing_address.zip_code')" :class="errorClass">{{ error('billing_address.zip_code') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.billing_address.city" placeholder="Ort *" />
                        <p v-if="error('billing_address.city')" :class="errorClass">{{ error('billing_address.city') }}</p>
                    </div>
                    <div class="col-span-2">
                        <Input v-model="form.billing_address.country" placeholder="Land *" />
                        <p v-if="error('billing_address.country')" :class="errorClass">{{ error('billing_address.country') }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.save_billing_address" type="checkbox" />
                    Diese Rechnungsadresse im Unternehmenskonto speichern
                </label>
                <label v-if="form.save_billing_address" class="ml-6 flex items-center gap-2 text-sm">
                    <input v-model="form.billing_address_default" type="checkbox" />
                    Als Standard-Rechnungsadresse festlegen
                </label>
            </section>

            <!-- Kostenstelle -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Kostenstelle <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <SelectField
                    v-if="costCentreOptions.length"
                    v-model="chosenCostCentre"
                    :options="costCentreOptions"
                    placeholder="Gespeicherte Kostenstelle wählen …"
                    @update:model-value="applyCostCentre"
                />
                <div class="grid grid-cols-2 gap-3">
                    <Input v-model="form.cost_centre.name" placeholder="Kostenstelle Name, z. B. Vertrieb" />
                    <Input v-model="form.cost_centre.number" placeholder="Kostenstelle Nummer" />
                </div>
                <label v-if="filled(form.cost_centre.name)" class="flex items-center gap-2 text-sm">
                    <input v-model="form.save_cost_centre" type="checkbox" />
                    Kostenstelle im Unternehmenskonto speichern
                </label>
            </section>

            <!-- Fahrzeugstandort -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Fahrzeugstandort *</h3>
                <p :class="hint">Wo befindet sich das Fahrzeug aktuell? Unser Gutachter kommt zu diesem Standort oder wir holen das Fahrzeug von dort ab.</p>
                <SelectField
                    v-if="addressProfiles.length"
                    v-model="chosenLocation"
                    :options="profileOptions"
                    placeholder="Gespeicherte Adresse wählen …"
                    @update:model-value="applyLocation"
                />
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Input v-model="form.vehicle_location.street" placeholder="Straße *" />
                        <p v-if="error('vehicle_location.street')" :class="errorClass">{{ error('vehicle_location.street') }}</p>
                    </div>
                    <Input v-model="form.vehicle_location.number" placeholder="Hausnummer" />
                    <div>
                        <Input v-model="form.vehicle_location.zip_code" placeholder="PLZ *" />
                        <p v-if="error('vehicle_location.zip_code')" :class="errorClass">{{ error('vehicle_location.zip_code') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.vehicle_location.city" placeholder="Ort *" />
                        <p v-if="error('vehicle_location.city')" :class="errorClass">{{ error('vehicle_location.city') }}</p>
                    </div>
                </div>
            </section>

            <!-- Abholung & Rückführung -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Abholung des Fahrzeugs <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <p :class="hint">Wir schlagen Ihnen die nächstgelegene Prüfstelle (DEKRA/TÜV) vor und koordinieren alles für Sie.</p>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.pickup_requested" type="checkbox" />
                    Fahrzeug soll abgeholt und zur Prüfstelle gebracht werden
                </label>

                <h3 :class="sectionTitle" class="pt-2">Rückführung nach Gutachten <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <label class="flex items-center gap-2 text-sm" :class="form.pickup_requested ? '' : 'opacity-50'">
                    <input v-model="form.return_transport" type="checkbox" :disabled="!form.pickup_requested" />
                    Fahrzeug soll nach dem Gutachten zurückgeführt werden
                </label>
                <p v-if="!form.pickup_requested" :class="hint">Eine Rückführung ist nur zusammen mit der Abholung möglich.</p>
                <p v-if="error('return_transport')" :class="errorClass">{{ error('return_transport') }}</p>
            </section>

            <!-- Leasinggeber -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Leasinggeber *</h3>
                <Input v-model="form.leasing_company" placeholder="z. B. VW Leasing, Mercedes Bank" />
                <p :class="hint">Der Leasinggeber wird über den Zustand des Fahrzeugs informiert und erhält eine Kopie des Gutachtens.</p>
                <p v-if="error('leasing_company')" :class="errorClass">{{ error('leasing_company') }}</p>
            </section>

            <!-- Wunschtermin -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Wunschtermin *</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label :class="labelClass">Datum *</label>
                        <CalendarDateField v-model="form.preferred_date" />
                        <p v-if="error('preferred_date')" :class="errorClass">{{ error('preferred_date') }}</p>
                    </div>
                    <div>
                        <label :class="labelClass">Zeitfenster von *</label>
                        <SelectField v-model="form.time_from" :options="startTimeOptions" placeholder="Startzeit wählen" />
                        <p v-if="error('time_from')" :class="errorClass">{{ error('time_from') }}</p>
                    </div>
                    <div>
                        <label :class="labelClass">Zeitfenster bis *</label>
                        <SelectField v-model="form.time_to" :options="endTimeOptions" placeholder="Endzeit wählen" :disabled="!form.time_from" />
                        <p v-if="error('time_to')" :class="errorClass">{{ error('time_to') }}</p>
                    </div>
                </div>
                <p :class="hint">
                    Mindestens 2 Stunden nach Startzeit. Wir bestätigen die Verfügbarkeit und koordinieren den Termin mit der Prüfstelle.
                </p>
            </section>

            <!-- Ansprechpartner -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Ansprechpartner am Fahrzeugstandort <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <p :class="hint">Wer ist am Fahrzeugstandort erreichbar und kann Zugang zum Fahrzeug ermöglichen?</p>
                <div class="grid grid-cols-3 gap-3">
                    <Input v-model="form.location_contact.name" placeholder="Name" />
                    <div>
                        <Input v-model="form.location_contact.phone" placeholder="Telefon" />
                        <p v-if="error('location_contact.phone')" :class="errorClass">{{ error('location_contact.phone') }}</p>
                    </div>
                    <div>
                        <Input v-model="form.location_contact.email" type="email" placeholder="E-Mail" />
                        <p v-if="error('location_contact.email')" :class="errorClass">{{ error('location_contact.email') }}</p>
                    </div>
                </div>
            </section>

            <!-- Weitere Hinweise -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Weitere Hinweise <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <textarea
                    v-model="form.notes"
                    rows="4"
                    placeholder="Zusätzliche Informationen zum Gutachten, z. B. Zugangshinweise …"
                    class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                />
                <p v-if="error('notes')" :class="errorClass">{{ error('notes') }}</p>
            </section>

            <!-- Says why the button is still grey instead of leaving the user to guess. -->
            <p v-if="missingFields.length" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Noch auszufüllen oder zu korrigieren: {{ missingFields.join(', ') }}
            </p>
        </form>

        <template #footer>
            <AppModalButton type="button" :disabled="!canSubmit || form.processing" @click="submit">
                {{ form.processing ? 'Wird gesendet …' : 'Gutachtenauftrag erstellen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>
