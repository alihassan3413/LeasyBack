<script setup lang="ts">
import SelectField, { type SelectFieldOption } from '@/components/form/SelectField.vue';
import { Input } from '@/components/ui/input';
import { AppModal, AppModalButton } from '@/components/ui/modal';
import type { BookableVehicleData } from '@/types/vehicle';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MdiClose from '~icons/mdi/close';
import MdiTrayArrowUp from '~icons/mdi/tray-arrow-up';

/**
 * The Unfallschaden form (Accident Damage brief, 17 September 2026).
 *
 * Exactly one vehicle. Billing information is mandatory — the company's
 * default billing address is preselected, another saved one or a new one can
 * be used, and a new one is only saved to the company account when the
 * customer ticks "speichern". The vehicle location is mandatory; a different
 * return location makes its address mandatory. Files are optional, several
 * at once, by click or drag and drop, 20 MB each at most.
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

const props = withDefaults(
    defineProps<{
        open: boolean;
        vehicle: BookableVehicleData;
        addressProfiles?: SavedAddressProfile[];
        billingAddresses?: SavedBillingAddress[];
        savedCostCentres?: SavedCostCentre[];
        costCentres?: string[];
    }>(),
    { addressProfiles: () => [], billingAddresses: () => [], savedCostCentres: () => [], costCentres: () => [] },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const MAX_FILE_BYTES = 20 * 1024 * 1024;
const MAX_FILES = 10;

const emptyAddress = () => ({ street: '', number: '', zip_code: '', city: '', country: 'Deutschland' });
const emptyContact = () => ({ name: '', phone: '', email: '' });

const form = useForm({
    vehicle_id: '',
    billing_address: { name: '', ...emptyAddress() },
    save_billing_address: false,
    billing_address_default: false,
    cost_centre: { name: '', number: '' },
    save_cost_centre: false,
    vehicle_location: emptyAddress(),
    location_contact: emptyContact(),
    return_differs: false,
    return_address: emptyAddress(),
    return_contact: emptyContact(),
    notes: '',
    files: [] as File[],
});

/* ── Saved data ── */
const chosenBilling = ref('');
const chosenLocation = ref('');
const chosenReturn = ref('');
const chosenCostCentre = ref('');

const billingOptions = computed<SelectFieldOption[]>(() =>
    props.billingAddresses.map((address) => ({
        label: address.is_default ? `${address.name} (Standard)` : address.name,
        value: address.id,
    })),
);

const profileOptions = computed<SelectFieldOption[]>(() =>
    props.addressProfiles.map((profile) => ({ label: profile.profile_name, value: profile.id })),
);

/** Saved company cost centres first (name + number), then names known from vehicles. */
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

function applyProfile(target: 'vehicle_location' | 'return_address', id: string) {
    copyAddress(form[target], props.addressProfiles.find((profile) => profile.id === id)?.details ?? null);
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

/*
 * Declared before the open-watcher below: with `immediate: true` it runs
 * during setup and resets `rejectedFiles`, which must already exist then.
 */
const fileInput = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const rejectedFiles = ref<string[]>([]);

// `immediate`: the dashboard mounts this modal already open.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();
        rejectedFiles.value = [];
        chosenLocation.value = '';
        chosenReturn.value = '';
        chosenCostCentre.value = '';
        form.vehicle_id = props.vehicle.vehicle_id;

        // The company's default billing address is preselected (brief).
        const defaultBilling = props.billingAddresses.find((address) => address.is_default) ?? null;
        chosenBilling.value = defaultBilling?.id ?? '';

        if (defaultBilling) {
            applyBilling(defaultBilling.id);
        }
    },
    { immediate: true },
);

/* ── Files ── (fileInput, dragging and rejectedFiles are declared above the open-watcher) */

function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function addFiles(list: FileList | null) {
    if (!list) {
        return;
    }

    const rejected: string[] = [];

    for (const file of Array.from(list)) {
        if (file.size > MAX_FILE_BYTES) {
            rejected.push(`${file.name} ist größer als 20 MB und wurde nicht übernommen.`);
            continue;
        }

        if (form.files.length >= MAX_FILES) {
            rejected.push(`Höchstens ${MAX_FILES} Dateien — ${file.name} wurde nicht übernommen.`);
            continue;
        }

        if (!form.files.some((existing) => existing.name === file.name && existing.size === file.size)) {
            form.files.push(file);
        }
    }

    rejectedFiles.value = rejected;
}

function onFileInput(event: Event) {
    const input = event.target as HTMLInputElement;
    addFiles(input.files);
    // Lets the same file be chosen again after removing it.
    input.value = '';
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? null);
}

function removeFile(index: number) {
    form.files.splice(index, 1);
}

/* ── Validation (mirrors the server) ── */
const filled = (value: string) => value.trim() !== '';
const PHONE = /^\+?[0-9 ()/.-]{5,30}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function contactProblems(contact: { phone: string; email: string }, label: string): string[] {
    return [
        filled(contact.phone) && !PHONE.test(contact.phone.trim()) ? `${label}: Telefonnummer ungültig` : '',
        filled(contact.email) && !EMAIL.test(contact.email.trim()) ? `${label}: E-Mail ungültig` : '',
    ].filter(Boolean);
}

/** What still blocks the submit button, in the order the form asks for it. */
const missingFields = computed(() => [
    ...[
        filled(form.billing_address.name) ? '' : 'Rechnungsadresse: Name',
        filled(form.billing_address.street) ? '' : 'Rechnungsadresse: Straße',
        filled(form.billing_address.zip_code) ? '' : 'Rechnungsadresse: PLZ',
        filled(form.billing_address.city) ? '' : 'Rechnungsadresse: Ort',
        filled(form.billing_address.country) ? '' : 'Rechnungsadresse: Land',
        filled(form.vehicle_location.street) ? '' : 'Fahrzeugstandort: Straße',
        filled(form.vehicle_location.zip_code) ? '' : 'Fahrzeugstandort: PLZ',
        filled(form.vehicle_location.city) ? '' : 'Fahrzeugstandort: Ort',
        !form.return_differs || filled(form.return_address.street) ? '' : 'Rückführadresse: Straße',
        !form.return_differs || filled(form.return_address.zip_code) ? '' : 'Rückführadresse: PLZ',
        !form.return_differs || filled(form.return_address.city) ? '' : 'Rückführadresse: Ort',
    ].filter(Boolean),
    ...contactProblems(form.location_contact, 'Ansprechpartner Fahrzeugstandort'),
    ...(form.return_differs ? contactProblems(form.return_contact, 'Ansprechpartner Rückführort') : []),
]);

const canSubmit = computed(() => missingFields.value.length === 0);

/** Nested validation errors arrive as "billing_address.street", "files.0" etc. */
function error(key: string): string | undefined {
    return (form.errors as Record<string, string>)[key];
}

const fileErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'files' || key.startsWith('files.'))
        .map(([, message]) => message),
);

function close() {
    emit('update:open', false);
}

function submit() {
    // `processing` keeps a double click from sending the report twice; the
    // server refuses a second order for the same vehicle anyway.
    if (!canSubmit.value || form.processing) {
        return;
    }

    const anyFilled = (block: Record<string, string>) => Object.values(block).some((value) => filled(value));

    form.transform((data) => ({
        ...data,
        vehicle_id: props.vehicle.vehicle_id,
        cost_centre: anyFilled(data.cost_centre) ? data.cost_centre : null,
        location_contact: anyFilled(data.location_contact) ? data.location_contact : null,
        return_address: data.return_differs ? data.return_address : null,
        return_contact: data.return_differs && anyFilled(data.return_contact) ? data.return_contact : null,
    })).post('/orders/b2b/accident-damage', {
        forceFormData: true,
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
        title="Unfallschadenmeldung"
        description="Unfallschaden dokumentieren und melden."
        :width="760"
        @update:open="(value) => emit('update:open', value)"
    >
        <form class="max-h-[70vh] space-y-6 overflow-y-auto px-2 pb-2" @submit.prevent="submit">
            <!-- Fahrzeug -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Fahrzeug</h3>
                <div class="border-border rounded-xl border px-4 py-3 text-sm">
                    <span class="text-brand-teal font-semibold">{{ vehicle.license_plate }}</span>
                    <span class="text-muted-foreground"> · {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') || '—' }}</span>
                    <span v-if="vehicle.vin" class="text-muted-foreground block text-xs">FIN {{ vehicle.vin }}</span>
                </div>
                <p v-if="error('vehicle_id')" :class="errorClass">{{ error('vehicle_id') }}</p>
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
                <p :class="hint">Wo befindet sich das Fahrzeug? (Gutachten wird vor Ort gemacht oder Fahrzeug wird von dort abgeholt)</p>
                <SelectField
                    v-if="addressProfiles.length"
                    v-model="chosenLocation"
                    :options="profileOptions"
                    placeholder="Gespeicherte Adresse wählen …"
                    @update:model-value="(id) => applyProfile('vehicle_location', id)"
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

            <!-- Ansprechpartner am Fahrzeugstandort -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Ansprechpartner am Fahrzeugstandort <span class="text-muted-foreground font-normal">(optional)</span></h3>
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

            <!-- Rückführung -->
            <section class="space-y-2">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input v-model="form.return_differs" type="checkbox" />
                    Rückführadresse abweichend vom Fahrzeugstandort
                </label>

                <template v-if="form.return_differs">
                    <h3 :class="sectionTitle">Abweichende Rückführadresse *</h3>
                    <SelectField
                        v-if="addressProfiles.length"
                        v-model="chosenReturn"
                        :options="profileOptions"
                        placeholder="Gespeicherte Adresse wählen …"
                        @update:model-value="(id) => applyProfile('return_address', id)"
                    />
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Input v-model="form.return_address.street" placeholder="Straße *" />
                            <p v-if="error('return_address.street')" :class="errorClass">{{ error('return_address.street') }}</p>
                        </div>
                        <Input v-model="form.return_address.number" placeholder="Hausnummer" />
                        <div>
                            <Input v-model="form.return_address.zip_code" placeholder="PLZ *" />
                            <p v-if="error('return_address.zip_code')" :class="errorClass">{{ error('return_address.zip_code') }}</p>
                        </div>
                        <div>
                            <Input v-model="form.return_address.city" placeholder="Ort *" />
                            <p v-if="error('return_address.city')" :class="errorClass">{{ error('return_address.city') }}</p>
                        </div>
                    </div>

                    <h3 :class="sectionTitle">Ansprechpartner Rückführort <span class="text-muted-foreground font-normal">(optional)</span></h3>
                    <div class="grid grid-cols-3 gap-3">
                        <Input v-model="form.return_contact.name" placeholder="Name" />
                        <div>
                            <Input v-model="form.return_contact.phone" placeholder="Telefon" />
                            <p v-if="error('return_contact.phone')" :class="errorClass">{{ error('return_contact.phone') }}</p>
                        </div>
                        <div>
                            <Input v-model="form.return_contact.email" type="email" placeholder="E-Mail" />
                            <p v-if="error('return_contact.email')" :class="errorClass">{{ error('return_contact.email') }}</p>
                        </div>
                    </div>
                </template>
            </section>

            <!-- Weitere Hinweise -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Weitere Hinweise <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <textarea
                    v-model="form.notes"
                    rows="4"
                    placeholder="Zusätzliche Informationen zum Unfallschaden …"
                    class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                />
                <p v-if="error('notes')" :class="errorClass">{{ error('notes') }}</p>
            </section>

            <!-- Dateien -->
            <section class="space-y-2">
                <h3 :class="sectionTitle">Dateien hochladen <span class="text-muted-foreground font-normal">(optional)</span></h3>
                <button
                    type="button"
                    class="flex w-full flex-col items-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors"
                    :class="dragging ? 'border-brand-green bg-brand-green/[0.06]' : 'border-border hover:border-brand-green'"
                    @click="fileInput?.click()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <MdiTrayArrowUp class="text-muted-foreground size-6" />
                    <span class="text-sm">Klicken Sie hier oder ziehen Sie Dateien hierher</span>
                    <span :class="hint">PDF, Bilder oder Word · max. 20 MB pro Datei · höchstens {{ MAX_FILES }} Dateien</span>
                </button>
                 <input
                    ref="fileInput"
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx"
                    class="hidden"
                    @change="onFileInput"
                />

                <ul v-if="form.files.length" class="space-y-1">
                    <li
                        v-for="(file, index) in form.files"
                        :key="`${file.name}-${file.size}`"
                        class="border-border flex items-center justify-between gap-2 rounded-md border px-3 py-1.5 text-sm"
                    >
                        <span class="min-w-0 truncate">{{ file.name }}</span>
                        <span class="text-muted-foreground flex shrink-0 items-center gap-2 text-xs">
                            {{ formatSize(file.size) }}
                            <button type="button" class="hover:text-red-600" :aria-label="`${file.name} entfernen`" @click="removeFile(index)">
                                <MdiClose class="size-4" />
                            </button>
                        </span>
                    </li>
                </ul>

                <p v-for="message in rejectedFiles" :key="message" :class="errorClass">{{ message }}</p>
                <p v-for="message in fileErrors" :key="message" :class="errorClass">{{ message }}</p>
            </section>

            <!-- Says why the button is still grey instead of leaving the user to guess. -->
            <p v-if="missingFields.length" class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Noch auszufüllen oder zu korrigieren: {{ missingFields.join(', ') }}
            </p>
        </form>

        <template #footer>
            <AppModalButton type="button" :disabled="!canSubmit || form.processing" @click="submit">
                {{ form.processing ? 'Wird gesendet …' : 'Auftrag erstellen' }}
            </AppModalButton>
        </template>
    </AppModal>
</template>